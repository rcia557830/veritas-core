<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\LedgerItem;
use App\Services\Access;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class FinancialAuditTrail
{
    public const STATUSES = ['Draft', 'For Review', 'Reviewed', 'Needs Correction', 'Posted'];

    public function query(Request $request): array
    {
        Gate::authorize('generalLedger', LedgerEntry::class);
        $filters = $request->validate([
            'q' => 'nullable|string|max:150', 'client_id' => 'nullable|integer|min:1',
            'account_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'page' => 'nullable|integer|min:1|max:1000000', 'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
        ]);
        $client = ! empty($filters['client_id']) ? Access::client((int) $filters['client_id']) : null;
        $accounts = Account::whereIn('client_id', Access::query(Client::class)->select('id'))->when($client, fn ($q) => $q->where('client_id', $client->id))->orderBy('code')->get();
        if (! empty($filters['account_id'])) {
            abort_unless($accounts->contains('id', (int) $filters['account_id']), 404);
        }
        $journals = Access::query(LedgerEntry::class)
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('transaction_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('transaction_date', '<=', $filters['to']))
            ->when(isset($filters['q']) && $filters['q'] !== '', fn ($q) => $q->where(function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where('reference_number', 'like', $term)->orWhere('description', 'like', $term)
                    ->orWhereHas('voucher', fn ($v) => $v->where('reference', 'like', $term));
            }));
        // Same mapped-journal boundary as General Ledger. Unmapped legacy records
        // remain available through the original journal, never included as GL amounts.
        $journals->whereDoesntHave('items', fn ($q) => $q->whereNull('account_id')->orWhereNull('client_id'));
        // Correlate ownership explicitly without joining evidence or voucher rows,
        // so a journal line can contribute exactly once.
        $lines = LedgerItem::query()->whereIn('ledger_entry_id', $journals->select('ledger_entries.id'))
            ->whereHas('entry', fn ($q) => $q->whereColumn('ledger_entries.client_id', 'ledger_items.client_id'))
            ->whereHas('account', fn ($q) => $q->whereColumn('accounts.client_id', 'ledger_items.client_id'))
            ->when(! empty($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']));

        return [$lines, $filters, Access::query(Client::class)->orderBy('business_name')->get(), $accounts];
    }

    public function totals(Builder $query): array
    {
        $totals = ['posted' => ['debit' => 0, 'credit' => 0], 'unposted' => ['debit' => 0, 'credit' => 0]];
        foreach ((clone $query)->with('entry')->orderBy('id')->lazyById(500) as $line) {
            $group = $line->entry->status === 'Posted' ? 'posted' : 'unposted';
            foreach (['debit', 'credit'] as $side) {
                $totals[$group][$side] = Money::addCents($totals[$group][$side], Money::storedCents($line->$side));
            }
        }

        return $totals;
    }

    public static function csvCell($value): string
    {
        $value = (string) $value;

        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
