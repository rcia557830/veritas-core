<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFollowUp;
use App\Models\DocumentRequirement;
use App\Models\DocumentRequirementTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentCompleteness;
use App\Services\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRequirementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    private User $otherBookkeeper;

    private Client $clientA;

    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();

        $this->clientA = Client::where('assigned_to', $this->bookkeeper->id)->firstOrFail();

        $bookkeeperRole = Role::where('slug', 'bookkeeper')->firstOrFail();
        $this->otherBookkeeper = User::create(['name' => 'Other Bookkeeper', 'email' => 'other@veritascore.local', 'password' => 'password123', 'role_id' => $bookkeeperRole->id, 'status' => 'Active']);
        $this->clientB = Client::create([
            'client_code' => 'CL-OTHER', 'business_name' => 'Other Bookkeeper Client', 'business_type' => 'Partnership',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->otherBookkeeper->id,
        ]);
    }

    private function requirementData(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->clientA->id,
            'name' => 'Annual receipts',
            'type' => 'Receipt',
            'is_required' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    private function makeRequirement(?Client $client = null, array $overrides = []): DocumentRequirement
    {
        $client ??= $this->clientA;

        return DocumentRequirement::create(array_merge([
            'client_id' => $client->id, 'name' => 'Annual receipts', 'type' => 'Receipt',
            'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ], $overrides));
    }

    private function makeDocument(?Client $client = null, string $status = 'Submitted', array $overrides = []): Document
    {
        $client ??= $this->clientA;

        return Document::create(array_merge([
            'client_id' => $client->id, 'document_number' => 'DOC-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => 'Sample document', 'document_type' => 'Receipt', 'status' => $status,
            'received_date' => today(), 'uploaded_by' => $this->bookkeeper->id,
        ], $overrides));
    }

    private function makePeriod(Client $client, string $starts, string $ends): AccountingPeriod
    {
        $year = \App\Models\AccountingYear::create([
            'client_id' => $client->id, 'label' => $starts.' FY', 'starts_on' => $starts, 'ends_on' => $ends,
        ]);

        return AccountingPeriod::create([
            'client_id' => $client->id, 'accounting_year_id' => $year->id, 'label' => $starts,
            'starts_on' => $starts, 'ends_on' => $ends,
        ]);
    }

    public function test_requirement_creation_and_validation(): void
    {
        $this->actingAs($this->manager)->post('/requirements', $this->requirementData())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requirements.checklist', $this->clientA));
        $this->assertDatabaseHas('document_requirements', ['client_id' => $this->clientA->id, 'name' => 'Annual receipts']);

        $this->actingAs($this->manager)->post('/requirements', $this->requirementData(['name' => '']))
            ->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->post('/requirements', $this->requirementData(['type' => 'Not A Type']))
            ->assertSessionHasErrors('type');
        $this->actingAs($this->manager)->post('/requirements', $this->requirementData(['client_id' => 999999]))
            ->assertSessionHasErrors('client_id');
    }

    public function test_requirement_is_client_specific(): void
    {
        $this->makeRequirement();
        $this->actingAs($this->manager)->get(route('requirements.checklist', $this->clientA))->assertOk()->assertSee('Annual receipts');
        $this->actingAs($this->manager)->get(route('requirements.checklist', $this->clientB))->assertOk()->assertDontSee('Annual receipts');
    }

    public function test_requirement_with_no_upload_is_reported_missing(): void
    {
        $this->makeRequirement();
        $stats = DocumentCompleteness::forClient($this->clientA);
        $this->assertSame(1, $stats['missing']);
        $this->assertSame(0, $stats['verified']);

        $this->actingAs($this->manager)->get(route('requirements.checklist', $this->clientA))->assertSee('Not submitted');
    }

    public function test_bookkeeper_cannot_manage_requirements(): void
    {
        $requirement = $this->makeRequirement();
        $this->actingAs($this->bookkeeper)->get('/requirements/create')->assertForbidden();
        $this->actingAs($this->bookkeeper)->post('/requirements', $this->requirementData())->assertForbidden();
        $this->actingAs($this->bookkeeper)->put('/requirements/'.$requirement->id, $this->requirementData())->assertForbidden();
        $this->actingAs($this->bookkeeper)->post('/requirements/'.$requirement->id.'/toggle')->assertForbidden();
        $this->actingAs($this->bookkeeper)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $this->makeDocument()->id])->assertForbidden();
    }

    public function test_link_marks_verified_and_unlink_recalculates(): void
    {
        $requirement = $this->makeRequirement();
        $document = $this->makeDocument(status: 'Approved');

        $this->actingAs($this->manager)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
        $this->assertSame(DocumentCompleteness::STATE_VERIFIED, DocumentCompleteness::stateOf($requirement->fresh(['documents'])));

        $this->actingAs($this->manager)->delete('/requirements/'.$requirement->id.'/documents/'.$document->id)->assertSessionHasNoErrors();
        $this->assertSame(DocumentCompleteness::STATE_NOT_SUBMITTED, DocumentCompleteness::stateOf($requirement->fresh(['documents'])));
    }

    public function test_link_requires_same_client(): void
    {
        $requirement = $this->makeRequirement();
        $foreignDocument = $this->makeDocument($this->clientB);
        $this->actingAs($this->manager)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $foreignDocument->id])
            ->assertSessionHasErrors('document_id');
    }

    public function test_link_requires_matching_accounting_period(): void
    {
        $period = $this->makePeriod($this->clientA, '2024-01-01', '2024-12-31');
        $requirement = $this->makeRequirement(null, ['accounting_period_id' => $period->id]);
        $outside = $this->makeDocument(null, 'Submitted', ['received_date' => today()]);

        $this->actingAs($this->manager)->post('/requirements/'.$requirement->id.'/link', ['document_id' => $outside->id])
            ->assertSessionHasErrors('document_id');

        $insidePeriod = $this->makePeriod($this->clientA, today()->subMonths(1)->toDateString(), today()->addMonths(1)->toDateString());
        $within = $this->makeRequirement(null, ['accounting_period_id' => $insidePeriod->id, 'name' => 'Inside period']);
        $this->actingAs($this->manager)->post('/requirements/'.$within->id.'/link', ['document_id' => $outside->id])->assertSessionHasNoErrors();
    }

    public function test_document_cannot_satisfy_two_requirements(): void
    {
        $first = $this->makeRequirement(null, ['name' => 'First']);
        $second = $this->makeRequirement(null, ['name' => 'Second']);
        $document = $this->makeDocument();

        $this->actingAs($this->manager)->post('/requirements/'.$first->id.'/link', ['document_id' => $document->id])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/requirements/'.$second->id.'/link', ['document_id' => $document->id])
            ->assertSessionHasErrors('document_id');
    }



    public function test_template_initialization_creates_requirement(): void
    {
        $template = DocumentRequirementTemplate::create([
            'name' => 'Quarterly VAT return', 'type' => 'BIR Document', 'description' => 'Quarterly filing evidence',
            'is_required' => true, 'default_due_days' => 30,
        ]);

        $this->actingAs($this->manager)->post('/requirements/templates/'.$template->id.'/apply', ['client_id' => $this->clientA->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requirements.checklist', $this->clientA));

        $requirement = DocumentRequirement::where('client_id', $this->clientA->id)->where('template_id', $template->id)->firstOrFail();
        $this->assertSame('Quarterly VAT return', $requirement->name);
        $this->assertNotNull($requirement->due_date);
    }

    public function test_cross_client_access_is_blocked(): void
    {
        $requirement = $this->makeRequirement($this->clientB);

        $this->actingAs($this->bookkeeper)->get(route('requirements.checklist', $this->clientB))->assertForbidden();
        $this->actingAs($this->bookkeeper)->get('/requirements/'.$requirement->id.'/edit')->assertForbidden();
        $this->actingAs($this->bookkeeper)->put('/requirements/'.$requirement->id, $this->requirementData(['client_id' => $this->clientB->id]))->assertForbidden();
    }

    public function test_bookkeeper_requirement_filters_hide_other_clients_periods(): void
    {
        $own = $this->makePeriod($this->clientA, '2024-01-01', '2024-12-31');
        $other = $this->makePeriod($this->clientB, '2024-01-01', '2024-12-31');

        foreach (['/requirements', '/requirements/monitoring'] as $url) {
            $response = $this->actingAs($this->bookkeeper)->get($url)->assertOk();
            $this->assertTrue($response->viewData('periods')->contains('id', $own->id));
            $this->assertFalse($response->viewData('periods')->contains('id', $other->id));
            $response->assertDontSee($this->clientB->business_name);
        }
    }

    public function test_follow_up_history(): void
    {
        $requirement = $this->makeRequirement();
        $this->actingAs($this->manager)->post('/requirements/'.$requirement->id.'/follow-ups', [
            'status' => 'In Progress', 'assigned_to' => $this->bookkeeper->id, 'follow_up_date' => today()->toDateString(), 'remarks' => 'Chase the client',
        ])->assertSessionHasNoErrors();

        $followUp = DocumentFollowUp::firstOrFail();
        $this->assertSame('In Progress', $followUp->status);
        $this->assertSame($this->bookkeeper->id, $followUp->assigned_to);
        $this->assertDatabaseHas('audit_logs', ['action' => 'follow-up.created']);

        $this->actingAs($this->manager)->get(route('requirements.checklist', $this->clientA))->assertSee('Chase the client');
        $this->actingAs($this->manager)->delete('/follow-ups/'.$followUp->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('document_follow_ups', ['id' => $followUp->id]);
    }

    public function test_dashboard_summary_is_consistent_with_service(): void
    {
        $requirement = $this->makeRequirement();
        $requirement->documents()->attach($this->makeDocument(status: 'Approved')->id, ['linked_by' => $this->owner->id]);
        $this->makeRequirement(null, ['name' => 'Missing one']);

        $this->actingAs($this->owner);
        $dashboard = Summary::dashboard();
        $organization = DocumentCompleteness::organization($this->owner);

        $this->assertSame($organization['missing'], $dashboard['requirements']['missing']);
        $this->assertSame($organization['verified'], $dashboard['requirements']['verified']);
        $this->assertSame(1, $dashboard['requirements']['clients_missing']);
    }

    public function test_monitoring_filters_and_paginates(): void
    {
        foreach (range(1, 20) as $i) {
            $this->makeRequirement(null, ['name' => 'Requirement '.$i]);
        }

        $response = $this->actingAs($this->manager)->get('/requirements/monitoring');
        $response->assertOk();
        $this->assertSame(20, $response->viewData('records')->total());

        $filtered = $this->actingAs($this->manager)->get('/requirements/monitoring?state=missing');
        $filtered->assertOk()->assertSee('Requirement 1');
        foreach ($filtered->viewData('records') as $record) {
            $this->assertSame(DocumentCompleteness::STATE_NOT_SUBMITTED, DocumentCompleteness::stateOf($record));
        }
    }

    public function test_linked_approved_document_replacement_still_restricted(): void
    {
        $requirement = $this->makeRequirement();
        $document = $this->makeDocument(status: 'Approved');
        $requirement->documents()->attach($document->id, ['linked_by' => $this->owner->id]);

        $data = $document->fresh()->only(['client_id', 'title', 'document_type', 'status', 'notes']);
        $data['received_date'] = $document->received_date->toDateString();
        $data['status'] = 'Submitted';
        $data['file'] = \Illuminate\Http\UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf');

        $this->actingAs($this->manager)->put('/documents/'.$document->id, $data)->assertSessionHasErrors('status');
        $this->assertSame('Approved', $document->fresh()->status);
    }

    public function test_management_pages_render(): void
    {
        $this->makeRequirement(null, ['remarks' => 'A remark that is longer than sixty characters to exercise truncation safely.']);
        DocumentRequirementTemplate::create(['name' => 'Template A', 'type' => 'Receipt', 'is_required' => true]);

        $this->actingAs($this->manager)->get('/requirements')->assertOk()->assertSee('Annual receipts');
        $this->actingAs($this->manager)->get('/requirements/create')->assertOk();
        $this->actingAs($this->manager)->get('/requirements/templates')->assertOk()->assertSee('Template A');
        $this->actingAs($this->manager)->get(route('requirements.index', ['client_id' => $this->clientA->id]))->assertOk()->assertSee('Annual receipts');
    }
}
