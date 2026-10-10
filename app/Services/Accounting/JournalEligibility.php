<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

final class JournalEligibility
{
    // Pure validation. Callers hold the client lock shared by account/period
    // writers, plus the entry lock, when using this at a workflow write boundary.
    public static function validate(LedgerEntry $entry): void
    {
        $entry = $entry->fresh();
        $period = AccountingPeriod::whereKey($entry->accounting_period_id)->where('client_id', $entry->client_id)->first();
        $date = $entry->transaction_date->toDateString();
        if (! $period || $period->status !== 'Open' || $date < $period->starts_on || $date > $period->ends_on) {
            throw ValidationException::withMessages(['accounting_period_id' => 'An open same-client period containing the transaction date is required.']);
        }
        $lines = $entry->items()->get();
        if ($lines->count() < 2 || $lines->count() > 100) {
            throw ValidationException::withMessages(['items' => 'Use between two and one hundred journal lines.']);
        }
        $debits = $credits = 0;
        $signatures = [];
        foreach ($lines as $line) {
            $account = $line->account_id ? Account::find($line->account_id) : null;
            if (! $account || ! $account->is_active || $line->client_id !== $entry->client_id || $account->client_id !== $entry->client_id) {
                throw ValidationException::withMessages(['items' => 'Every line requires an active same-client account and complete ownership references.']);
            }
            $debit = Money::cents($line->debit);
            $credit = Money::cents($line->credit);
            $signature = $account->id.':'.$debit.':'.$credit;
            if (isset($signatures[$signature])) {
                throw ValidationException::withMessages(['items' => 'Duplicate account and amount lines must be corrected before submission or review.']);
            }
            $signatures[$signature] = true;
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(['items' => 'Each line requires exactly one positive debit or credit.']);
            }
            if ($debit > PHP_INT_MAX - $debits || $credit > PHP_INT_MAX - $credits) {
                throw ValidationException::withMessages(['items' => 'Journal totals exceed the supported integer range.']);
            }
            $debits += $debit;
            $credits += $credit;
        }
        if ($debits <= 0 || $debits !== $credits) {
            throw ValidationException::withMessages(['items' => 'Positive debit and credit totals must balance exactly.']);
        }
    }
}
