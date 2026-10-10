<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use App\Services\Accounting\GeneralLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GeneralLedgerFixtures;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use GeneralLedgerFixtures, RefreshDatabase;

    private Client $client;

    private Account $cash;

    private GeneralLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->client = Client::firstOrFail();
        $payload = $this->journalPayload($this->client);
        $this->cash = Account::findOrFail($payload['items'][0]['account_id']);
        $this->ledger = new GeneralLedger;
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
    }

    private function filters(array $overrides = []): array
    {
        return array_replace(['client_id' => $this->client->id, 'account_id' => $this->cash->id,
            'start_date' => '2026-04-01', 'end_date' => '2026-04-30'], $overrides);
    }

    private function url(array $overrides = [], bool $print = false): string
    {
        return ($print ? '/general-ledger/print?' : '/general-ledger?').http_build_query($this->filters($overrides));
    }

    public function test_independent_fixture_posted_only_opening_movements_and_closing(): void
    {
        $this->ledgerJournal($this->client, '2026-03-31', [100000]);
        $debit = $this->ledgerJournal($this->client, '2026-04-01', [20000]);
        $credit = $this->ledgerJournal($this->client, '2026-04-30', [-7500]);
        $this->ledgerJournal($this->client, '2026-05-01', [99900]);
        foreach (['Draft', 'For Review', 'Reviewed', 'Needs Correction'] as $status) {
            $this->ledgerJournal($this->client, '2026-04-12', [98700], $status);
        }
        $result = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30');
        $this->assertSame([100000, 20000, 7500, 12500, 112500], array_values(get_object_vars($result['balance'])));
        $this->assertSame([$debit->id, $credit->id], array_column($result['rows'], 'journal_id'));
        $this->assertSame([120000, 112500], array_column($result['rows'], 'running'));
        $this->get($this->url())->assertOk()->assertSee('₱1,000.00 Dr')->assertSee('₱200.00')->assertSee('₱75.00')->assertSee('₱1,125.00 Dr');
        $all = $this->ledger->balances($this->client, '2026-04-01', '2026-04-30');
        $this->assertSame(112500, $all[$this->cash->id]['balance']->closing);
        $this->assertSame(-112500, $all->first(fn ($item) => $item['account']->classification === 'Revenue')['balance']->closing);
        $this->assertSame(0, $all->sum(fn ($item) => $item['balance']->closing));
    }

    public function test_deterministic_date_entry_line_order_and_running_balances_across_pages(): void
    {
        $this->ledgerJournal($this->client, '2026-03-01', [100000]);
        $later = $this->ledgerJournal($this->client, '2026-04-20', [20000]);
        $multiple = $this->ledgerJournal($this->client, '2026-04-10', [5000, 2500]);
        $sameDate = $this->ledgerJournal($this->client, '2026-04-10', [-7500]);
        $first = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30', 1, 2);
        $second = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30', 2, 2);
        $this->assertSame([105000, 107500], array_column($first['rows'], 'running'));
        $this->assertSame([100000, 120000], array_column($second['rows'], 'running'));
        $this->assertSame(107500, $second['page_opening']);
        $this->assertEquals($first['balance'], $second['balance']);
        $this->assertSame(4, $second['count']);
        $this->assertSame([$multiple->id, $multiple->id, $sameDate->id, $later->id], array_column([...$first['rows'], ...$second['rows']], 'journal_id'));
        $this->assertSame($multiple->items()->where('account_id', $this->cash->id)->orderBy('id')->pluck('id')->all(), array_column($first['rows'], 'line_id'));
        $this->assertSame($second['rows'], $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30', 2, 2)['rows']);
    }

    public function test_paginated_http_and_print_keep_filters_and_full_range_totals_without_writes(): void
    {
        $this->ledgerJournal($this->client, '2026-03-01', [100000]);
        $entry = $this->ledgerJournal($this->client, '2026-04-10', range(100, 1100, 100));
        $before = [DB::table('ledger_entries')->get()->toJson(), DB::table('ledger_items')->get()->toJson(), DB::table('audit_logs')->count()];
        $this->get($this->url(['per_page' => 10, 'page' => 2]))->assertOk()->assertViewHas('movements', function ($movements) {
            parse_str(parse_url($movements->previousPageUrl(), PHP_URL_QUERY), $query);

            return $movements->total() === 11 && $movements->count() === 1 && $query['account_id'] == $this->cash->id && $query['start_date'] === '2026-04-01';
        })->assertSee('₱1,055.00 Dr')->assertSee('₱1,066.00 Dr')->assertSee(route('ledger.show', $entry->id), false);
        $this->get($this->url(['per_page' => 10, 'page' => 2], true))->assertOk()->assertSee('Page 2 of 2')->assertSee('₱1,055.00 Dr')->assertSee('₱1,066.00 Dr')->assertSee('Read-only copy of the selected page');
        $this->assertSame($before, [DB::table('ledger_entries')->get()->toJson(), DB::table('ledger_items')->get()->toJson(), DB::table('audit_logs')->count()]);
        $this->get($this->url(['page' => 999]))->assertOk()->assertSee('No movements on this page')->assertSee('₱1,066.00 Dr');
    }

    public function test_date_boundaries_empty_activity_and_inactive_accounts(): void
    {
        $this->ledgerJournal($this->client, '2026-04-01', [10000]);
        $this->ledgerJournal($this->client, '2026-04-30', [-2500]);
        $this->cash->update(['is_active' => false]);
        $result = $this->ledger->account($this->client, $this->cash, '2026-04-30', '2026-04-30');
        $this->assertSame(10000, $result['balance']->opening);
        $this->assertSame(7500, $result['balance']->closing);
        $this->assertCount(1, $result['rows']);
        $this->get($this->url(['start_date' => '2026-05-01', 'end_date' => '2026-05-31']))->assertOk()->assertSee('No posted movements')->assertSee('₱75.00 Dr')->assertSee('Inactive');
        $empty = $this->ledger->account($this->client, $this->cash, '2026-01-01', '2026-01-31');
        $this->assertSame(0, $empty['balance']->closing);
        $this->assertSame([], $empty['rows']);
        $zero = Account::create(['client_id' => $this->client->id, 'code' => '003-ZERO', 'name' => 'SYNTHETIC Unused', 'classification' => 'Expense']);
        $this->assertSame(0, $this->ledger->balances($this->client, '2026-04-01', '2026-04-30')[$zero->id]['balance']->closing);
    }

    public function test_exact_precision_signed_balances_and_maximum_permitted_amounts(): void
    {
        $this->ledgerJournal($this->client, '2026-04-01', [10, 20]);
        $this->ledgerJournal($this->client, '2026-04-02', [-31]);
        $result = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30');
        $this->assertSame(-1, $result['balance']->closing);
        $this->get($this->url())->assertOk()->assertSee('₱0.01 Cr');
        $this->ledgerJournal($this->client, '2026-04-03', [99999999999]);
        $this->ledgerJournal($this->client, '2026-04-04', [99999999999]);
        $balance = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30')['balance'];
        $this->assertSame(199999999997, $balance->closing);
        $this->assertSame(200000000028, $balance->debits);
        $this->assertSame(31, $balance->credits);
        $this->get($this->url())->assertOk()->assertSee('₱1,999,999,999.97 Dr');
    }

    public function test_clients_remain_isolated_even_with_identical_account_codes_and_forged_parameters(): void
    {
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $this->ledgerJournal($this->client, '2026-04-01', [10000]);
        $this->ledgerJournal($other, '2026-04-01', [90000], reference: 'OTHER-CLIENT-SECRET');
        $foreign = $other->accounts()->where('code', $this->cash->code)->firstOrFail();
        $this->get($this->url())->assertOk()->assertDontSee('OTHER-CLIENT-SECRET')->assertSee('₱100.00 Dr');
        foreach ([false, true] as $print) {
            $this->get($this->url(['account_id' => $foreign->id], $print))->assertNotFound();
        }
        $this->get($this->url(['status' => 'Draft', 'sort' => 'client_id', 'client_id' => $this->client->id]))->assertOk()->assertDontSee('OTHER-CLIENT-SECRET');
        $this->assertSame(90000, $this->ledger->balances($other, '2026-04-01', '2026-04-30')[$foreign->id]['balance']->closing);
        $this->expectException(ModelNotFoundException::class);
        $this->ledger->account($this->client, $foreign, '2026-04-01', '2026-04-30');
    }

    public function test_unassigned_clients_and_direct_service_access_are_denied(): void
    {
        $this->client->update(['assigned_to' => null]);
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $this->get($this->url())->assertNotFound();
        $this->get($this->url([], true))->assertNotFound();
        $this->get('/general-ledger')->assertOk()->assertDontSee($this->client->business_name);
        $this->expectException(ModelNotFoundException::class);
        $this->ledger->balances($this->client, '2026-04-01', '2026-04-30');
    }

    public static function viewPermissions(): array
    {
        return [['bookkeeping.view'], ['account.view'], ['client.view']];
    }

    #[DataProvider('viewPermissions')]
    public function test_each_required_permission_is_checked_on_all_endpoints_and_service(string $permission): void
    {
        auth()->user()->role->permissions()->detach(Permission::where('name', $permission)->value('id'));
        $this->get($this->url())->assertForbidden();
        $this->get($this->url([], true))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->ledger->balances($this->client, '2026-04-01', '2026-04-30');
    }

    public function test_print_permission_guest_access_and_read_only_routes(): void
    {
        auth()->user()->role->permissions()->detach(Permission::where('name', 'report.print')->value('id'));
        $this->get($this->url())->assertOk()->assertDontSee('Print this page');
        $this->get($this->url([], true))->assertForbidden();
        $this->post('/general-ledger', $this->filters())->assertStatus(405);
        auth()->logout();
        $this->get('/general-ledger')->assertRedirect('/login');
        $this->get($this->url([], true))->assertRedirect('/login');
    }

    public function test_fully_and_partially_unmapped_legacy_journals_are_excluded_without_mutation(): void
    {
        $legacy = LedgerEntry::whereNull('accounting_period_id')->firstOrFail();
        DB::table('ledger_entries')->where('id', $legacy->id)->update(['status' => 'Posted', 'transaction_date' => '2026-04-12']);
        $partial = $this->ledgerJournal($this->client, '2026-04-12', [12300], 'Draft');
        DB::table('ledger_items')->where('ledger_entry_id', $partial->id)->where('account_id', '!=', $this->cash->id)->update(['client_id' => null, 'account_id' => null]);
        DB::table('ledger_entries')->where('id', $partial->id)->update(['status' => 'Posted']);
        $before = DB::table('ledger_items')->get()->toJson();
        $result = $this->ledger->account($this->client, $this->cash, '2026-04-01', '2026-04-30');
        $this->assertSame(0, $result['count']);
        $this->assertSame(0, $result['balance']->closing);
        $this->assertSame($before, DB::table('ledger_items')->get()->toJson());
    }

    public function test_filters_reject_arrays_invalid_dates_and_excessive_pagination(): void
    {
        foreach ([['client_id' => ['1']], ['account_id' => ['1']], ['start_date' => '2026-02-30'], ['start_date' => '2026-05-01'], ['end_date' => '2026-04-31'], ['per_page' => 100000], ['page' => 0], ['page' => '999999999999999999999']] as $bad) {
            $this->getJson($this->url($bad))->assertUnprocessable();
        }
        $this->get('/general-ledger')->assertOk()->assertSee('Select a client');
        $this->get('/general-ledger?client_id='.$this->client->id)->assertOk()->assertSee('Select an account');
        $empty = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $this->get('/general-ledger?client_id='.$empty->id)->assertOk()->assertSee('No accounts configured');
    }
}
