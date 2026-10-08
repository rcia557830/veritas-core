<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class Records
{
    public static function query(string $module, Request $request): Builder
    {
        $request->validate([
            'q' => 'nullable|string|max:150', 'search' => 'nullable|string|max:150', 'status' => 'nullable|string|max:60',
            'client_id' => 'nullable|integer|min:1', 'type' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|string|max:60', 'direction' => 'nullable|in:asc,desc',
        ]);
        $config = Modules::get($module);
        $query = Access::query($config['model']);
        if (! in_array($module, ['clients', 'knowledge'])) {
            $query->with('client');
        }
        if ($module === 'billing') {
            $query->with(['items', 'payments']);
        }
        if ($module === 'ledger') {
            $query->with('items');
        }
        if ($search = self::searchText($request)) {
            $query->where(function ($q) use ($search, $config, $module) {
                foreach ($config['search'] as $column) {
                    $q->orWhere($column, 'like', '%'.mb_substr($search, 0, 150).'%');
                }
                if ($module === 'billing') {
                    $q->orWhereHas('payments', fn ($payments) => $payments->where('reference_number', 'like', '%'.$search.'%'));
                    // Search the displayed financial status, not the stored Open lifecycle alone.
                    foreach ($config['statuses'] as $label) {
                        if (! str_contains(mb_strtolower($label), mb_strtolower($search))) {
                            continue;
                        }
                        $q->orWhere(function ($statusQuery) use ($label) {
                            if (in_array($label, ['Draft', 'Cancelled'])) {
                                $statusQuery->where('status', $label);
                            } else {
                                self::invoiceStatus($statusQuery, $label);
                            }
                        });
                    }
                }
                if (! in_array($module, ['clients', 'knowledge'])) {
                    $q->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', '%'.mb_substr($search, 0, 150).'%'));
                }
            });
        }
        if ($client = $request->integer('client_id')) {
            if (! in_array($module, ['clients', 'knowledge'])) {
                $query->where('client_id', $client);
            }
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, $config['statuses'])) {
            if ($module === 'compliance' && $status === 'Overdue') {
                $query->where('status', '!=', 'Filed')->whereDate('due_date', '<', today());
            } elseif ($module === 'billing' && ! in_array($status, ['Draft', 'Cancelled'])) {
                self::invoiceStatus($query, $status);
            } else {
                $query->where('status', $status);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = $request->query($key);
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $query->whereDate($config['date'], $operator, $date);
            }
        }
        $typeColumn = ['clients' => 'business_type', 'documents' => 'document_type', 'compliance' => 'agency', 'knowledge' => 'category'][$module] ?? null;
        if ($typeColumn && $request->filled('type')) {
            $query->where($typeColumn, (string) $request->query('type'));
        }
        $allowed = array_unique(array_merge(['id', $config['date']], array_keys($config['fields'])));
        $sort = in_array($request->query('sort'), $allowed) ? $request->query('sort') : $config['date'];

        return $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')->orderBy('id', 'desc');
    }

    public static function invoiceStatus(Builder $query, string $status): void
    {
        // Static SQL expressions only. Filter values remain bound parameters.
        $total = '(COALESCE((SELECT SUM(ROUND(quantity * unit_price,2)) FROM invoice_items WHERE invoice_items.invoice_id = invoices.id),0) + invoices.tax)';
        $paid = 'COALESCE((SELECT SUM(amount) FROM payments WHERE payments.invoice_id = invoices.id),0)';
        $query->where('status', 'Open');
        if ($status === 'Paid') {
            $query->whereRaw("$paid >= $total");
        } else {
            $query->whereRaw("$paid < $total");
            if ($status === 'Overdue') {
                $query->whereDate('due_date', '<', today());
            } else {
                $query->whereDate('due_date', '>=', today());
                if ($status === 'Partially Paid') {
                    $query->whereHas('payments');
                } else {
                    $query->whereDoesntHave('payments');
                }
            }
        }
    }

    public static function searchText(Request $request): string
    {
        $request->validate(['q' => 'nullable|string|max:150', 'search' => 'nullable|string|max:150']);

        return trim((string) ($request->query('q') ?? $request->query('search', '')));
    }

    public static function pageSize(?Request $request = null): int
    {
        $request ??= request();
        $request->validate(['per_page' => 'nullable|integer|in:10,25,50', 'page' => 'nullable|integer|min:1']);
        $configured = (int) Setting::value('page_size');
        $default = in_array($configured, [10, 25, 50]) ? $configured : 10;

        return $request->filled('per_page') ? (int) $request->query('per_page') : $default;
    }
}
