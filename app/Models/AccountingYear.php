<?php

namespace App\Models;

use App\Models\Concerns\ValidatesAccountingDates;
use Illuminate\Database\Eloquent\Model;

class AccountingYear extends Model
{
    use ValidatesAccountingDates;

    protected $fillable = ['client_id', 'label', 'starts_on', 'ends_on'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function periods()
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}
