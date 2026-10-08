<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\Audit;
use App\Services\Notify;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LedgerController extends ModuleController
{
    protected string $module = 'ledger';

    public function transition(Request $request, $record)
    {
        Gate::authorize('view', $record);
        $data = $request->validate(['action' => 'required|in:submit,review,return', 'notes' => 'nullable|string|max:3000']);
        DB::transaction(function () use ($data, $record) {
            $entry = LedgerEntry::lockForUpdate()->findOrFail($record->id);
            $action = $data['action'];
            Gate::authorize(match ($action) {
                'submit' => 'submit','review' => 'approve',default => 'review'
            }, $entry);
            if ($action === 'submit') {
                abort_unless(in_array($entry->status, ['Draft', 'Needs Correction']), 409);
                $debit = $entry->items->sum(fn ($i) => Money::cents($i->debit));
                $credit = $entry->items->sum(fn ($i) => Money::cents($i->credit));
                if ($debit <= 0 || $debit !== $credit) {
                    throw ValidationException::withMessages(['items' => 'Total debit must equal total credit before submission.']);
                }
                $entry->update(['status' => 'For Review', 'reviewed_by' => null, 'reviewed_at' => null]);
            } else {
                abort_unless($entry->status === 'For Review', 409);
                if ($entry->created_by === auth()->id()) {
                    throw ValidationException::withMessages(['action' => 'A different employee must review this transaction.']);
                }
                $entry->update(['status' => $action === 'review' ? 'Reviewed' : 'Needs Correction', 'notes' => $data['notes'] ?? $entry->notes, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
            }
            Audit::record($action, 'ledger', $entry, 'Ledger status changed to '.$entry->status.'.');
        });
        Notify::record($record->fresh(), 'ledger', 'Ledger review: '.$record->description);

        return back()->with('success', 'Ledger workflow updated.');
    }
}
