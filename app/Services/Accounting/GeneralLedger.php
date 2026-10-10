<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\LedgerItem;
use App\Services\Access;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class GeneralLedger
{
    public function authorize(Client $client): Client
    {
        Gate::authorize('generalLedger', LedgerEntry::class);

        return Access::client($client->id);
    }

    private function dates(string $start, string $end): void
    {
        Validator::make(['start' => $start, 'end' => $end], [
            'start' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start|before_or_equal:9999-12-31',
        ])->validate();
    }

    private function lines(int $clientId, string $end, ?int $accountId = null): Builder
    {
        $posted = LedgerEntry::posted()->where('client_id', $clientId)
            // Exclude the entire unmapped journal, including partially mapped legacy rows.
            ->whereDoesntHave('items', fn ($q) => $q->whereNull('account_id')->orWhereNull('client_id'))
            ->select('ledger_entries.id');
        $query = LedgerItem::query()->join('ledger_entries as journal', 'journal.id', '=', 'ledger_items.ledger_entry_id')
            ->join('accounts as account', 'account.id', '=', 'ledger_items.account_id')
            ->whereIn('ledger_items.ledger_entry_id', $posted)->where('ledger_items.client_id', $clientId)->where('account.client_id', $clientId)
            ->whereDate('journal.transaction_date', '<=', $end)
            ->when($accountId !== null, fn ($q) => $q->where('ledger_items.account_id', $accountId))
            ->select(['ledger_items.id', 'ledger_items.account_id', 'ledger_items.ledger_entry_id', 'journal.reference_number', 'journal.description',
                'account.code as account_code', 'account.name as account_name', 'account.classification as account_classification', 'account.is_active as account_active'])
            // SQLite retains Laravel's midnight suffix in DATE-affinity columns;
            // normalize to calendar dates on both engines, including boundaries.
            ->selectRaw('DATE(journal.transaction_date) AS transaction_date')
            ->orderByRaw('DATE(journal.transaction_date)')->orderBy('journal.id')->orderBy('ledger_items.id');
        // Read individual DECIMAL values as text. Never use SQL SUM: SQLite's
        // numeric affinity would turn aggregate arithmetic into floating point.
        foreach (['debit', 'credit'] as $side) {
            $expression = DB::getDriverName() === 'sqlite' ? "printf('%.2f', ledger_items.$side)" : "CAST(ledger_items.$side AS CHAR)";
            $query->selectRaw("$expression AS exact_$side");
        }

        return $query;
    }

    /** One ordered financial SELECT supplies summary, page carry-forward and page rows. */
    public function account(Client $client, Account $account, string $start, string $end, int $page = 1, int $perPage = 25, bool $allRows = false): array
    {
        $client = $this->authorize($client);
        $account = $client->accounts()->findOrFail($account->id);
        Gate::authorize('view', $account);
        $this->dates($start, $end);
        Validator::make(compact('page', 'perPage'), ['page' => 'integer|min:1|max:1000000', 'perPage' => 'integer|min:1|max:100'])->validate();
        $balance = new LedgerBalance;
        $rows = [];
        $count = 0;
        $offset = $allRows ? 0 : ($page - 1) * $perPage;
        $pageOpening = null;
        foreach ($this->lines($client->id, $end, $account->id)->cursor() as $line) {
            $debit = Money::storedCents($line->exact_debit);
            $credit = Money::storedCents($line->exact_credit);
            $before = $line->transaction_date < $start;
            if (! $before && $count === $offset) {
                $pageOpening = $balance->closing;
            }
            $balance->apply($debit, $credit, $before);
            if (! $before) {
                if ($allRows || ($count >= $offset && $count < $offset + $perPage)) {
                    $rows[] = ['line_id' => $line->id, 'journal_id' => $line->ledger_entry_id, 'date' => $line->transaction_date,
                        'reference' => $line->reference_number, 'description' => $line->description,
                        'debit' => $debit, 'credit' => $credit, 'running' => $balance->closing];
                }
                $count++;
            }
        }

        return ['account' => $account, 'balance' => $balance, 'rows' => $rows, 'count' => $count, 'page_opening' => $pageOpening ?? $balance->closing];
    }

    /** Shared account-balance foundation for future authorized financial reports. */
    public function balances(Client $client, string $start, string $end): Collection
    {
        $client = $this->authorize($client);
        $this->dates($start, $end);
        $balances = $client->accounts()->orderBy('code')->orderBy('id')->get()->mapWithKeys(fn ($account) => [
            $account->id => ['account' => $account, 'balance' => new LedgerBalance],
        ]);
        foreach ($this->lines($client->id, $end)->cursor() as $line) {
            // A newly created account can be posted between the metadata read
            // and this statement. Include its metadata from the same row set.
            if (! $balances->has($line->account_id)) {
                $account = (new Account)->newFromBuilder(['id' => $line->account_id, 'client_id' => $client->id,
                    'code' => $line->account_code, 'name' => $line->account_name, 'classification' => $line->account_classification, 'is_active' => $line->account_active]);
                $balances->put($line->account_id, ['account' => $account, 'balance' => new LedgerBalance]);
            }
            $balances[$line->account_id]['balance']->apply(Money::storedCents($line->exact_debit), Money::storedCents($line->exact_credit), $line->transaction_date < $start);
        }

        return $balances;
    }
}
