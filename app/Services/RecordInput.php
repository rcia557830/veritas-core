<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordInput
{
    // Field-level checks also apply to forged ordinary CRUD requests, not only workflow endpoints.
    public static function authorize(string $module, User $user, array $input, $record = null): void
    {
        $changed = function (string $key) use ($input, $record): bool {
            if (! array_key_exists($key, $input)) {
                return false;
            }
            abort_if(is_array($input[$key]) || is_object($input[$key]), 422, 'Invalid field value.');
            $old = $record?->$key;
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }

            return (string) ($input[$key] ?? '') !== (string) ($old ?? '');
        };
        if (in_array($module, ['ledger', 'billing']) && array_key_exists('status', $input)) {
            abort(403, 'Use the authorized workflow action to change status.');
        }
        if ($module === 'clients') {
            if ($changed('assigned_to')) {
                Gate::authorize('client.assign');
            }
            if ($changed('status') && ($input['status'] ?? '') === 'Archived') {
                Gate::authorize('client.archive');
            }
            if ($changed('status') && $record?->status === 'Archived') {
                Gate::authorize('client.restore');
            }
        }
        if ($module === 'documents') {
            if ($changed('status') && in_array($record?->status, ['Reviewed', 'Approved']) && ! in_array($input['status'], ['Reviewed', 'Approved'])) {
                Gate::authorize('validate', $record);
                throw ValidationException::withMessages(['status' => 'Use Validate document on the document page to explicitly reopen review before replacing its attachment.']);
            }
            if (isset($input['file'])) {
                Gate::authorize('document.upload');
            }
            if ($changed('status')) {
                $permission = match ($input['status'] ?? '') {
                    'Approved' => 'document.approve','Rejected' => 'document.reject',
                    'Under Review','Reviewed','Needs Clarification' => 'document.validate',default => null
                };
                if ($permission) {
                    Gate::authorize($permission);
                }
            }
        }
        if ($module === 'compliance') {
            if ($changed('assigned_to')) {
                Gate::authorize('compliance.assign');
            }
            if ($changed('status') && ($input['status'] ?? '') === 'Filed') {
                Gate::authorize('compliance.file');
            }
            if ($user->hasRole('bookkeeper')) {
                foreach (['client_id', 'agency', 'requirement', 'reporting_period', 'due_date', 'filed_date', 'reference_number', 'assigned_to'] as $key) {
                    abort_if($changed($key), 403, 'Bookkeepers may update preparation status and notes only.');
                }
            }
        }
        if ($module === 'knowledge' && $changed('status')) {
            if (($input['status'] ?? '') === 'Published') {
                Gate::authorize('knowledge.publish');
            }
            if (($input['status'] ?? '') === 'Archived') {
                Gate::authorize('knowledge.archive');
            }
        }
    }
}
