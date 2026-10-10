<?php

namespace App\Models\Concerns;

use App\Services\Accounting\AccountingTransaction;
use App\Services\Accounting\PeriodValidation;
use Illuminate\Validation\ValidationException;

trait ValidatesAccountingDates
{
    public function save(array $options = [])
    {
        $clientId = $this->exists ? (int) $this->getRawOriginal('client_id') : (int) $this->client_id;

        return AccountingTransaction::forClient($clientId, function () use ($options) {
            if ($this->exists) {
                if ($this->isDirty(['id', 'client_id'])) {
                    throw ValidationException::withMessages(['client_id' => 'Accounting identifiers and ownership cannot change.']);
                }
                // Validate the effective update against the latest locked row,
                // not unchanged dates from a stale form or model instance.
                $changes = $this->getDirty();
                $stored = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $this->setRawAttributes($stored->getAttributes(), true);
                $this->forceFill($changes);
            }
            PeriodValidation::validate($this);

            return parent::save($options);
        });
    }
}
