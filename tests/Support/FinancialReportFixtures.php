<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Support\Money;

trait FinancialReportFixtures
{
    protected function reportChart(Client $client): void
    {
        foreach (['100' => ['Cash', 'Asset'], '200' => ['Payable', 'Liability'], '300' => ['Capital', 'Equity'], '310' => ['Retained earnings', 'Equity'], '400' => ['Service revenue', 'Revenue'], '500' => ['Rent expense', 'Expense']] as $code => [$name, $classification]) {
            Account::firstOrCreate(['client_id' => $client->id, 'code' => (string) $code], ['name' => 'SYNTHETIC '.$name, 'classification' => $classification]);
        }
        foreach ([2024, 2025, 2026] as $yearNumber) {
            $year = AccountingYear::firstOrCreate(['client_id' => $client->id, 'label' => 'SYNTHETIC FY '.$yearNumber], ['starts_on' => "$yearNumber-07-01", 'ends_on' => ($yearNumber + 1).'-06-30']);
            AccountingPeriod::firstOrCreate(['client_id' => $client->id, 'label' => $year->label], ['accounting_year_id' => $year->id, 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on]);
        }
    }

    protected function reportJournal(Client $client, string $date, array $amounts, string $status = 'Posted'): LedgerEntry
    {
        auth()->login(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $period = AccountingPeriod::where('client_id', $client->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->firstOrFail();
        $items = [];
        $net = 0;
        foreach ($amounts as $code => $cents) {
            $net = Money::addCents($net, $cents);
            $items[] = ['account_id' => $client->accounts()->where('code', (string) $code)->value('id'), 'debit' => Money::decimal(max(0, $cents)), 'credit' => Money::decimal(max(0, -$cents))];
        }
        if ($net !== 0) {
            throw new \LogicException('Synthetic report fixtures must balance independently.');
        }
        $entry = JournalWriter::save(['client_id' => $client->id, 'accounting_period_id' => $period->id, 'transaction_date' => $date,
            'reference_number' => 'SYNTHETIC-FINANCIAL', 'description' => 'SYNTHETIC balanced financial report fixture', 'items' => $items]);
        if ($status === 'Draft') {
            return $entry;
        }
        JournalWriter::transition($entry, 'submit', null);
        if ($status === 'For Review') {
            return $entry->fresh();
        }
        auth()->login(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($entry, 'review', null);
        if ($status === 'Reviewed') {
            return $entry->fresh();
        }
        if ($status === 'Needs Correction') {
            return JournalWriter::transition($entry, 'return', null);
        }

        return JournalPosting::post($entry);
    }

    protected function independentReportFixture(Client $client): void
    {
        $this->reportChart($client);
        $this->reportJournal($client, '2025-06-29', [100 => 100000, 300 => -100000]);
        $this->reportJournal($client, '2025-06-30', [100 => 20000, 400 => -20000]);
        $this->reportJournal($client, '2026-04-01', [100 => 50000, 400 => -50000]);
        $this->reportJournal($client, '2026-04-15', [500 => 15000, 100 => -15000]);
        $this->reportJournal($client, '2026-04-30', [100 => 30000, 200 => -30000]);
    }
}
