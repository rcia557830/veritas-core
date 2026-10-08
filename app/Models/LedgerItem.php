<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerItem extends Model
{
    protected $fillable = ['ledger_entry_id', 'account_name', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function entry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }
}
