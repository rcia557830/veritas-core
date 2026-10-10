<?php

namespace App\Models;

use App\Services\Accounting\TemplateTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AccountTemplate extends Model
{
    protected $fillable = ['name', 'version', 'is_active'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }

    public function save(array $options = [])
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return TemplateTransaction::run((int) $this->getRawOriginal('id'), function ($stored) use ($options) {
            if ($this->isDirty('id')) {
                throw ValidationException::withMessages(['template' => 'Template identifiers cannot change.']);
            }
            if (($this->name !== $stored->name || (int) $this->version !== $stored->version) && $stored->items()->whereHas('accounts')->exists()) {
                throw ValidationException::withMessages(['version' => 'Used template versions cannot be renamed or renumbered. Create a new version.']);
            }

            return parent::save($options);
        });
    }

    public function items()
    {
        return $this->hasMany(AccountTemplateItem::class);
    }
}
