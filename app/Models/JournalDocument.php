<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class JournalDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detached_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evidence) {
            if (! in_array($evidence->entry?->status, ['Draft', 'Needs Correction'])) {
                throw ValidationException::withMessages(['documents' => 'Evidence can only be attached to editable journals.']);
            }
        });
        static::updating(function (self $evidence) {
            if (array_diff(array_keys($evidence->getDirty()), ['detached_at', 'updated_at']) || $evidence->getRawOriginal('detached_at') !== null || $evidence->detached_at === null || ! in_array($evidence->entry->status, ['Draft', 'Needs Correction'])) {
                throw ValidationException::withMessages(['documents' => 'Journal evidence snapshots are immutable.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['documents' => 'Historical journal evidence cannot be deleted.']));
    }

    public function entry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id')->withTrashed();
    }

    public function document()
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }
}
