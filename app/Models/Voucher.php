<?php

namespace App\Models;

use App\Services\Accounting\AccountingTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Voucher extends Model
{
    public const TYPES = ['JV' => 'Journal Voucher', 'CV' => 'Check Voucher', 'CR' => 'Cash Receipt', 'CD' => 'Cash Disbursement'];

    protected $fillable = ['party', 'cash_account_id', 'amount', 'check_number', 'check_key', 'check_date'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'check_date' => 'date'];
    }

    public function journal()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function save(array $options = [])
    {
        $clientId = (int) ($this->exists ? $this->getRawOriginal('client_id') : $this->client_id);

        return AccountingTransaction::forClient($clientId, function () use ($options) {
            $journal = LedgerEntry::whereKey($this->exists ? $this->getRawOriginal('ledger_entry_id') : $this->ledger_entry_id)->lockForUpdate()->firstOrFail();
            if (! in_array($journal->status, $this->exists ? ['Draft', 'Needs Correction'] : ['Draft'])) {
                throw ValidationException::withMessages(['voucher' => 'Only Draft or Needs Correction vouchers may be edited.']);
            }
            if ($this->exists && $this->isDirty(['id', 'client_id', 'ledger_entry_id', 'type', 'sequence', 'reference', 'creation_token', 'creation_digest'])) {
                throw ValidationException::withMessages(['voucher' => 'Issued voucher identity and reference cannot change.']);
            }

            return parent::save($options);
        });
    }

    public function delete()
    {
        throw ValidationException::withMessages(['voucher' => 'Issued vouchers and references must be retained. Cancellation rules are not configured.']);
    }
}
