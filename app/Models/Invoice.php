<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = ['invoice_number', 'client_id', 'invoice_date', 'due_date', 'tax', 'status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'tax' => 'decimal:2'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getSubtotalCentsAttribute(): int
    {
        return $this->items->sum(fn ($item) => $item->amount_cents);
    }

    public function getTotalCentsAttribute(): int
    {
        return $this->subtotal_cents + Money::cents($this->tax);
    }

    public function getPaidCentsAttribute(): int
    {
        return $this->payments->sum(fn ($p) => Money::cents($p->amount));
    }

    public function getBalanceCentsAttribute(): int
    {
        return $this->total_cents - $this->paid_cents;
    }

    public function getTotalAmountAttribute(): string
    {
        return Money::decimal($this->total_cents);
    }

    public function getAmountPaidAttribute(): string
    {
        return Money::decimal($this->paid_cents);
    }

    public function getBalanceAttribute(): string
    {
        return Money::decimal($this->balance_cents);
    }

    public function getDisplayStatusAttribute(): string
    {
        if (in_array($this->status, ['Draft', 'Cancelled'])) {
            return $this->status;
        }
        if ($this->balance_cents <= 0) {
            return 'Paid';
        }
        if ($this->due_date->lt(today())) {
            return 'Overdue';
        }

        return $this->paid_cents > 0 ? 'Partially Paid' : 'Open';
    }
}
