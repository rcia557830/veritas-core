<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\AccountTemplate;
use App\Services\Accounting\JournalEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountingFixtures;
use Tests\TestCase;

class AccountingSchemaTest extends TestCase
{
    use AccountingFixtures, RefreshDatabase;

    private function rejects(callable $operation, string $class = ValidationException::class): void
    {
        try {
            $operation();
            $this->fail('Expected accounting integrity rejection.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($class, $error);
        }
    }

    public function test_account_codes_have_deterministic_scoped_uniqueness(): void
    {
        $client = $this->syntheticClient();
        $account = $this->syntheticAccount($client, ' 001-a ');
        $this->assertSame('001-a', $account->code);
        $this->assertSame(hash('sha256', '001-A'), $account->code_key);
        $this->rejects(fn () => $this->syntheticAccount($client, '001-A'), QueryException::class);
        foreach (['01-A', '001A', '001 A', 'é-a', 'É-a'] as $code) {
            $this->assertNotSame($account->code_key, $this->syntheticAccount($client, $code)->code_key);
        }
        $this->assertSame($account->code_key, $this->syntheticAccount($this->syntheticClient(), '001-A')->code_key);
        $this->rejects(fn () => $this->syntheticAccount($client, '   '));
        $this->rejects(fn () => $this->syntheticAccount($client, "001\tA"));
        $this->rejects(fn () => $this->syntheticAccount($client, str_repeat('A', 256)));
    }

    public function test_templates_are_independent_initialization_resources(): void
    {
        $template = AccountTemplate::create(['name' => 'SYNTHETIC TEMPLATE', 'version' => 1]);
        $item = $template->items()->create(['code' => '001-a', 'name' => 'Synthetic asset', 'classification' => 'Asset']);
        $this->rejects(fn () => $template->items()->create(['code' => '001-A', 'name' => 'Duplicate', 'classification' => 'Asset']), QueryException::class);
        $client = $this->syntheticClient();
        $account = Account::create(['client_id' => $client->id, 'account_template_item_id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'classification' => $item->classification]);
        $this->assertTrue($account->templateItem->is($item));
        $this->assertTrue($item->template->is($template));
        $this->assertSame(1, $item->accounts()->count());
        $this->rejects(fn () => $item->update(['classification' => 'Expense']));
        $this->rejects(fn () => $template->update(['version' => 2]));
        $this->assertSame('Asset', $account->fresh()->classification);
        $this->assertSame(1, $client->accounts()->count());
        $this->rejects(fn () => DB::table('account_template_items')->where('id', $item->id)->delete(), QueryException::class);
        $this->rejects(fn () => DB::table('accounts')->where('id', $account->id)->update(['classification' => 'Invalid']), QueryException::class);
    }

    public function test_structured_lines_derive_owner_and_database_rejects_cross_client_and_partial_nulls(): void
    {
        $client = $this->syntheticClient();
        $other = $this->syntheticClient();
        $account = $this->syntheticAccount($client);
        $foreign = $this->syntheticAccount($other);
        $entry = $this->syntheticEntry($client);
        $data = ['ledger_entry_id' => $entry->id, 'account_name' => 'Original label', 'debit' => '1.00', 'credit' => '0.00'];
        $line = $entry->items()->create($data + ['account_id' => $account->id]);
        $this->assertSame($client->id, $line->client_id);
        $this->assertTrue($line->account->is($account));
        $this->assertTrue($line->client->is($client));
        $this->rejects(fn () => $entry->items()->create($data + ['account_id' => $foreign->id]));
        foreach ([['account_id' => $foreign->id, 'client_id' => $client->id], ['account_id' => $foreign->id, 'client_id' => $other->id], ['account_id' => $account->id, 'client_id' => null], ['account_id' => null, 'client_id' => $client->id], ['account_id' => 999999, 'client_id' => $client->id]] as $refs) {
            $this->rejects(fn () => DB::table('ledger_items')->insert($data + $refs), QueryException::class);
        }
        $id = DB::table('ledger_items')->insertGetId($data);
        $this->assertNull(DB::table('ledger_items')->where('id', $id)->value('account_id'));
        $this->rejects(fn () => DB::table('accounts')->where('id', $account->id)->delete(), QueryException::class);
        $this->rejects(fn () => $account->delete());
        $this->rejects(fn () => $account->update(['client_id' => $other->id]));
    }

    public function test_fiscal_dates_allow_noncalendar_years_and_reject_overlap_and_invalid_containment(): void
    {
        $client = $this->syntheticClient();
        $period = $this->syntheticPeriod($client);
        $year = $period->year;
        $this->assertSame('2026-04-01', $year->starts_on);
        $this->assertSame(1, $client->accountingYears()->count());
        $this->assertSame(1, $client->accountingPeriods()->count());
        $yearData = ['client_id' => $client->id, 'label' => 'Synthetic year', 'starts_on' => '2027-03-31', 'ends_on' => '2028-03-30'];
        $this->rejects(fn () => AccountingYear::create($yearData));
        $yearData['starts_on'] = '2027-04-01';
        $this->assertNotNull(AccountingYear::create($yearData)->id);
        $periodData = ['client_id' => $client->id, 'accounting_year_id' => $year->id, 'label' => 'Synthetic period', 'starts_on' => '2026-04-30', 'ends_on' => '2026-05-31'];
        $this->rejects(fn () => AccountingPeriod::create($periodData));
        $periodData['starts_on'] = '2026-05-01';
        $next = AccountingPeriod::create($periodData);
        $this->assertNotNull($next->id);
        $this->rejects(fn () => $next->update(['starts_on' => '2026-04-29']));
        $this->rejects(fn () => AccountingPeriod::create(array_merge($periodData, ['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31'])));
        $this->rejects(fn () => $year->update(['ends_on' => '2026-04-15']));
        $this->rejects(fn () => AccountingYear::create(array_merge($yearData, ['starts_on' => '2028-02-30'])));
        $this->assertNotNull($this->syntheticPeriod($this->syntheticClient())->id);
    }

    public function test_database_date_closure_and_period_ownership_constraints(): void
    {
        $client = $this->syntheticClient();
        $period = $this->syntheticPeriod($client);
        $other = $this->syntheticClient();
        $this->rejects(fn () => DB::table('accounting_years')->where('id', $period->accounting_year_id)->update(['ends_on' => '2025-01-01']), QueryException::class);
        $this->rejects(fn () => DB::table('accounting_periods')->where('id', $period->id)->update(['ends_on' => '2025-01-01']), QueryException::class);
        foreach ([['status' => 'Closed'], ['status' => 'Open', 'closed_by' => $client->created_by], ['status' => 'Invalid'], ['client_id' => $other->id]] as $change) {
            $this->rejects(fn () => DB::table('accounting_periods')->where('id', $period->id)->update($change), QueryException::class);
        }
        $entry = $this->syntheticEntry($other);
        $this->rejects(fn () => $entry->update(['accounting_period_id' => $period->id]));
        $this->rejects(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['accounting_period_id' => $period->id]), QueryException::class);
        $valid = $this->syntheticEntry($client, $period);
        $this->assertTrue($valid->accountingPeriod->is($period));
        $this->rejects(fn () => $period->update(['ends_on' => '2026-04-29']));
        $this->rejects(fn () => DB::table('accounting_periods')->where('id', $period->id)->delete(), QueryException::class);
        $this->rejects(fn () => DB::table('accounting_years')->where('id', $period->accounting_year_id)->delete(), QueryException::class);
        DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'Closed', 'closed_by' => $client->created_by, 'closed_at' => now()]);
        $this->assertSame($client->created_by, $period->fresh()->closer->id);
    }

