<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\Permission;
use App\Models\User;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\GeneralLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinancialReportFixtures;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use FinancialReportFixtures, RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->client = Client::firstOrFail();
        $this->reportChart($this->client);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
    }

    private function report(string $type, string $start = '2026-04-01', string $end = '2026-04-30'): array
    {
        return (new FinancialReports)->generate($this->client, $type, $start, $end);
    }

    private function url(string $type = 'trial-balance', array $extra = [], bool $print = false): string
    {
        return '/financial-reports'.($print ? '/print' : '').'?'.http_build_query(array_replace(['client_id' => $this->client->id, 'type' => $type, 'start_date' => '2026-04-01', 'end_date' => '2026-04-30'], $extra));
    }

    public function test_independent_trial_balance_income_and_balance_sheet_reconcile_with_ledger(): void
    {
        $this->independentReportFixture($this->client);
        $trial = $this->report('trial-balance');
        $this->assertSame([200000, 200000, 0], [$trial['debits'], $trial['credits'], $trial['difference']]);
        $this->assertSame(['Asset' => 185000, 'Liability' => 30000, 'Equity' => 100000, 'Revenue' => 70000, 'Expense' => 15000], $trial['totals']);
        $income = $this->report('income-statement');
        $this->assertSame([50000, 15000, 35000, 0], [$income['totals']['Revenue'], $income['totals']['Expense'], $income['netIncome'], $income['difference']]);
        $sheet = $this->report('balance-sheet');
        $this->assertSame([20000, 35000, 185000, 0, '2025-07-01'], [$sheet['priorEarnings'], $sheet['currentEarnings'], $sheet['liabilitiesEquity'], $sheet['equationDifference'], $sheet['start']]);
        $ledger = (new GeneralLedger)->balances($this->client, '2026-04-01', '2026-04-30');
        foreach ($trial['groups'] as $rows) {
            foreach ($rows as $row) {
                $this->assertSame($ledger[$row['account']->id]['balance']->closing, $row['signed']);
            }
        }
        $this->get($this->url('balance-sheet'))->assertOk()->assertSee('not retained earnings')->assertSee('Provisional development convention')->assertSee('Verified');
    }

    public function test_fiscal_rollover_keeps_prior_unclosed_earnings_separate_without_transferring_equity(): void
    {
        $this->independentReportFixture($this->client);
        $this->reportJournal($this->client, '2026-07-01', [100 => 10000, 400 => -10000]);
        $before = $this->report('balance-sheet', '2026-06-01', '2026-06-30');
        $after = $this->report('balance-sheet', '2026-07-01', '2026-07-01');
        $this->assertSame([20000, 35000], [$before['priorEarnings'], $before['currentEarnings']]);
        $this->assertSame([55000, 10000, 100000, 195000, 0], [$after['priorEarnings'], $after['currentEarnings'], $after['totals']['Equity'], $after['totals']['Asset'], $after['equationDifference']]);
        $this->assertSame(0, collect($after['groups']['Equity'])->first(fn ($r) => $r['account']->code === '310')['signed']);
        $this->getJson($this->url('balance-sheet', ['end_date' => '2029-01-01']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_unusual_balances_net_loss_and_exact_cents_are_not_hidden(): void
    {
        $this->reportJournal($this->client, '2026-04-01', [100 => -31, 400 => 31]);
        $this->reportJournal($this->client, '2026-04-01', [100 => 10, 500 => -10]);
        $this->reportJournal($this->client, '2026-04-02', [100 => 20, 500 => -20]);
        $income = $this->report('income-statement');
        $this->assertSame([-31, -30, -1], [$income['totals']['Revenue'], $income['totals']['Expense'], $income['netIncome']]);
        $trial = $this->report('trial-balance');
        $this->assertSame([31, 31, -1], [$trial['debits'], $trial['credits'], $trial['totals']['Asset']]);
        $this->assertSame(0, $this->report('balance-sheet')['equationDifference']);
        $this->get($this->url('income-statement'))->assertOk()->assertSee('Net loss')->assertSee('₱-0.01');
    }

    public function test_explicit_posted_retained_earnings_are_not_counted_again_as_unclosed_earnings(): void
    {
        $this->independentReportFixture($this->client);
        // A synthetic, explicitly supplied balanced closing journal, not an automatic workflow.
        $this->reportJournal($this->client, '2025-06-30', [400 => 20000, 310 => -20000]);
        $sheet = $this->report('balance-sheet');
        $retained = collect($sheet['groups']['Equity'])->first(fn ($r) => $r['account']->code === '310');
        $this->assertSame(20000, $retained['presentation']);
        $this->assertSame([0, 35000, 120000, 185000, 0], [$sheet['priorEarnings'], $sheet['currentEarnings'], $sheet['totals']['Equity'], $sheet['liabilitiesEquity'], $sheet['equationDifference']]);
        $this->assertSame(35000, $this->report('income-statement')['netIncome']);
    }

    public function test_unposted_and_unmapped_records_are_excluded_and_date_boundaries_are_inclusive(): void
    {
        $this->independentReportFixture($this->client);
        foreach (['Draft', 'For Review', 'Reviewed', 'Needs Correction'] as $status) {
            $this->reportJournal($this->client, '2026-04-12', [100 => 99999, 400 => -99999], $status);
        }
        $legacy = $this->reportJournal($this->client, '2026-04-12', [100 => 12345, 400 => -12345], 'Draft');
        DB::table('ledger_items')->where('ledger_entry_id', $legacy->id)->where('credit', '>', 0)->update(['account_id' => null, 'client_id' => null]);
        DB::table('ledger_entries')->where('id', $legacy->id)->update(['status' => 'Posted']);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        $this->reportJournal($this->client, '2026-05-01', [100 => 77777, 400 => -77777]);
        $this->assertSame(35000, $this->report('income-statement')['netIncome']);
        $this->assertSame(50000, $this->report('income-statement', '2026-04-01', '2026-04-01')['netIncome']);
        $this->assertSame(185000, $this->report('trial-balance')['totals']['Asset']);
    }

    public function test_empty_periods_and_closed_period_selection_use_dates_without_double_counting_opening(): void
    {
        $this->independentReportFixture($this->client);
        $this->assertSame(0, $this->report('income-statement', '2026-03-01', '2026-03-31')['netIncome']);
        $this->assertSame(120000, $this->report('trial-balance', '2026-03-01', '2026-03-31')['totals']['Asset']);
        $period = AccountingPeriod::where('client_id', $this->client->id)->where('label', 'SYNTHETIC FY 2025')->firstOrFail();
        DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'Closed', 'closed_at' => now(), 'closed_by' => auth()->id()]);
        $this->get($this->url('income-statement', ['period_id' => $period->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-02']))->assertOk()->assertViewHas('filters', fn ($f) => $f['start_date'] === '2025-07-01' && $f['end_date'] === '2026-06-30')->assertViewHas('result', fn ($r) => $r['netIncome'] === 35000);
    }

    public function test_full_accounts_ledger_prints_all_movements_and_all_reports_are_read_only(): void
    {
        $this->independentReportFixture($this->client);
        for ($i = 1; $i <= 26; $i++) {
            $this->reportJournal($this->client, '2026-04-20', [100 => $i, 400 => -$i]);
        }
        $cash = $this->client->accounts()->where('code', '100')->value('id');
        $tables = ['ledger_entries', 'ledger_items', 'accounts', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
        foreach (array_keys(FinancialReports::TYPES) as $type) {
            foreach ([false, true] as $print) {
                $response = $this->get($this->url($type, ['account_id' => $cash], $print))->assertOk();
                if ($type === 'accounts-ledger') {
                    $response->assertViewHas('result', fn ($r) => count($r['ledger']['rows']) === 29 && $r['ledger']['balance']->opening === 120000 && $r['ledger']['balance']->closing === 185351);
                }
            }
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->post('/financial-reports')->assertStatus(405);
    }

    public function test_client_isolation_period_account_forgery_and_unassigned_access(): void
    {
        $this->independentReportFixture($this->client);
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $this->reportChart($other);
        $this->reportJournal($other, '2026-04-01', [100 => 999999, 400 => -999999]);
        $this->assertSame(185000, $this->report('trial-balance')['totals']['Asset']);
        foreach ([false, true] as $print) {
            $this->get($this->url('trial-balance', ['period_id' => AccountingPeriod::where('client_id', $other->id)->value('id')], $print))->assertNotFound();
            $this->get($this->url('accounts-ledger', ['account_id' => $other->accounts()->value('id')], $print))->assertNotFound();
        }
        $this->client->update(['assigned_to' => null]);
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $this->get($this->url())->assertNotFound();
        $this->get($this->url(print: true))->assertNotFound();
        $this->get('/financial-reports')->assertOk()->assertDontSee($this->client->business_name);
    }

    public static function permissions(): array
    {
        return array_map(fn ($p) => [$p], ['report.view', 'report.generate', 'bookkeeping.view', 'account.view', 'client.view']);
    }

    #[DataProvider('permissions')]
    public function test_required_permissions_protect_both_endpoints_and_service(string $permission): void
    {
        auth()->user()->role->permissions()->detach(Permission::where('name', $permission)->value('id'));
        $this->get($this->url())->assertForbidden();
        $this->get($this->url(print: true))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->report('trial-balance');
    }

    public function test_print_permission_guests_invalid_filters_and_empty_states(): void
    {
        $this->get('/financial-reports')->assertOk()->assertSee('Select a client');
        $this->get($this->url('accounts-ledger'))->assertOk()->assertSee('Select an account');
        $this->get($this->url('accounts-ledger', print: true))->assertStatus(422);
        foreach ([['type' => 'bogus'], ['client_id' => ['1']], ['period_id' => ['1']], ['start_date' => '2026-02-30'], ['end_date' => '2026-01-01']] as $bad) {
            $this->getJson($this->url(extra: $bad))->assertUnprocessable();
        }
        $this->assertSame(0, $this->report('trial-balance')['debits']);
        auth()->user()->role->permissions()->detach(Permission::where('name', 'report.print')->value('id'));
        $this->get($this->url())->assertOk()->assertDontSee('Print full report');
        $this->get($this->url(print: true))->assertForbidden();
        auth()->logout();
        $this->get('/financial-reports')->assertRedirect('/login');
        $this->get($this->url(print: true))->assertRedirect('/login');
    }

    public function test_unbalanced_source_is_warned_about_without_inserting_equity(): void
    {
        $draft = $this->reportJournal($this->client, '2026-04-01', [100 => 10000, 400 => -10000], 'Draft');
        // Synthetic imported corruption is arranged before posting; never mutate a posted journal.
        DB::table('ledger_items')->where('ledger_entry_id', $draft->id)->where('credit', '>', 0)->update(['credit' => '90.00']);
        DB::table('ledger_entries')->where('id', $draft->id)->update(['status' => 'Posted']);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        $sheet = $this->report('balance-sheet');
        $this->assertSame([1000, 1000, 0, 9000], [$sheet['difference'], $sheet['equationDifference'], $sheet['totals']['Equity'], $sheet['currentEarnings']]);
        foreach (['trial-balance', 'balance-sheet'] as $type) {
            $this->get($this->url($type))->assertOk()->assertSee('UNRECONCILED')->assertDontSee('Reconciled:');
            $this->get($this->url($type, print: true))->assertOk()->assertSee('UNRECONCILED');
        }
    }
}
