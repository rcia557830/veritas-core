<?php

namespace App\Services\Accounting;

use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FinancialReports
{
    public const TYPES = ['trial-balance' => 'Trial Balance', 'income-statement' => 'Income Statement', 'balance-sheet' => 'Balance Sheet', 'accounts-ledger' => 'Accounts Ledger'];

    public function generate(Client $client, string $type, string $start, string $end, ?int $accountId = null): array
    {
        Gate::authorize('financialReports', LedgerEntry::class);
        $ledger = new GeneralLedger;
        $client = $ledger->authorize($client);
        Validator::make(compact('start', 'end'), [
            'start' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start|before_or_equal:9999-12-31',
        ])->validate();
        if (! isset(self::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Choose a supported financial report.']);
        }
        $year = null;
        if ($type === 'accounts-ledger') {
            $account = $client->accounts()->findOrFail($accountId);

            return ['type' => $type, 'ledger' => $ledger->account($client, $account, $start, $end, allRows: true)];
        }
        if ($type === 'balance-sheet') {
            $year = AccountingYear::where('client_id', $client->id)->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $end)->first();
            if (! $year) {
                throw ValidationException::withMessages(['end_date' => 'Configure a fiscal year containing the reporting date before generating a Balance Sheet.']);
            }
            $start = substr($year->starts_on, 0, 10);
        }
        $balances = $ledger->balances($client, $start, $end);
        $groups = array_fill_keys(['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'], []);
        $totals = array_fill_keys(array_keys($groups), 0);
        $debits = $credits = $priorEarnings = $currentEarnings = 0;
        foreach ($balances as $item) {
            $account = $item['account'];
            $balance = $item['balance'];
            if (! array_key_exists($account->classification, $groups)) {
                throw ValidationException::withMessages(['report' => 'An account has an unsupported classification. Report generation stopped.']);
            }
            $signed = $type === 'income-statement' ? $balance->movement : $balance->closing;
            $debit = max(0, $signed);
            $credit = $signed < 0 ? Money::subtractCents(0, $signed) : 0;
            $debits = Money::addCents($debits, $debit);
            $credits = Money::addCents($credits, $credit);
            $presentation = in_array($account->classification, ['Liability', 'Equity', 'Revenue']) ? Money::subtractCents(0, $signed) : $signed;
            $totals[$account->classification] = Money::addCents($totals[$account->classification], $presentation);
            $groups[$account->classification][] = compact('account', 'balance', 'signed', 'debit', 'credit', 'presentation');
            if (in_array($account->classification, ['Revenue', 'Expense'])) {
                $priorEarnings = Money::subtractCents($priorEarnings, $balance->opening);
                $currentEarnings = Money::subtractCents($currentEarnings, $balance->movement);
            }
        }
        $netIncome = Money::subtractCents($totals['Revenue'], $totals['Expense']);
        $liabilitiesEquity = Money::addCents($totals['Liability'], $totals['Equity']);
        if ($type === 'balance-sheet') {
            $liabilitiesEquity = Money::addCents(Money::addCents($liabilitiesEquity, $priorEarnings), $currentEarnings);
        }
        $difference = Money::subtractCents($debits, $credits);
        $equationDifference = Money::subtractCents($totals['Asset'], $liabilitiesEquity);

        return compact('type', 'year', 'start', 'end', 'groups', 'totals', 'debits', 'credits', 'netIncome', 'priorEarnings', 'currentEarnings', 'liabilitiesEquity', 'difference', 'equationDifference');
    }
}
