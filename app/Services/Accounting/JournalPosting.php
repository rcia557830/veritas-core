<?php

namespace App\Services\Accounting;

use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class JournalPosting
{
    public static function validate(LedgerEntry $entry): void
    {
        if ($entry->status !== 'Reviewed' || $entry->posted_at || $entry->posted_by) {
            throw ValidationException::withMessages(['posting' => $entry->status === 'Posted' ? 'This journal has already been posted.' : 'Only Reviewed journals may be posted.']);
        }
        if (! $entry->reviewed_by || ! $entry->reviewed_at || $entry->reviewed_by === $entry->created_by) {
            throw ValidationException::withMessages(['posting' => 'Independent review is required before posting.']);
        }
        JournalEligibility::validate($entry);
        VoucherWriter::validate($entry);
        JournalEvidence::validate($entry);
        if (! $entry->review_digest || ! hash_equals($entry->review_digest, JournalReview::digest($entry))) {
            throw ValidationException::withMessages(['posting' => 'The review snapshot is missing or has changed. Return for correction and obtain a new independent review.']);
        }
    }

    public static function post(LedgerEntry $record): LedgerEntry
    {
        Gate::authorize('post', $record);
        Access::client($record->client_id);

        return AccountingTransaction::forClient($record->client_id, function () use ($record) {
            $entry = LedgerEntry::whereKey($record->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('post', $entry);
            Access::client($entry->client_id);
            self::validate($entry);
            // The sole application bypass of the model's posting-metadata guard.
            // Client -> entry lock order is shared with account/period writers.
            $changed = LedgerEntry::whereKey($entry->id)->where('status', 'Reviewed')->whereNull('posted_at')->whereNull('posted_by')
                ->update(['status' => 'Posted', 'posted_by' => auth()->id(), 'posted_at' => now(), 'updated_at' => now()]);
            abort_unless($changed === 1, 409, 'This journal has already been posted or changed.');
            $entry->refresh();
            Audit::record('post', 'ledger', $entry, 'Journal posted; reviewed by #'.$entry->reviewed_by.'; review SHA-256: '.$entry->review_digest.'.');

            return $entry;
        });
    }
}
