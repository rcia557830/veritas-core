<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Voucher;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VoucherWriter
{
    public static function save(array $input, ?Voucher $voucher = null): Voucher
    {
        Gate::authorize($voucher ? 'update' : 'create', $voucher?->journal ?? LedgerEntry::class);
        Gate::authorize('viewAny', Account::class);
        $data = Validator::make($input, [
            'client_id' => 'required|integer|min:1', 'type' => ['required', Rule::in(array_keys(Voucher::TYPES))],
            'creation_token' => $voucher ? 'sometimes|uuid' : 'required|uuid',
            'transaction_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'accounting_period_id' => 'required|integer|min:1', 'description' => 'required|string|max:255', 'notes' => 'nullable|string|max:30000',
            'party' => 'nullable|string|max:255', 'cash_account_id' => 'nullable|integer|min:1', 'amount' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'check_number' => 'nullable|string|max:255', 'check_date' => 'nullable|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31',
            'items' => 'required|array|list|min:2|max:100', 'items.*' => 'required|array:id,account_id,debit,credit',
            'items.*.id' => 'nullable|integer|min:1|distinct', 'items.*.account_id' => 'required|integer|min:1',
            'items.*.debit' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'], 'items.*.credit' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'document_ids' => 'sometimes|array|list|max:100', 'document_ids.*' => 'required|integer|min:1|distinct',
            'refresh_document_ids' => 'sometimes|array|list|max:100', 'refresh_document_ids.*' => 'required|integer|min:1|distinct',
            'reference' => 'prohibited', 'reference_number' => 'prohibited', 'ledger_entry_id' => 'prohibited', 'sequence' => 'prohibited', 'status' => 'prohibited',
        ])->validate();
        $client = Access::client((int) $data['client_id']);
        if ($voucher && ($voucher->client_id !== $client->id || $voucher->type !== $data['type'])) {
            throw ValidationException::withMessages(['voucher' => 'The client and voucher type are fixed when a reference is issued.']);
        }

        return AccountingTransaction::forClient($client->id, function () use ($data, $voucher, $client) {
            $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! $voucher) {
                $existing = Voucher::where('client_id', $client->id)->where('creation_token', $data['creation_token'])->first();
                if ($existing) {
                    Gate::authorize('view', $existing->journal);
                    if (! hash_equals($existing->creation_digest, $digest)) {
                        throw ValidationException::withMessages(['voucher' => 'This creation request was already used with different data. Start a new voucher.']);
                    }

                    return $existing;
                }
            }
            $new = ! $voucher;
            $voucher = $voucher ? Voucher::findOrFail($voucher->id) : new Voucher;
            $sequence = $new ? Money::addCents((int) Voucher::where('client_id', $client->id)->where('type', $data['type'])->max('sequence'), 1) : $voucher->sequence;
            $reference = $new ? $data['type'].'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) : $voucher->reference;
            $entry = JournalWriter::save(array_replace($data, ['reference_number' => $reference]), $new ? null : $voucher->journal, voucherContext: true);
            if ($new) {
                $voucher->forceFill(['client_id' => $client->id, 'ledger_entry_id' => $entry->id, 'type' => $data['type'], 'sequence' => $sequence, 'reference' => $reference,
                    'creation_token' => $data['creation_token'], 'creation_digest' => $digest]);
            }
            $check = isset($data['check_number']) ? trim($data['check_number']) : null;
            $voucher->fill(['party' => isset($data['party']) ? trim($data['party']) : null, 'cash_account_id' => $data['cash_account_id'] ?? null,
                'amount' => isset($data['amount']) ? Money::decimal(Money::cents($data['amount'])) : null,
                'check_number' => $check ?: null, 'check_key' => $check ? hash('sha256', strtoupper($check)) : null, 'check_date' => $data['check_date'] ?? null]);
            if ($voucher->check_key && Voucher::where('client_id', $client->id)->where('cash_account_id', $voucher->cash_account_id)->where('check_key', $voucher->check_key)->when(! $new, fn ($q) => $q->where('id', '!=', $voucher->id))->exists()) {
                throw ValidationException::withMessages(['check_number' => 'This check number is already associated with a voucher for this client and cash/bank account.']);
            }
            self::validateMetadata($voucher, $entry);
            JournalEligibility::validate($entry);
            $voucher->save();
            Audit::record($new ? 'voucher.created' : 'voucher.updated', 'ledger', $entry, 'Voucher '.$reference.' ('.$data['type'].') saved. '.json_encode($voucher->only(['party', 'cash_account_id', 'amount', 'check_number', 'check_date'])));

            return $voucher->fresh();
        });
    }

    public static function validate(LedgerEntry $entry): void
    {
        if ($voucher = $entry->voucher()->first()) {
            self::validateMetadata($voucher, $entry);
        }
    }

    private static function validateMetadata(Voucher $voucher, LedgerEntry $entry): void
    {
        if ($voucher->type === 'JV') {
            if ($voucher->party || $voucher->cash_account_id || $voucher->amount !== null || $voucher->check_number || $voucher->check_date) {
                throw ValidationException::withMessages(['voucher' => 'Journal Vouchers use journal lines only; receipt, payment and check fields must be empty.']);
            }

            return;
        }
        $cash = Account::whereKey($voucher->cash_account_id)->where('client_id', $entry->client_id)->where('classification', 'Asset')->where('is_active', true)->first();
        if (! $voucher->party || ! $cash || $voucher->amount === null || Money::cents($voucher->amount) <= 0) {
            throw ValidationException::withMessages(['voucher' => 'A payer/payee, active same-client asset account designated as cash/bank, and positive amount are required.']);
        }
        if ($voucher->type === 'CV' ? (! $voucher->check_number || ! $voucher->check_date) : ($voucher->check_number || $voucher->check_date)) {
            throw ValidationException::withMessages(['check_number' => 'Check number and date are required only for Check Vouchers.']);
        }
        $side = $voucher->type === 'CR' ? 'debit' : 'credit';
        $opposite = $side === 'debit' ? 'credit' : 'debit';
        $total = 0;
        foreach ($entry->items()->where('account_id', $cash->id)->get() as $line) {
            if (Money::storedCents($line->$opposite) !== 0) {
                throw ValidationException::withMessages(['items' => 'The designated cash/bank account must be debited for receipts or credited for payments.']);
            }
            $total = Money::addCents($total, Money::storedCents($line->$side));
        }
        if ($total !== Money::cents($voucher->amount)) {
            throw ValidationException::withMessages(['amount' => 'Voucher amount must equal the designated cash/bank account movements exactly.']);
        }
    }
}
