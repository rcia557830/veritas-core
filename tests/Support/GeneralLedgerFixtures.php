<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Support\Money;

trait GeneralLedgerFixtures
{
    use JournalFixtures;

    /** Each synthetic journal has exact balancing counterpart lines. */
    protected function ledgerJournal(Client $client, string $date, array $cashMovements, string $status = 'Posted', string $reference = 'SYNTHETIC-GL'): LedgerEntry
    {
        auth()->login(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $payload = $this->journalPayload($client);
        [$cash, $counterpart] = array_column($payload['items'], 'account_id');
        $payload['transaction_date'] = $date;
        $payload['reference_number'] = $reference;
        $payload['description'] = 'SYNTHETIC General Ledger fixture';
        $payload['items'] = [];
        $net = 0;
        foreach ($cashMovements as $movement) {
            $net = Money::addCents($net, $movement);
            $payload['items'][] = ['account_id' => $cash, 'debit' => Money::decimal(max(0, $movement)), 'credit' => Money::decimal(max(0, -$movement))];
        }
        if ($net !== 0) {
            $payload['items'][] = ['account_id' => $counterpart, 'debit' => Money::decimal(max(0, -$net)), 'credit' => Money::decimal(max(0, $net))];
        }
        $entry = JournalWriter::save($payload);
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
}
