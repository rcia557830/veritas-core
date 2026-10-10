<?php

namespace App\Models;

use App\Models\Concerns\HasAccountCode;
use App\Services\Accounting\AccountingTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Account extends Model
{
    use HasAccountCode;

    protected $fillable = ['client_id', 'account_template_item_id', 'code', 'name', 'classification', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function save(array $options = [])
    {
        $clientId = $this->exists ? (int) $this->getRawOriginal('client_id') : (int) $this->client_id;

        return AccountingTransaction::forClient($clientId, function () use ($options, $clientId) {
            if ($this->exists) {
                $stored = self::whereKey($this->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
                if ((int) $this->client_id !== $clientId || $this->id !== $stored->id) {
                    throw ValidationException::withMessages(['client_id' => 'Account identifiers and ownership cannot change.']);
                }
                if ($stored->items()->whereHas('entry', fn ($q) => $q->withTrashed()->where('status', 'Posted'))->exists()) {
                    foreach (['code', 'name', 'classification', 'account_template_item_id'] as $field) {
                        if ((string) $stored->$field !== (string) $this->$field) {
                            throw ValidationException::withMessages([$field => 'Posted accounting history protects this account. Deactivate it instead.']);
                        }
                    }
                }
            }

            return parent::save($options);
        });
    }

    public function delete()
    {
        return AccountingTransaction::forClient((int) $this->client_id, function () {
            if ($this->items()->exists()) {
                throw ValidationException::withMessages(['account' => 'Referenced accounts cannot be deleted. Deactivate the account instead.']);
            }

            return parent::delete();
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function templateItem()
    {
        return $this->belongsTo(AccountTemplateItem::class, 'account_template_item_id');
    }

    public function items()
    {
        return $this->hasMany(LedgerItem::class);
    }
}
