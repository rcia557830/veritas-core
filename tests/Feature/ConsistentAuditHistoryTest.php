<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use App\Support\AuditHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GeneralLedgerFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class ConsistentAuditHistoryTest extends TestCase
{
    use GeneralLedgerFixtures, RefreshDatabase, SuperAdminFixtures, VoucherFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@veritascore.local')->firstOrFail();
    }

    public function test_audit_history_formats_json_descriptions_into_human_readable_text(): void
    {
        // 1. Account JSON formatting
        $accountRaw = 'Account created: {"code":"1000","name":"Cash in Bank","classification":"Asset"}';
        $formatted = AuditHistory::description($accountRaw);
        $this->assertStringContainsString('Code: 1000 · Name: Cash in Bank · Classification: Asset', $formatted);
        $this->assertStringNotContainsString('{"code"', $formatted);

        // 2. Voucher JSON formatting
        $voucherRaw = 'Voucher CR-0001 (CR) saved. {"party":"Acme Corp","cash_account_id":1,"amount":"500.00","check_number":"CHK-99","check_date":"2026-04-10"}';
        $voucherFormatted = AuditHistory::description($voucherRaw);
        $this->assertStringContainsString('Payee: Acme Corp', $voucherFormatted);
        $this->assertStringContainsString('Amount: ₱500.00', $voucherFormatted);
        $this->assertStringContainsString('Check #: CHK-99', $voucherFormatted);
        $this->assertStringContainsString('Check date: 2026-04-10', $voucherFormatted);
        $this->assertStringNotContainsString('{"party"', $voucherFormatted);

        // 3. Journal lines JSON formatting
        $linesRaw = 'Structured journal saved; status: Draft. Lines: [{"account_name":"Cash","debit":"100.00","credit":"0.00"},{"account_name":"Sales","debit":"0.00","credit":"100.00"}]';
        $linesFormatted = AuditHistory::description($linesRaw);
        $this->assertStringContainsString('Line items (2): Cash (DR ₱100.00), Sales (CR ₱100.00)', $linesFormatted);
        $this->assertStringNotContainsString('[{"account_name"', $linesFormatted);

        // 4. User ID reference resolution
        $reviewer = $this->user('manager');
        $postedRaw = 'Journal posted; reviewed by #'.$reviewer->id.'; review SHA-256: abc123def456.';
        $postedFormatted = AuditHistory::description($postedRaw);
        $this->assertStringContainsString('reviewed by '.$reviewer->name, $postedFormatted);
        $this->assertStringNotContainsString('#'.$reviewer->id, $postedFormatted);
    }

    public function test_audit_diff_computes_previous_and_new_values(): void
    {
        $before = [
            'status' => 'For Review',
            'transaction_date' => '2026-04-01',
            'voucher' => [
                'type' => 'CR',
                'reference' => 'CR-0001',
                'amount' => '500.00',
                'party' => 'Old Client',
                'check_number' => 'CHK-1',
                'check_date' => '2026-04-01',
            ],
            'lines' => [
                ['account_name' => 'Cash', 'debit' => '500.00', 'credit' => '0.00'],
                ['account_name' => 'Revenue', 'debit' => '0.00', 'credit' => '500.00'],
            ],
        ];

        $after = [
            'status' => 'Reviewed',
            'transaction_date' => '2026-04-02',
            'voucher' => [
                'type' => 'CR',
                'reference' => 'CR-0001',
                'amount' => '650.00',
                'party' => 'New Client',
                'check_number' => 'CHK-2',
                'check_date' => '2026-04-02',
            ],
            'lines' => [
                ['account_name' => 'Cash', 'debit' => '650.00', 'credit' => '0.00'],
                ['account_name' => 'Revenue', 'debit' => '0.00', 'credit' => '650.00'],
            ],
        ];

        $diff = AuditHistory::diff($before, $after);
        $diffMap = collect($diff)->keyBy('field')->all();

        $this->assertArrayHasKey('status', $diffMap);
        $this->assertSame('For Review', $diffMap['status']['old']);
        $this->assertSame('Reviewed', $diffMap['status']['new']);

        $this->assertArrayHasKey('voucher.amount', $diffMap);
        $this->assertSame('₱500.00', $diffMap['voucher.amount']['old']);
        $this->assertSame('₱650.00', $diffMap['voucher.amount']['new']);

        $this->assertArrayHasKey('voucher.party', $diffMap);
        $this->assertSame('Old Client', $diffMap['voucher.party']['old']);
        $this->assertSame('New Client', $diffMap['voucher.party']['new']);

        $this->assertArrayHasKey('voucher.check_number', $diffMap);
        $this->assertSame('CHK-1', $diffMap['voucher.check_number']['old']);
        $this->assertSame('CHK-2', $diffMap['voucher.check_number']['new']);

        $this->assertArrayHasKey('lines', $diffMap);
        $this->assertStringContainsString('₱500.00', $diffMap['lines']['old']);
        $this->assertStringContainsString('₱650.00', $diffMap['lines']['new']);
    }

    public function test_financial_audit_show_displays_attribution_and_diff(): void
    {
        $client = Client::firstOrFail();
        $this->actingAs($this->user('bookkeeper'));
        $payload = $this->voucherPayload($client, 'CR');
        $voucher = VoucherWriter::save($payload);

        // Edit the voucher to generate an update event with before/after state
        $payload['amount'] = '750.00';
        $payload['party'] = 'Updated Acme Corp';
        $payload['items'][0]['debit'] = '750.00';
        $payload['items'][1]['credit'] = '750.00';
        VoucherWriter::save($payload, $voucher);

        $this->actingAs($this->user('manager'));
        $response = $this->get('/financial-audit/'.$voucher->ledger_entry_id);

        $response->assertOk();
        // Attribution: who changed what
        $response->assertSee('Sample Bookkeeper');
        $response->assertSee('Voucher updated');
        // Diff display: previous and new values
        $response->assertSee('Recorded financial changes');
        $response->assertSee('Summary of differences');
        $response->assertSee('₱500.00');
        $response->assertSee('₱750.00');
        $response->assertSee('Updated Acme Corp');
        // Isolation banner
        $response->assertSee('Financial Audit Trail:');
        $response->assertSee('System Activity Logs');
    }

    public function test_journal_detail_displays_audit_attribution_and_diffs(): void
    {
        $client = Client::firstOrFail();
        $this->actingAs($this->user('bookkeeper'));
        $entry = $this->structuredJournal($this->user('bookkeeper'));

        JournalWriter::transition($entry, 'submit', 'Submitting for manager review');

        $this->actingAs($this->user('manager'));
        JournalWriter::transition($entry, 'review', 'Approved by manager');
        JournalPosting::post($entry);

        $response = $this->get('/ledger/'.$entry->id);
        $response->assertOk();

        // Check attribution
        $response->assertSee('Journal audit history');
        $response->assertSee('by Sample Bookkeeper');
        $response->assertSee('by Sample Office Manager');
        // Check readable description
        $response->assertSee('Journal status changed to For Review');
        $response->assertSee('Journal status changed to Reviewed');
        $response->assertSee('Journal posted;');
        // Check previous/new values diff section
        $response->assertSee('Previous / new values');
        $response->assertSee('Reviewed');
        $response->assertSee('Posted');
    }

    public function test_account_history_displays_attribution_and_diffs(): void
    {
        $client = Client::firstOrFail();
        $this->actingAs($this->user('owner'));

        $account = ChartOfAccounts::save($client, [
            'code' => '9999',
            'name' => 'Initial Audit Account',
            'classification' => 'Asset',
        ]);

        ChartOfAccounts::save($client, [
            'code' => '9999',
            'name' => 'Renamed Audit Account',
            'classification' => 'Expense',
        ], $account);

        $response = $this->get('/accounts/'.$account->id);
        $response->assertOk();

        // Check attribution
        $response->assertSee('by System Owner');
        $response->assertSee('Account history');
        // Check readable description
        $response->assertSee('Renamed Audit Account');
        // Check diff
        $response->assertSee('Previous / new values');
        $response->assertSee('Initial Audit Account');
        $response->assertSee('Asset');
        $response->assertSee('Expense');
    }

    public function test_financial_history_is_isolated_from_security_logs(): void
    {
        $superAdmin = $this->superAdmin();
        $manager = $this->user('manager');

        // Super-admin cannot access financial audit
        $this->actingAs($superAdmin)->get('/financial-audit')->assertForbidden();

        // Financial staff cannot access security or activity logs
        $this->actingAs($manager)->get('/admin/activity-logs')->assertForbidden();
        $this->actingAs($manager)->get('/admin/security-logs')->assertForbidden();

        // Super-admin sees separation notice in activity logs and security logs
        $actResponse = $this->actingAs($superAdmin)->get('/admin/activity-logs');
        $actResponse->assertOk();
        $actResponse->assertSee('Financial Audit Trail');

        $secResponse = $this->actingAs($superAdmin)->get('/admin/security-logs');
        $secResponse->assertOk();
        $secResponse->assertSee('Financial Audit Trail');

        // Financial staff sees separation notice in financial audit
        $finResponse = $this->actingAs($manager)->get('/financial-audit');
        $finResponse->assertOk();
        $finResponse->assertSee('System Activity Logs');
    }
}
