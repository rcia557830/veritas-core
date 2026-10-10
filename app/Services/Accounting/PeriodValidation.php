<?php

namespace App\Services\Accounting;

use App\Models\AccountingYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PeriodValidation
{
    public static function validate(Model $record): void
    {
        Validator::make($record->getAttributes(), [
            'label' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
        ])->validate();
        if ($record->exists && $record->isDirty(['id', 'client_id'])) {
            throw ValidationException::withMessages(['client_id' => 'Accounting identifiers and ownership cannot change.']);
        }
        $overlap = $record->newQuery()->where('client_id', $record->client_id)
            ->when($record->exists, fn ($q) => $q->where('id', '!=', $record->getRawOriginal('id')))
            ->where('starts_on', '<=', $record->ends_on)->where('ends_on', '>=', $record->starts_on)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Accounting date ranges for a client must not overlap.']);
        }
        if ($record instanceof AccountingYear) {
            if ($record->exists && $record->periods()->where(fn ($q) => $q->where('starts_on', '<', $record->starts_on)->orWhere('ends_on', '>', $record->ends_on))->exists()) {
                throw ValidationException::withMessages(['ends_on' => 'The fiscal year must contain every existing period.']);
            }
        } else {
            $year = AccountingYear::whereKey($record->accounting_year_id)->where('client_id', $record->client_id)->first();
            if (! $year || $record->starts_on < $year->starts_on || $record->ends_on > $year->ends_on) {
                throw ValidationException::withMessages(['accounting_year_id' => 'The period must belong to the same client and fall within its fiscal year.']);
            }
            if ($record->exists && $record->isDirty(['starts_on', 'ends_on', 'accounting_year_id']) && $record->entries()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'A referenced accounting period cannot be moved.']);
            }
        }
    }
}
