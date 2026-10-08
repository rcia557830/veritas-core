<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LedgerEntry extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'transaction_date', 'reference_number', 'description', 'status', 'notes', 'created_by', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(LedgerItem::class);
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
        return $this->items->sum('debit');
    }

    public function getTotalCreditAttribute()
    {
        return $this->items->sum('credit');
    }
}
