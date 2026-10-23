<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Voucher;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ClientAccountingSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag);
    }

    private function setupOptions(bool $canManage = true): array
    {
        return [
            'accounts' => [['id' => 1, 'code' => '1000', 'name' => 'Cash in Bank', 'classification' => 'Asset']],
            'periods' => [['id' => 1, 'label' => 'October 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']],
            'documents' => [],
            'setup' => [
                'years' => [['id' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
                'accounts_url' => $canManage ? '/accounts?client_id=7' : null,
                'periods_url' => $canManage ? '/accounting-periods?client_id=7' : null,
            ],
        ];
    }

    private function checklist(array $options, string $date = '2026-10-23'): string
    {
        // Render synthetic data only; no hosted records, migrations, or seeders.
        return view('ledger.setup-checklist', [
            'record' => new LedgerEntry(['transaction_date' => $date]),
            'options' => $options,
        ])->render();
    }

    public function test_complete_setup_and_inclusive_period_boundaries(): void
    {
        foreach (['2026-10-01', '2026-10-23', '2026-10-31'] as $date) {
            $html = $this->checklist($this->setupOptions(), $date);
            $this->assertStringContainsString('3 of 3 complete', $html);
            $this->assertSame(3, substr_count($html, 'data-state="complete"'));
            $this->assertStringContainsString('href="/accounting-periods?client_id=7"', $html);
        }
    }

    public function test_existing_period_outside_transaction_date_is_not_ready(): void
    {
        $html = $this->checklist($this->setupOptions(), '2026-11-01');
        $this->assertStringContainsString('No open period covers this date', $html);
        $this->assertSame(2, substr_count($html, 'data-state="complete"'));

        $html = $this->checklist($this->setupOptions(), '2027-01-01');
        $this->assertStringContainsString('No year covers this date', $html);
        $this->assertSame(1, substr_count($html, 'data-state="complete"'));
    }

    public function test_missing_setup_directs_bookkeeper_to_authorized_staff(): void
    {
        $options = $this->setupOptions(false);
        $options['accounts'] = [];
        $options['periods'] = [];
        $options['setup']['years'] = [];
        $html = $this->checklist($options);
        $this->assertSame(3, substr_count($html, 'data-state="missing"'));
        $this->assertStringContainsString('Ask an Owner or Office Manager', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_no_client_is_pending_instead_of_missing(): void
    {
        $html = $this->checklist(['accounts' => [], 'periods' => []]);
        $this->assertSame(3, substr_count($html, 'data-state="pending"'));
        $this->assertStringContainsString('Select a client', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    private function form(array $options, ?Voucher $voucher = null, string $date = '2026-10-23'): string
    {
        return view('ledger.form-content', [
            'record' => new LedgerEntry(['transaction_date' => $date]),
            'clients' => collect(),
            'options' => $options,
            'evidence' => collect(),
            'voucher' => $voucher,
        ])->render();
    }

    public function test_empty_periods_dropdown_displays_helpful_message_and_link_for_authorized_staff(): void
    {
        $options = $this->setupOptions(true);
        $options['periods'] = [];
        $html = $this->form($options);
        $this->assertStringContainsString('No open periods for this client', $html);
        $this->assertStringContainsString('href="/accounting-periods?client_id=7"', $html);
        $this->assertStringContainsString('Set up periods', $html);
    }

    public function test_empty_periods_dropdown_shows_restricted_message_for_bookkeeper(): void
    {
        $options = $this->setupOptions(false);
        $options['periods'] = [];
        $html = $this->form($options);
        $this->assertStringContainsString('No open periods for this client', $html);
        $this->assertStringContainsString('Ask an Owner or Office Manager to set up periods.', $html);
        $this->assertStringNotContainsString('data-setup-period-link', $html);
    }

    public function test_empty_accounts_dropdown_displays_helpful_message_and_link_for_authorized_staff(): void
    {
        $options = $this->setupOptions(true);
        $options['accounts'] = [];
        $html = $this->form($options);
        $this->assertStringContainsString('No active accounts for this client', $html);
        $this->assertStringContainsString('href="/accounts?client_id=7"', $html);
        $this->assertStringContainsString('Set up accounts', $html);
    }

    public function test_empty_accounts_dropdown_shows_restricted_message_for_bookkeeper(): void
    {
        $options = $this->setupOptions(false);
        $options['accounts'] = [];
        $html = $this->form($options);
        $this->assertStringContainsString('No active accounts for this client', $html);
        $this->assertStringContainsString('Ask an Owner or Office Manager to set up accounts.', $html);
        $this->assertStringNotContainsString('/accounts?client_id=7', $html);
    }

    public function test_voucher_form_displays_helpful_empty_asset_account_and_period_dropdowns(): void
    {
        $options = $this->setupOptions(true);
        $options['periods'] = [];
        $options['accounts'] = [['id' => 2, 'code' => '4000', 'name' => 'Service Revenue', 'classification' => 'Revenue']];
        $voucher = (new Voucher)->forceFill(['type' => 'CR']);
        $html = $this->form($options, $voucher);
        $this->assertStringContainsString('No open periods for this client', $html);
        $this->assertStringContainsString('No active asset accounts for this client', $html);
        $this->assertStringContainsString('href="/accounts?client_id=7"', $html);
        $this->assertStringContainsString('href="/accounting-periods?client_id=7"', $html);
    }

    public function test_populated_dropdowns_render_standard_prompts_and_hide_empty_help(): void
    {
        $options = $this->setupOptions(true);
        $options['accounts'] = [['id' => 1, 'code' => '1000', 'name' => 'Cash in Bank', 'classification' => 'Asset']];
        $voucher = (new Voucher)->forceFill(['type' => 'CV']);
        $html = $this->form($options, $voucher);
        $this->assertStringContainsString('Select an open period (required to save)', $html);
        $this->assertStringContainsString('Choose a same-client asset account', $html);
        $this->assertStringContainsString('Choose an active account', $html);
        $this->assertMatchesRegularExpression('/data-period-empty-help[^>]*hidden/', $html);
        $this->assertMatchesRegularExpression('/data-account-empty-help[^>]*hidden/', $html);
        $this->assertMatchesRegularExpression('/data-voucher-cash-empty-help[^>]*hidden/', $html);
    }

    public function test_journal_form_renders_debit_credit_guide_with_balancing_rule_and_example(): void
    {
        $options = $this->setupOptions(true);
        $html = $this->form($options);
        $this->assertStringContainsString('Debit & Credit Guide', $html);
        $this->assertStringContainsString('The Balancing Rule', $html);
        $this->assertStringContainsString('Total Debits = Total Credits', $html);
        $this->assertStringContainsString('Rent Expense', $html);
        $this->assertStringContainsString('Cash in Bank', $html);
        $this->assertStringContainsString('data-debit-credit-guide', $html);
        $this->assertStringContainsString('data-guidance-block-reason', $html);
        $this->assertStringContainsString('Drafts can be saved unbalanced', $html);
    }

    public function test_voucher_form_displays_strict_save_requirements_in_guidance(): void
    {
        $options = $this->setupOptions(true);
        $voucher = (new Voucher)->forceFill(['type' => 'JV']);
        $html = $this->form($options, $voucher);
        $this->assertStringContainsString('Voucher save requirements', $html);
        $this->assertStringContainsString('Saving voucher requires balanced debits and credits', $html);
    }
}
