<?php

namespace App\Models;

use App\Models\Concerns\HasAccountCode;
use App\Services\Accounting\TemplateManager;
use App\Services\Accounting\TemplateTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AccountTemplateItem extends Model
{
    use HasAccountCode;

    protected $fillable = ['account_template_id', 'code', 'name', 'classification', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function save(array $options = [])
    {
        $id = (int) ($this->exists ? $this->getRawOriginal('account_template_id') : $this->account_template_id);

        return TemplateTransaction::run($id, function ($template) use ($id, $options) {
            if ((int) $this->account_template_id !== $id || ($this->exists && $this->isDirty('id'))) {
                throw ValidationException::withMessages(['template' => 'Template item identifiers cannot change.']);
            }
            TemplateManager::editable($template);

            return parent::save($options);
        });
    }

    public function delete()
    {
        return TemplateTransaction::run((int) $this->getRawOriginal('account_template_id'), function ($template) {
            TemplateManager::editable($template);

            return parent::delete();
        });
    }

    public function template()
    {
        return $this->belongsTo(AccountTemplate::class, 'account_template_id');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}
