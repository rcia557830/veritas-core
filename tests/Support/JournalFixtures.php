<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;

trait JournalFixtures
{
    protected function journalPayload(Client $client, string $credit = '100.00'): array
    {
        $year = AccountingYear::firstOrCreate(['client_id' => $client->id, 'label' => 'SYNTHETIC JOURNAL YEAR'], ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $period = AccountingPeriod::firstOrCreate(['client_id' => $client->id, 'label' => 'SYNTHETIC JOURNAL PERIOD'], ['accounting_year_id' => $year->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $accounts = [];
        foreach (['001-SYN' => 'Asset', '002-SYN' => 'Revenue'] as $code => $classification) {
            $accounts[] = Account::firstOrCreate(['client_id' => $client->id, 'code' => $code], ['name' => 'SYNTHETIC '.$classification, 'classification' => $classification])->id;
        }

        return ['client_id' => $client->id, 'transaction_date' => '2026-04-12', 'accounting_period_id' => $period->id, 'reference_number' => 'TEST-LEDGER', 'description' => 'SYNTHETIC structured journal', 'items' => [
            ['account_id' => $accounts[0], 'debit' => '100.00', 'credit' => '0.00'], ['account_id' => $accounts[1], 'debit' => '0.00', 'credit' => $credit],
        ]];
    }

    protected function structuredJournal(User $creator, string $status = 'Draft', ?Client $client = null): LedgerEntry
    {
        $client ??= Client::firstOrFail();
        $data = $this->journalPayload($client);
        $lines = $data['items'];
        unset($data['items']);
        $entry = LedgerEntry::create($data + ['created_by' => $creator->id, 'status' => $status]);
        foreach ($lines as $line) {
            $entry->items()->create($line + ['account_name' => Account::findOrFail($line['account_id'])->name]);
        }

        return $entry;
    }
}
