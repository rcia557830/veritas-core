<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class LedgerEntry extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'transaction_date', 'reference_number', 'description', 'status', 'notes', 'created_by', 'reviewed_by', 'reviewed_at', 'accounting_period_id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'reviewed_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $protect = function (self $entry) {
            if ($entry->exists && self::withTrashed()->whereKey($entry->getRawOriginal('id'))->where('status', 'Posted')->exists()) {
                throw ValidationException::withMessages(['entry' => 'Posted entries cannot be modified or deleted.']);
            }
        };
        static::saving(function (self $entry) use ($protect) {
            $protect($entry);
            if ($entry->status === 'Posted' || ($entry->isDirty(['posted_by', 'posted_at']) && ($entry->exists || $entry->posted_by !== null || $entry->posted_at !== null))) {
                throw ValidationException::withMessages(['posting' => 'Use the authorized journal posting action.']);
            }
            if ($entry->accounting_period_id !== null) {
                $period = AccountingPeriod::whereKey($entry->accounting_period_id)->where('client_id', $entry->client_id)->first();
                $date = $entry->transaction_date?->toDateString();
                if (! $period || ! $date || $date < $period->starts_on || $date > $period->ends_on) {
                    throw ValidationException::withMessages(['accounting_period_id' => 'Choose a period for this client containing the transaction date.']);
                }
            }
        });
        static::deleting($protect);
        static::restoring($protect);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function voucher()
    {
        return $this->hasOne(Voucher::class);
    }

    // Future ledger/report queries must use these journal rows and their items,
    // rather than copying transactions into a second accounting store.
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'Posted');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(LedgerItem::class);
    }

    public function evidence()
    {
        return $this->hasMany(JournalDocument::class);
    }

    public function getIsLegacyAttribute(): bool
    {
        return $this->exists && (! $this->items()->exists() || $this->items()->where(fn ($q) => $q->whereNull('account_id')->orWhereNull('client_id'))->exists());
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getTotalDebitAttribute()
    {
        return Money::decimal($this->items->sum(fn ($line) => Money::storedCents($line->debit)));
    }

    public function getTotalCreditAttribute()
    {
        return Money::decimal($this->items->sum(fn ($line) => Money::storedCents($line->credit)));
    }
}
