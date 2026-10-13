<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class Records
{
    public static function query(string $module, Request $request): Builder
    {
        $request->validate([
            'queue' => 'nullable|in:attention,validation,outstanding',
            'work' => 'nullable|in:mine,reviewable,postable',
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
        if ($module === 'ledger' && $request->filled('work')) {
            if ($request->query('work') === 'mine') {
                $query->where('created_by', auth()->id());
            } else {
                $posting = $request->query('work') === 'postable';
                Gate::authorize($posting ? 'bookkeeping.post' : 'bookkeeping.review');
                $query->where('created_by', '!=', auth()->id())->where('status', $posting ? 'Reviewed' : 'For Review');
            }
        }
        if ($module === 'documents' && in_array($request->query('queue'), ['attention', 'validation'])) {
            $query->whereIn('status', $request->query('queue') === 'validation' ? ['Submitted', 'Under Review'] : ['Submitted', 'Under Review', 'Needs Clarification']);
        }
        if ($module === 'billing' && $request->query('queue') === 'outstanding') {
            $query->where('status', 'Open')->where(function ($unpaid) {
                foreach (['Open', 'Partially Paid', 'Overdue'] as $label) {
                    $unpaid->orWhere(fn ($q) => self::invoiceStatus($q, $label));
                }
            });
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, $config['statuses'])) {
            if ($module === 'compliance' && $status === 'Overdue') {
                $query->whereNotIn('status', DeadlineCalculator::TERMINAL)->whereDate('due_date', '<', DeadlineCalculator::today());
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
