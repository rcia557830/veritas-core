<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;

trait AccountingFixtures
{
    protected function syntheticClient(): Client
    {
        $user = User::factory()->create();

        return Client::create(['client_code' => 'SYNTHETIC-'.$user->id, 'business_name' => 'Synthetic accounting fixture '.$user->id,
            'business_type' => 'Corporation', 'created_by' => $user->id, 'assigned_to' => $user->id]);
    }

    protected function syntheticAccount(Client $client, string $code = '001-a'): Account
    {
        return Account::create(['client_id' => $client->id, 'code' => $code, 'name' => 'Synthetic account', 'classification' => 'Asset', 'is_active' => true]);
    }

    protected function syntheticYear(Client $client): AccountingYear
    {
        return AccountingYear::create(['client_id' => $client->id, 'label' => 'Synthetic fiscal year', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
    }

    protected function syntheticPeriod(Client $client): AccountingPeriod
    {
        $year = $this->syntheticYear($client);

        return AccountingPeriod::create(['client_id' => $client->id, 'accounting_year_id' => $year->id,
            'label' => 'Synthetic April', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30']);
    }

    protected function syntheticEntry(Client $client, ?AccountingPeriod $period = null): LedgerEntry
    {
        return LedgerEntry::create(['client_id' => $client->id, 'transaction_date' => '2026-04-12',
            'reference_number' => 'SYNTHETIC-JOURNAL', 'description' => 'Synthetic accounting test only', 'status' => 'Draft',
            'created_by' => $client->created_by, 'accounting_period_id' => $period?->id]);
    }
}