    public function test_stale_period_updates_validate_current_dates_under_the_client_lock(): void
    {
        $client = $this->syntheticClient();
        $period = $this->syntheticPeriod($client);
        $stale = $period->fresh();
        $period->update(['starts_on' => '2026-04-20']);
        $this->rejects(fn () => $stale->update(['ends_on' => '2026-04-15']));
        $this->assertSame('2026-04-20', $period->fresh()->starts_on);
        $this->assertSame('2026-04-30', $period->fresh()->ends_on);
    }

    public function test_eligibility_requires_complete_active_accounts_period_and_exact_balancing(): void
    {
        $client = $this->syntheticClient();
        $period = $this->syntheticPeriod($client);
        $entry = $this->syntheticEntry($client, $period);
        $account = $this->syntheticAccount($client);
        $debit = $entry->items()->create(['account_name' => 'Synthetic debit', 'debit' => '0.30', 'credit' => '0.00']);
        $entry->items()->create(['account_name' => 'Synthetic credit', 'account_id' => $account->id, 'debit' => '0.00', 'credit' => '0.30']);
        $this->rejects(fn () => JournalEligibility::validate($entry));
        $debit->update(['account_id' => $account->id]);
        JournalEligibility::validate($entry);
        $this->assertSame('Draft', $entry->fresh()->status);
        $account->update(['is_active' => false]);
        $this->rejects(fn () => JournalEligibility::validate($entry));
        $account->update(['is_active' => true]);
        $this->rejects(fn () => $debit->update(['debit' => '0.301']));
        $this->rejects(fn () => $debit->update(['debit' => '-0.30']));
        $debit->refresh();
        foreach (['0.29', '0.00'] as $amount) {
            $debit->update(['debit' => $amount]);
            $this->rejects(fn () => JournalEligibility::validate($entry));
        }
        $debit->update(['debit' => '0.30', 'credit' => '0.01']);
        $this->rejects(fn () => JournalEligibility::validate($entry));
        $debit->update(['credit' => '0.00']);
        DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'Closed', 'closed_by' => $client->created_by, 'closed_at' => now()]);
        $this->rejects(fn () => JournalEligibility::validate($entry));
        $entry->update(['accounting_period_id' => null]);
        $this->rejects(fn () => JournalEligibility::validate($entry));
    }

    public function test_historical_accounts_and_posted_models_are_protected(): void
    {
        $client = $this->syntheticClient();
        $account = $this->syntheticAccount($client);
        $entry = $this->syntheticEntry($client, $this->syntheticPeriod($client));
        $line = $entry->items()->create(['account_name' => 'Original synthetic label', 'account_id' => $account->id, 'debit' => '1.00', 'credit' => '0.00']);
        // Construct historical state directly in the isolated fixture. No posting service exists yet.
        DB::table('ledger_entries')->where('id', $entry->id)->update(['status' => 'Posted', 'posted_by' => $client->created_by, 'posted_at' => now()]);
        foreach (['classification' => 'Expense', 'code' => '002', 'name' => 'Changed'] as $key => $value) {
            $fresh = $account->fresh();
            $this->rejects(fn () => $fresh->update([$key => $value]));
        }
        $account->update(['is_active' => false]);
        $this->assertFalse($account->fresh()->is_active);
        $this->assertSame('Asset', $account->fresh()->classification);
        $this->rejects(fn () => $entry->update(['description' => 'Changed']));
        $this->rejects(fn () => $entry->delete());
        $this->rejects(fn () => $line->update(['debit' => '2.00']));
        $this->rejects(fn () => $line->delete());
        $this->assertSame('Original synthetic label', $line->fresh()->account_name);
        $this->assertSame($client->created_by, $entry->fresh()->poster->id);
    }
}
