<?php

namespace App\Models\Concerns;

use App\Support\AccountCode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait HasAccountCode
{
    protected static function bootHasAccountCode(): void
    {
        static::saving(function ($model) {
            Validator::make($model->getAttributes(), [
                'code' => 'required|string', 'name' => 'required|string|max:255',
                'classification' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'])],
            ])->validate();
            $model->code = AccountCode::display($model->code);
            $model->code_key = AccountCode::key($model->code);
        });
    }
}
