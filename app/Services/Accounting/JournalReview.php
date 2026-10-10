<?php

namespace App\Services\Accounting;

use App\Models\LedgerEntry;

final class JournalReview
{
    public static function digest(LedgerEntry $entry): string
    {
        $entry = $entry->fresh();

        $snapshot = [
            $entry->only(['id', 'client_id', 'transaction_date', 'reference_number', 'description', 'notes', 'created_by', 'reviewed_by', 'reviewed_at', 'accounting_period_id']),
            $entry->accountingPeriod?->only(['id', 'client_id', 'accounting_year_id', 'starts_on', 'ends_on']),
            $entry->items()->orderBy('id')->get()->map(fn ($line) => [
                $line->only(['id', 'client_id', 'account_id', 'account_name', 'debit', 'credit']),
                $line->account?->only(['id', 'client_id', 'code', 'name', 'classification']),
            ])->all(),
            $entry->evidence()->whereNull('detached_at')->orderBy('id')->get()->map(fn ($e) => $e->only([
                'id', 'client_id', 'document_id', 'document_number', 'title', 'file_path', 'original_file_name', 'mime_type', 'sha256', 'attached_by',
            ]))->all(),
        ];
        // Preserve existing non-voucher review hashes byte-for-byte.
        if ($voucher = $entry->voucher()->first()) {
            $snapshot[] = $voucher->only(['id', 'client_id', 'ledger_entry_id', 'type', 'sequence', 'reference', 'party', 'cash_account_id', 'amount', 'check_number', 'check_date']);
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
