<?php

namespace App\Models;

use App\Models\Concerns\ValidatesAccountingDates;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use ValidatesAccountingDates;

    protected $fillable = ['client_id', 'accounting_year_id', 'label', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function year()
    {
        return $this->belongsTo(AccountingYear::class, 'accounting_year_id');
    }

    public function entries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
