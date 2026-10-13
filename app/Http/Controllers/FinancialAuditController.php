<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Accounting\FinancialAuditTrail;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinancialAuditController extends Controller
{
    public function index(Request $request, FinancialAuditTrail $audit)
    {
        return DB::transaction(function () use ($request, $audit) {
            [$query, $filters, $clients, $accounts] = $audit->query($request);
            $totals = $audit->totals($query);
            $lines = $query->with(['account', 'entry.client', 'entry.voucher', 'entry.creator', 'entry.reviewer', 'entry.poster'])
                ->orderByDesc('ledger_entry_id')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

            return view('financial-audit.index', compact('lines', 'filters', 'clients', 'accounts', 'totals'));
        });
    }

    public function show(int $journal)
    {
        Gate::authorize('generalLedger', LedgerEntry::class);
        $entry = Access::query(LedgerEntry::class)->with(['items.account', 'client', 'voucher', 'creator', 'reviewer', 'poster', 'evidence.document'])->findOrFail($journal);
        Gate::authorize('view', $entry);

        return view('financial-audit.show', compact('entry'));
    }

    public function export(Request $request, FinancialAuditTrail $audit)
    {
        Gate::authorize('report.export');
        [$query] = $audit->query($request);

        return response()->streamDownload(function () use ($query) {
            DB::transaction(function () use ($query) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date', 'Journal reference', 'Client', 'Voucher type', 'Voucher reference', 'Account code', 'Account name', 'Debit', 'Credit', 'Description', 'Prepared by', 'Reviewed by', 'Posted by', 'Status'], ',', '"', '');
                foreach ($query->with(['account', 'entry.client', 'entry.voucher', 'entry.creator', 'entry.reviewer', 'entry.poster'])->orderBy('id')->lazyById(500) as $line) {
                    $entry = $line->entry;
                    $cells = [$entry->transaction_date->toDateString(), $entry->reference_number, $entry->client->business_name,
                        $entry->voucher?->type, $entry->voucher?->reference, $line->account->code, $line->account->name,
                        Money::decimal(Money::storedCents($line->debit)), Money::decimal(Money::storedCents($line->credit)),
                        $entry->description, $entry->creator?->name, $entry->reviewer?->name, $entry->poster?->name, $entry->status];
                    fputcsv($out, array_map([FinancialAuditTrail::class, 'csvCell'], $cells), ',', '"', '');
                }
                fclose($out);
            });
        }, 'financial-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
