<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use App\Support\AuditHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\JournalFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class CompleteDemoWalkthroughJourneyTest extends TestCase
{
    use JournalFixtures, RefreshDatabase, SuperAdminFixtures;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');

        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->superAdmin = $this->superAdmin();
    }

    public function test_complete_demo_walkthrough_client_journey_across_all_roles(): void
    {
        // =========================================================================
        // STEP 1: SUPER-ADMIN ISOLATION CHECK
        // =========================================================================
        $this->actingAs($this->superAdmin);
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/security-logs')->assertOk();
        $this->get('/clients')->assertForbidden();
        $this->get('/ledger')->assertForbidden();
        $this->get('/vouchers')->assertForbidden();
        $this->get('/financial-reports')->assertForbidden();

        // =========================================================================
        // STEP 2: CLIENT SETUP (OWNER) & SETUP CHECKLIST
        // =========================================================================
        $this->actingAs($this->owner);

        // Create new client
        $client = Client::create([
            'client_code' => 'DEMO-LOG-01',
            'business_name' => 'Demo Walkthrough Logistics Inc.',
            'trade_name' => 'Demo Logistics',
            'business_type' => 'Corporation',
            'tin' => '987-654-321-000',
            'status' => 'Active',
            'assigned_to' => $this->bookkeeper->id,
            'created_by' => $this->owner->id,
        ]);

        // Prior to chart of accounts and periods: options reflect unconfigured state
        $setupRes = $this->getJson("/ledger/options?client_id={$client->id}");
        $setupRes->assertOk();
        $setupRes->assertJson([
            'accounts' => [],
            'periods' => [],
            'setup' => [
                'years' => [],
            ],
        ]);

        // Create accounts for client
        $cash = Account::create([
            'client_id' => $client->id,
            'code' => '1010',
            'name' => 'Operating Cash in Bank',
            'classification' => 'Asset',
            'is_active' => true,
        ]);

        $revenue = Account::create([
            'client_id' => $client->id,
            'code' => '4010',
            'name' => 'Freight Service Revenue',
            'classification' => 'Revenue',
            'is_active' => true,
        ]);

        $expense = Account::create([
            'client_id' => $client->id,
            'code' => '5010',
            'name' => 'Fuel & Transport Expense',
            'classification' => 'Expense',
            'is_active' => true,
        ]);

        // Create Fiscal Year & Period
        $year = AccountingYear::create([
            'client_id' => $client->id,
            'label' => 'FY 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'is_closed' => false,
        ]);

        $period = AccountingPeriod::create([
            'client_id' => $client->id,
            'accounting_year_id' => $year->id,
            'label' => 'Q2 2026',
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-06-30',
            'status' => 'Open',
        ]);

        // Now: options reflect populated accounts, periods, and fiscal year
        $readyRes = $this->getJson("/ledger/options?client_id={$client->id}");
        $readyRes->assertOk();
        $this->assertCount(3, $readyRes->json('accounts'));
        $this->assertCount(1, $readyRes->json('periods'));
        $this->assertCount(1, $readyRes->json('setup.years'));

        // =========================================================================
        // STEP 3: DOCUMENTS (BOOKKEEPER UPLOAD & OFFICE MANAGER APPROVAL)
        // =========================================================================
        $this->actingAs($this->owner);
        $requirement = DocumentRequirement::create([
            'client_id' => $client->id,
            'name' => 'Commercial Freight Invoice',
            'type' => 'Invoice',
            'is_mandatory' => true,
            'created_by' => $this->owner->id,
        ]);

        // Bookkeeper logs in and uploads document evidence
        $this->actingAs($this->bookkeeper);
        $file = UploadedFile::fake()->create('freight_invoice.pdf', 300, 'application/pdf');

        $docRes = $this->post('/documents', [
            'client_id' => $client->id,
            'title' => 'Commercial Freight Receipt 001',
            'document_type' => 'Invoice',
            'status' => 'Submitted',
            'received_date' => '2026-04-15',
            'file' => $file,
        ]);
        $docRes->assertRedirect();
        $document = Document::where('client_id', $client->id)->firstOrFail();

        // Office Manager reviews and approves the document
        $this->actingAs($this->manager);
        $valRes = $this->post("/documents/{$document->id}/validate", [
            'status' => 'Approved',
            'notes' => 'Validated and approved by Office Manager',
        ]);
        $valRes->assertRedirect();
        $document->refresh();
        $this->assertSame('Approved', $document->status);

        // =========================================================================
        // STEP 4: VOUCHER CREATION & DEBIT/CREDIT GUIDANCE (BOOKKEEPER)
        // =========================================================================
        $this->actingAs($this->bookkeeper);

        // Voucher form shows client setup and live debit/credit guidance elements
        $formRes = $this->get('/vouchers/create?type=CR&client_id='.$client->id);
        $formRes->assertOk();
        $formRes->assertSee('Debit & Credit Guide', false);
        $formRes->assertSee('Total Debits = Total Credits');
        $formRes->assertSee('data-debit-credit-guide', false);

        // Prepare Cash Receipt Voucher (CR-000001) for ₱5,000.00 (500,000 integer cents)
        // Debit: Cash on Hand ₱5,000.00
        // Credit: Service Revenue ₱5,000.00
        $payload = [
            'client_id' => $client->id,
            'type' => 'CR',
            'creation_token' => (string) Str::uuid(),
            'transaction_date' => '2026-04-15',
            'accounting_period_id' => $period->id,
            'description' => 'Collected freight services payment',
            'notes' => 'Voucher verified with commercial invoice evidence',
            'party' => 'Pacific Freight Forwarders',
            'amount' => '5000.00',
            'cash_account_id' => $cash->id,
            'items' => [
                [
                    'account_id' => $cash->id,
                    'debit' => '5000.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $revenue->id,
                    'debit' => '0.00',
                    'credit' => '5000.00',
                ],
            ],
            'document_ids' => [$document->id],
        ];

        $voucher = VoucherWriter::save($payload);
        $journal = $voucher->journal;
        $this->assertSame('Draft', $journal->status);
        $this->assertSame('5000.00', $journal->total_debit);
        $this->assertSame('5000.00', $journal->total_credit);
        $this->assertSame('5000.00', $voucher->amount);

        // Bookkeeper submits voucher for review
        JournalWriter::transition($journal, 'submit', 'Submitted for manager review');
        $journal->refresh();
        $this->assertSame('For Review', $journal->status);

        // Strict Separation: Bookkeeper CANNOT self-review or post!
        $this->actingAs($this->bookkeeper);
        $this->post("/ledger/{$journal->id}/transition", ['action' => 'review'])->assertForbidden();
        $this->post("/ledger/{$journal->id}/post")->assertForbidden();

        // =========================================================================
        // STEP 5: ACTIONABLE DASHBOARD, REVIEW & POSTING (OFFICE MANAGER)
        // =========================================================================
        $this->actingAs($this->manager);

        // Manager dashboard highlights reviewable journals with direct action link
        $dashRes = $this->get('/dashboard');
        $dashRes->assertOk();
        $dashRes->assertSee('Priority Action Items');
        $dashRes->assertSee('Journals Awaiting Review');
        $dashRes->assertSee(route('ledger.index', ['work' => 'reviewable']));
        $dashRes->assertSee('Review entry');

        // Manager reviews the entry
        JournalWriter::transition($journal, 'review', 'Reviewed and verified against documentation');
        $journal->refresh();
        $this->assertSame('Reviewed', $journal->status);

        // Manager posts the entry
        $this->post("/ledger/{$journal->id}/post")->assertRedirect();
        $journal->refresh();
        $this->assertSame('Posted', $journal->status);

        // Verify Consistent Audit History (Priority 4)
        $audit = AuditLog::where('module', 'ledger')
            ->where('record_id', $journal->id)
            ->where('action', 'post')
            ->firstOrFail();

        $this->assertSame($this->manager->id, $audit->user_id);
        $this->assertStringContainsString('Posted', AuditHistory::description($audit));
        $diff = AuditHistory::diff($audit->evidence['before'] ?? null, $audit->evidence['after'] ?? null);
        $this->assertNotEmpty($diff);

        // =========================================================================
        // STEP 6: LEDGER & FINANCIAL REPORTS RECONCILIATION (OWNER)
        // =========================================================================
        $this->actingAs($this->owner);

        // 1. General Ledger reflects posted transaction in integer cents
        $glRes = $this->get("/general-ledger?client_id={$client->id}&account_id={$cash->id}&start_date=2026-04-01&end_date=2026-04-30");
        $glRes->assertOk();
        $glRes->assertSee('₱5,000.00');

        // 2. Financial Reports: Trial Balance reconciles debits == credits
        $tbRes = $this->get("/financial-reports?client_id={$client->id}&type=trial-balance&start_date=2026-04-01&end_date=2026-04-30");
        $tbRes->assertOk();
        $tbRes->assertSee('₱5,000.00');

        // 3. Financial Reports: Income Statement shows net income
        $isRes = $this->get("/financial-reports?client_id={$client->id}&type=income-statement&start_date=2026-04-01&end_date=2026-04-30");
        $isRes->assertOk();
        $isRes->assertSee('₱5,000.00');

        // 4. Financial Audit Trail shows the posted voucher and attribution
        $auditRes = $this->get("/financial-audit?client_id={$client->id}&status=Posted");
        $auditRes->assertOk();
        $auditRes->assertSee('Financial Audit Trail');
        $auditRes->assertSee('Sample Office Manager');
    }
}
