<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\LedgerEntry;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class JournalWriter
{
    public static function save(array $data, ?LedgerEntry $record = null, bool $voucherContext = false): LedgerEntry
    {
        Gate::authorize($record ? 'update' : 'create', $record ?? LedgerEntry::class);
        Gate::authorize('viewAny', Account::class);
        $client = Access::client((int) $data['client_id']);
        if ($record && $record->client_id !== $client->id) {
            throw ValidationException::withMessages(['client_id' => 'A saved journal cannot be transferred to another client.']);
        }

        return AccountingTransaction::forClient($client->id, function () use ($data, $record, $client, $voucherContext) {
            $entry = $record ? LedgerEntry::whereKey($record->id)->lockForUpdate()->firstOrFail() : new LedgerEntry(['client_id' => $client->id, 'created_by' => auth()->id(), 'status' => 'Draft']);
            if ($entry->exists && $entry->voucher()->exists() && ! $voucherContext) {
                throw ValidationException::withMessages(['voucher' => 'Use the voucher editor to keep voucher details and accounting lines consistent.']);
            }
            Gate::authorize($record ? 'update' : 'create', $record ? $entry : LedgerEntry::class);
            if (! empty($data['accounting_period_id'])) {
                $period = AccountingPeriod::whereKey($data['accounting_period_id'])->where('client_id', $client->id)->first();
                if (! $period || $period->status !== 'Open' || $data['transaction_date'] < $period->starts_on || $data['transaction_date'] > $period->ends_on) {
                    throw ValidationException::withMessages(['accounting_period_id' => 'Choose an open same-client period containing the transaction date.']);
                }
            }
            $lines = [];
            $signatures = [];
            $oldLines = $entry->exists ? $entry->items()->get()->keyBy('id') : collect();
            $seenIds = [];
            foreach ($data['items'] as $index => $item) {
                $account = Account::whereKey($item['account_id'])->where('client_id', $client->id)->where('is_active', true)->first();
                if (! $account) {
                    throw ValidationException::withMessages(["items.$index.account_id" => 'Choose an active account belonging to this client.']);
                }
                Gate::authorize('view', $account);
                $debit = Money::cents($item['debit']);
                $credit = Money::cents($item['credit']);
                if (($debit > 0) === ($credit > 0)) {
                    throw ValidationException::withMessages(["items.$index.debit" => 'Use exactly one positive debit or credit per line.']);
                }
                $signature = $account->id.':'.$debit.':'.$credit;
                if (in_array($signature, $signatures, true)) {
                    throw ValidationException::withMessages(["items.$index.account_id" => 'Duplicate account and amount line. Combine identical lines or correct the entry.']);
                }
                $signatures[] = $signature;
                $id = $item['id'] ?? null;
                if ($id && (! $oldLines->has($id) || in_array((int) $id, $seenIds, true))) {
                    throw ValidationException::withMessages(["items.$index.id" => 'Invalid or duplicate journal line identifier.']);
                }
                if ($id) {
                    $seenIds[] = (int) $id;
                }
                $lines[] = ['id' => $id, 'account_id' => $account->id, 'account_name' => $id && $oldLines[$id]->account_id === $account->id ? $oldLines[$id]->account_name : $account->name, 'debit' => Money::decimal($debit), 'credit' => Money::decimal($credit)];
            }
            $entry->fill(array_intersect_key($data, array_flip(['transaction_date', 'reference_number', 'description', 'notes', 'accounting_period_id'])));
            $entry->reviewed_by = null;
            $entry->reviewed_at = null;
            $entry->review_digest = null;
            $entry->save();
            foreach ($oldLines as $old) {
                if (! in_array($old->id, $seenIds, true)) {
                    $old->delete();
                }
            }
            foreach ($lines as $line) {
                $id = $line['id'];
                unset($line['id']);
                $id ? $oldLines[$id]->update($line) : $entry->items()->create($line);
            }
            JournalEvidence::sync($entry, $data['document_ids'] ?? [], $data['refresh_document_ids'] ?? []);
            Audit::record($record ? 'updated' : 'created', 'ledger', $entry, 'Structured journal saved; status: '.$entry->status.'. Lines: '.json_encode($lines));

            return $entry;
        });
    }

    public static function transition(LedgerEntry $record, string $action, ?string $notes): LedgerEntry
    {
        abort_unless(in_array($action, ['submit', 'review', 'return'], true), 422);

        return AccountingTransaction::forClient($record->client_id, function () use ($record, $action, $notes) {
            $entry = LedgerEntry::whereKey($record->id)->lockForUpdate()->firstOrFail();
            Gate::authorize(match ($action) {
                'submit' => 'submit', 'review' => 'approve', default => 'review'
            }, $entry);
            if ($action === 'return') {
                abort_unless(in_array($entry->status, ['For Review', 'Reviewed']), 409);
                $entry->update(['status' => 'Needs Correction', 'notes' => $notes ?? $entry->notes, 'reviewed_by' => null, 'reviewed_at' => null, 'review_digest' => null]);
            } else {
                abort_unless($action === 'submit' ? in_array($entry->status, ['Draft', 'Needs Correction']) : $entry->status === 'For Review', 409);
                Gate::authorize('viewAny', Account::class);
                Access::client($entry->client_id);
                JournalEligibility::validate($entry);
                VoucherWriter::validate($entry);
                JournalEvidence::validate($entry);
                $entry->update(['status' => $action === 'submit' ? 'For Review' : 'Reviewed', 'notes' => $notes ?? $entry->notes, 'reviewed_by' => $action === 'review' ? auth()->id() : null, 'reviewed_at' => $action === 'review' ? now() : null]);
            }
            $entry->review_digest = $action === 'review' ? JournalReview::digest($entry) : null;
            $entry->save();
            Audit::record($action, 'ledger', $entry, 'Journal status changed to '.$entry->status.'. '.($notes ?? ''));

            return $entry;
        });
    }
}
