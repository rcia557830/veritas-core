<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function getAmountCentsAttribute(): int
    {
        return (int) round(Money::cents($this->unit_price) * Money::cents($this->quantity) / 100);
    }

    public function getAmountAttribute(): string
    {
        return Money::decimal($this->amount_cents);
    }
}
