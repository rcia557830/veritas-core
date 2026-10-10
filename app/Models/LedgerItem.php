<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LedgerItem extends Model
{
    protected $fillable = ['ledger_entry_id', 'account_name', 'debit', 'credit', 'account_id'];

    protected static function booted(): void
    {
        $protect = function (self $item) {
            $ids = array_filter([$item->ledger_entry_id, $item->getRawOriginal('ledger_entry_id')]);
            if (LedgerEntry::withTrashed()->whereIn('id', $ids)->where('status', 'Posted')->exists()) {
                throw ValidationException::withMessages(['entry' => 'Posted journal lines cannot be modified or deleted.']);
            }
        };
        static::saving(function (self $item) use ($protect) {
            $protect($item);
            if ($item->account_id !== null) {
                $entry = LedgerEntry::findOrFail($item->ledger_entry_id);
                $account = Account::find($item->account_id);
                if (! $account || $account->client_id !== $entry->client_id || ($item->client_id !== null && $item->client_id !== $entry->client_id)) {
                    throw ValidationException::withMessages(['account_id' => 'Journal accounts must belong to the entry client.']);
                }
                $item->client_id = $entry->client_id;
                // Validate the input before decimal casts can round it silently.
                $raw = $item->getAttributes();
                Money::cents($raw['debit'] ?? '0');
                Money::cents($raw['credit'] ?? '0');
            } elseif ($item->client_id !== null) {
                throw ValidationException::withMessages(['account_id' => 'Account and client references must both be present or both be absent.']);
            }
        });
        static::deleting($protect);
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function entry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
