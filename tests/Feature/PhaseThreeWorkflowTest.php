<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Services\DeadlineCalculator;
use App\Services\DocumentCompleteness;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\JournalFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class PhaseThreeWorkflowTest extends TestCase
{
    use JournalFixtures;
    use RefreshDatabase;
    use SuperAdminFixtures;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->client = Client::firstOrFail();
        $this->client->update(['assigned_to' => $this->bookkeeper->id]);
    }

    public function test_deadline_filters_count_all_matches_before_pagination(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Manila'));
        foreach (range(1, 42) as $i) {
            ComplianceRecord::create(['client_id' => $this->client->id, 'agency' => 'Other', 'requirement' => 'Pagination fixture '.$i,
                'due_date' => '2026-10-15', 'submission_deadline' => $i <= 16 ? '2026-10-01' : '2026-10-14', 'status' => $i === 42 ? 'Completed' : 'Pending', 'created_by' => $this->owner->id]);
        }
        foreach ([10, 25, 50] as $size) {
            $ids = [];
            for ($page = 1; $page <= (int) ceil(25 / $size); $page++) {
                $response = $this->actingAs($this->manager)->get('/compliance/monitoring?deadline=submission_approaching&q=Pagination&per_page='.$size.'&page='.$page)->assertOk();
                $records = $response->viewData('records');
                $this->assertSame(25, $records->total());
                $this->assertStringContainsString('deadline=submission_approaching', $records->url(2));
                $ids = array_merge($ids, $records->pluck('id')->all());
            }
            $this->assertCount(25, array_unique($ids));
            $this->assertCount(25, $ids);
        }
        foreach (['filing_approaching', 'filing_overdue', 'submission_overdue'] as $filter) {
            $records = $this->get('/compliance/monitoring?q=Pagination&deadline='.$filter)->assertOk()->viewData('records');
            $this->assertSame(match ($filter) {
                'filing_approaching' => 41, 'filing_overdue' => 0, default => 16
            }, $records->total());
        }
        $this->get('/compliance/monitoring?per_page=100')->assertSessionHasErrors('per_page');
    }

    private function period(Client $client, string $start = '2026-01-01', string $end = '2026-12-31'): AccountingPeriod
    {
        $year = AccountingYear::create(['client_id' => $client->id, 'label' => 'Test year', 'starts_on' => $start, 'ends_on' => $end]);

        return AccountingPeriod::create(['client_id' => $client->id, 'accounting_year_id' => $year->id, 'label' => 'Test period', 'starts_on' => $start, 'ends_on' => $end]);
    }

    public function test_requirement_partial_updates_validate_effective_client_and_linked_dates(): void
    {
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $foreign = $this->period($other);
        $own = $this->period($this->client);
        $requirement = DocumentRequirement::create(['client_id' => $this->client->id, 'name' => 'Boundary evidence', 'type' => 'Receipt', 'created_by' => $this->owner->id]);
        $payload = ['name' => $requirement->name, 'type' => 'Receipt'];
        $this->actingAs($this->manager)->put('/requirements/'.$requirement->id, $payload + ['accounting_period_id' => $foreign->id])->assertSessionHasErrors('accounting_period_id');
        $this->put('/requirements/'.$requirement->id, $payload + ['accounting_period_id' => $own->id])->assertSessionHasNoErrors();
        foreach (['2026-01-01', '2026-12-31'] as $date) {
            $document = Document::create(['client_id' => $this->client->id, 'document_number' => 'BOUNDARY-'.$date, 'title' => 'Boundary', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => $date, 'uploaded_by' => $this->owner->id]);
            $this->post('/requirements/'.$requirement->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
            $this->post('/requirements/'.$requirement->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $requirement->documents()->count());
        $this->put('/requirements/'.$requirement->id, $payload + ['client_id' => (string) $this->client->id, 'accounting_period_id' => (string) $own->id])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, $requirement->documents()->whereKey($document->id)->firstOrFail()->pivot->linked_by);
        $future = $this->period($this->client, '2027-01-01', '2027-12-31');
        $this->put('/requirements/'.$requirement->id, $payload + ['accounting_period_id' => $future->id])->assertSessionHasErrors('document_id');
        $this->put('/requirements/'.$requirement->id, $payload + ['client_id' => $other->id])->assertSessionHasErrors('accounting_period_id');
        $this->assertSame($own->id, $requirement->fresh()->accounting_period_id);
    }

    public function test_accounting_calendar_creation_closure_and_role_isolation(): void
    {
        $dates = ['label' => 'Approved calendar', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'];
        $this->actingAs($this->manager)->post('/clients/'.$this->client->id.'/accounting-years', $dates)->assertSessionHasNoErrors();
        $year = AccountingYear::where('client_id', $this->client->id)->firstOrFail();
        $this->post('/clients/'.$this->client->id.'/accounting-years', $dates)->assertSessionHasErrors('starts_on');
        $this->post('/clients/'.$this->client->id.'/accounting-periods', $dates + ['accounting_year_id' => $year->id])->assertSessionHasNoErrors();
        $period = AccountingPeriod::where('client_id', $this->client->id)->firstOrFail();
        $this->get('/accounting-periods?client_id='.$this->client->id)->assertOk()->assertSee('Open');
        $this->post('/accounting-periods/'.$period->id.'/close')->assertSessionHasErrors('confirmation');
        $this->actingAs($this->bookkeeper)->get('/accounting-periods?client_id='.$this->client->id)->assertOk()->assertDontSee('Create accounting year');
        $this->post('/accounting-periods/'.$period->id.'/close', ['confirmation' => 1])->assertForbidden();
        $this->post('/clients/'.$this->client->id.'/accounting-years', $dates)->assertForbidden();
        $this->actingAs($this->manager)->post('/accounting-periods/'.$period->id.'/close', ['confirmation' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Closed', $period->fresh()->status);
        $this->assertSame($this->manager->id, $period->fresh()->closed_by);
        $this->assertNotNull($period->fresh()->closed_at);
        $this->post('/accounting-periods/'.$period->id.'/close', ['confirmation' => 1])->assertSessionHasErrors('period');
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $other->update(['assigned_to' => $this->owner->id]);
        $this->actingAs($this->bookkeeper)->get('/accounting-periods?client_id='.$other->id)->assertNotFound();
        $this->actingAs($this->superAdmin())->get('/accounting-periods')->assertForbidden();
        $this->post('/clients/'.$this->client->id.'/accounting-years', $dates)->assertForbidden();
    }

    public function test_client_assignments_exclude_technical_accounts_and_preserve_related_records(): void
    {
        $technical = $this->superAdmin();
        $data = ['business_name' => 'Assignment workflow', 'business_type' => 'Corporation', 'registration_status' => 'On file', 'business_license_status' => 'On file', 'status' => 'Active', 'assigned_to' => $technical->id];
        $this->actingAs($this->owner)->post('/clients', $data)->assertSessionHasErrors('assigned_to');
        $data['assigned_to'] = $this->bookkeeper->id;
        $this->post('/clients', $data)->assertSessionHasNoErrors();
        $client = Client::where('business_name', 'Assignment workflow')->firstOrFail();
        $this->actingAs($this->bookkeeper)->get('/clients/'.$client->id)->assertOk();
        $data['assigned_to'] = $this->manager->id;
        $this->actingAs($this->owner)->put('/clients/'.$client->id, $data)->assertSessionHasNoErrors();
        $this->actingAs($this->bookkeeper)->get('/clients/'.$client->id)->assertForbidden();
        $this->actingAs($this->owner)->post('/clients/'.$client->id.'/archive')->assertSessionHasNoErrors();
        $this->assertSame('Archived', $client->fresh()->status);
        $this->post('/clients/'.$client->id.'/archive')->assertSessionHasNoErrors();
        $this->assertSame('Active', $client->fresh()->status);
        $this->assertSame($this->manager->id, $client->fresh()->assigned_to);
    }

    public function test_period_closure_respects_pending_review_and_preserves_posted_books(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper, client: $this->client);
        $this->actingAs($this->bookkeeper)->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        $close = '/accounting-periods/'.$entry->accounting_period_id.'/close';
        $this->actingAs($this->manager)->post($close, ['confirmation' => 1])->assertSessionHasErrors('period');
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasNoErrors();
        $this->post($close, ['confirmation' => 1])->assertSessionHasErrors('period');
        $this->post('/ledger/'.$entry->id.'/post')->assertSessionHasNoErrors();
        $this->post($close, ['confirmation' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Posted', $entry->fresh()->status);
        $this->assertSame(10000, $entry->fresh()->items->sum(fn ($line) => Money::cents($line->debit)));
        $draft = $this->structuredJournal($this->bookkeeper, client: $this->client);
        $this->actingAs($this->bookkeeper)->post('/ledger/'.$draft->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('accounting_period_id');
    }

    public function test_knowledge_draft_edit_publish_search_and_archive_permissions(): void
    {
        $data = ['title' => 'Phase three procedure', 'category' => 'Internal Procedure', 'status' => 'Draft', 'content' => 'Use the approved template', 'tags' => 'template,procedure'];
        $this->actingAs($this->owner)->post('/knowledge', $data)->assertSessionHasNoErrors();
        $article = KnowledgeArticle::where('title', $data['title'])->firstOrFail();
        $this->actingAs($this->bookkeeper)->get('/knowledge/'.$article->id)->assertForbidden();
        $data['status'] = 'Published';
        $data['content'] = 'Updated internal procedure';
        $this->actingAs($this->manager)->put('/knowledge/'.$article->id, $data)->assertSessionHasNoErrors();
        $this->actingAs($this->bookkeeper)->get('/knowledge?q=Phase%20three&type=Internal%20Procedure')->assertOk()->assertSee($article->title);
        $this->get('/knowledge/'.$article->id)->assertOk()->assertSee('Updated internal procedure');
        $data['status'] = 'Archived';
        $this->put('/knowledge/'.$article->id, $data)->assertForbidden();
        $this->actingAs($this->owner)->put('/knowledge/'.$article->id, $data)->assertSessionHasNoErrors();
        $this->actingAs($this->bookkeeper)->get('/knowledge/'.$article->id)->assertForbidden();
        $this->assertDatabaseHas('knowledge_articles', ['id' => $article->id, 'status' => 'Archived', 'content' => 'Updated internal procedure']);
    }

    public function test_manual_deadline_is_revalidated_and_filing_evidence_is_preserved(): void
    {
        $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'Asia/Manila'));
        $record = ComplianceRecord::create(['client_id' => $this->client->id, 'agency' => 'Other', 'requirement' => 'Manual target', 'due_date' => '2026-12-31',
            'submission_deadline' => '2026-12-20', 'submission_deadline_is_manual' => true, 'submission_deadline_override_reason' => 'Agreed target', 'status' => 'Filed', 'filed_date' => '2026-01-01', 'reference_number' => 'HISTORY', 'created_by' => $this->owner->id]);
        $data = ['client_id' => $this->client->id, 'agency' => 'Other', 'requirement' => $record->requirement, 'status' => 'Completed', 'due_date' => '2026-12-01'];
        $this->actingAs($this->owner)->put('/compliance/'.$record->id, $data)->assertSessionHasErrors('submission_deadline');
        $data['due_date'] = '2026-12-31';
        $this->put('/compliance/'.$record->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-01-01', $record->fresh()->filed_date->toDateString());
        $this->assertSame('HISTORY', $record->fresh()->reference_number);
        $this->assertFalse(DeadlineCalculator::isFilingOverdue($record->fresh()));
        $this->get('/compliance?status=Overdue')->assertOk()->assertDontSee('Manual target');
    }

    public function test_document_upload_review_followup_and_duplicate_protection(): void
    {
        Storage::fake('local');
        $requirement = DocumentRequirement::create(['client_id' => $this->client->id, 'name' => 'Workflow receipt', 'type' => 'Receipt', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id]);
        $data = ['client_id' => $this->client->id, 'title' => 'Workflow upload', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => '2026-01-01'];
        $file = fn () => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nSynthetic workflow evidence\n%%EOF");
        $this->actingAs($this->bookkeeper)->post('/documents', $data + ['file' => $file()])->assertSessionHasNoErrors();
        $document = Document::where('title', 'Workflow upload')->firstOrFail();
        $this->post('/documents', $data + ['file' => $file()])->assertSessionHasErrors('file');
        $document->update(['file_sha256' => null]);
        $this->post('/documents', $data + ['file' => $file()])->assertSessionHasErrors('file');
        Storage::disk('local')->assertExists($document->file_path);
        $this->post('/documents/'.$document->id.'/validate', ['status' => 'Approved'])->assertForbidden();
        $this->actingAs($this->manager)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
        $this->assertSame('awaiting', DocumentCompleteness::stateOf($requirement->fresh()));
        foreach (['Needs Clarification' => 'clarification', 'Rejected' => 'incomplete', 'Approved' => 'verified'] as $status => $state) {
            $this->post('/documents/'.$document->id.'/validate', ['status' => $status])->assertSessionHasNoErrors();
            $this->assertSame($state, DocumentCompleteness::stateOf($requirement->fresh()));
        }
        $this->post('/requirements/'.$requirement->id.'/follow-ups', ['status' => 'Resolved', 'remarks' => 'Evidence accepted'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('document_follow_ups', ['requirement_id' => $requirement->id, 'status' => 'Resolved']);
        $this->get('/documents/'.$document->id.'/download')->assertOk();
        Storage::disk('local')->delete($document->file_path);
        $this->get('/documents/'.$document->id.'/download')->assertNotFound();
    }

    public function test_payments_reject_retries_and_reconcile_partial_and_full_balance(): void
    {
        $this->actingAs($this->owner)->post('/billing', ['client_id' => $this->client->id, 'invoice_date' => today()->toDateString(), 'due_date' => today()->addDays(10)->toDateString(), 'tax' => '0', 'items' => [['description' => 'Workflow service', 'quantity' => '1', 'unit_price' => '100']]])->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->post('/billing/'.$invoice->id.'/transition', ['action' => 'issue'])->assertSessionHasNoErrors();
        $pay = ['amount' => '25.00', 'payment_method' => 'Cash', 'payment_date' => today()->toDateString(), 'submission_token' => (string) Str::uuid(), 'reference_number' => 'PHASE3-RECEIPT'];
        $url = '/billing/'.$invoice->id.'/payments';
        $this->post($url, $pay)->assertSessionHasNoErrors();
        $this->post($url, $pay)->assertSessionHasErrors('amount');
        $this->assertSame(7500, $invoice->fresh()->balance_cents);
        $this->assertSame('Partially Paid', $invoice->fresh()->display_status);
        $pay['submission_token'] = (string) Str::uuid();
        $this->post($url, $pay)->assertSessionHasErrors('reference_number');
        unset($pay['reference_number']);
        $legacy = $pay;
        unset($legacy['submission_token']);
        $this->post($url, $legacy)->assertSessionHasErrors('amount');
        $pay['amount'] = '75.01';
        $this->post($url, $pay)->assertSessionHasErrors('amount');
        $pay['amount'] = '75.00';
        $this->post($url, $pay)->assertSessionHasNoErrors();
        $this->assertSame(0, $invoice->fresh()->balance_cents);
        $this->assertSame('Paid', $invoice->fresh()->display_status);
        $this->assertSame(2, $invoice->payments()->count());
    }
}
