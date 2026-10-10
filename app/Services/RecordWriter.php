<?php

namespace App\Services;

use App\Http\Requests\RecordRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\JournalWriter;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordWriter
{
    public function save(string $module, RecordRequest $request, $record = null)
    {
        $data = $request->validated();
        if ($module === 'compliance' && ! $request->user()->hasRole('bookkeeper')) {
            $data = $this->prepareComplianceDeadlines($data, $record);
        }
        if ($module === 'ledger') {
            return JournalWriter::save($data, $record);
        }
        $path = null;
        if ($record) {
            Gate::authorize('update', $record);
        }
        if (! $record) {
            Gate::authorize('create', Modules::get($module)['model']);
        }
        RecordInput::authorize($module, $request->user(), $data, $record);
        if (isset($data['client_id'])) {
            Access::client((int) $data['client_id']);
        }
        if ($module === 'clients') {
            if (! $record) {
                $data['created_by'] = $request->user()->id;
            }
            if (! $request->user()->hasPermission('client.assign')) {
                $data['assigned_to'] = $request->user()->id;

            }
        }
        if ($module === 'compliance' && ! empty($data['assigned_to'])) {
            $client = Access::client((int) ($data['client_id'] ?? $record->client_id));
            $assignee = User::findOrFail($data['assigned_to']);
            if (! $assignee->hasAnyRole(['owner', 'office-manager']) && $client->assigned_to !== $assignee->id) {
                throw ValidationException::withMessages(['assigned_to' => 'Assign this requirement to the client’s assigned bookkeeper, Office Manager or Owner.']);
            }
        }
        if ($module === 'compliance' && ! $request->user()->hasRole('bookkeeper') && ($data['status'] ?? '') !== 'Filed') {
            $data['filed_date'] = null;
        }
        $items = $data['items'] ?? [];
        unset($data['items'],$data['file']);
        if ($module === 'billing') {
            $total = Money::cents($data['tax']);
            foreach ($items as $item) {
                $total += (int) round(Money::cents($item['quantity']) * Money::cents($item['unit_price']) / 100);
            }
            if ($total <= 0 || $total > 99999999999) {
                throw ValidationException::withMessages(['items' => 'Invoice total must be positive and at most PHP 999,999,999.99.']);
            }
        }
        if ($module === 'knowledge') {
            $data['tags'] = array_values(array_filter(array_map('trim', explode(',', $data['tags'] ?? ''))));
            if (! $record) {
                $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(8));
                $data['author_id'] = $request->user()->id;
            }
        }
        if (! $record) {
            $prefix = ['clients' => ['client_code', 'CL'], 'documents' => ['document_number', 'DOC'], 'billing' => ['invoice_number', 'INV']][$module] ?? null;
            if ($prefix) {
                $data[$prefix[0]] = $prefix[1].'-'.now()->format('Y').'-'.Str::upper(Str::random(8));
            }
            if (in_array($module, ['ledger', 'billing', 'compliance'])) {
                $data['created_by'] = $request->user()->id;
            }
            if ($module === 'documents') {
                $data['uploaded_by'] = $request->user()->id;
            }
        }
        $deadlineChanged = false;
        try {
            $saved = DB::transaction(function () use ($module, $record, $data, $items, $request, &$path, &$deadlineChanged) {
                $model = Modules::get($module)['model'];
                $previousStatus = null;
                $previousDueDate = null;
                $previousSubmission = null;
                $previousClient = null;
                if ($record) {
                    $record = $model::lockForUpdate()->findOrFail($record->id);
                    Gate::authorize('update', $record);
                    RecordInput::authorize($module, $request->user(), $data, $record);
                    $previousStatus = $record->status;
                    $previousDueDate = $record->due_date?->toDateString();
                    $previousSubmission = $record->submission_deadline?->toDateString();
                    if ($module === 'clients') {
                        $previousClient = $record->only($this->clientTrackedFields());
                    }
                } else {
                    Gate::authorize('create', $model);
                }
                $oldPath = $record?->file_path;
                if ($module === 'documents' && $request->hasFile('file')) {
                    // Check the locked state, not the potentially stale route-bound record.
                    if ($record && (in_array($record->status, ['Reviewed', 'Approved']) || in_array($data['status'] ?? $record->status, ['Reviewed', 'Approved']))) {
                        throw ValidationException::withMessages(['file' => 'Reopen review using Validate document before replacing the attachment. Save the replacement before verifying it in a separate action.']);
                    }
                    $file = $request->file('file');
                    $path = $file->store('documents', 'local');
                    $data['file_path'] = $path;
                    $data['original_file_name'] = $file->getClientOriginalName();
                    $data['mime_type'] = $file->getMimeType();
                }
                if ($record) {
                    $record->update($data);
                } else {
                    $record = $model::create($data);
                }
                if ($module === 'compliance' && $record) {
                    $newDueDate = $record->due_date?->toDateString();
                    $newSubmission = $record->submission_deadline?->toDateString();
                    if ($previousDueDate !== $newDueDate || $previousSubmission !== $newSubmission) {
                        $deadlineChanged = true;
                        Audit::record('compliance.deadline-changed', $module, $record, 'Deadline adjusted by '.($request->user()->name ?? 'staff').': filing '.($previousDueDate ?? '—').' → '.($newDueDate ?? '—').'; submission '.($previousSubmission ?? '—').' → '.($newSubmission ?? '—').'.');
                    }
                }
                if ($path && $oldPath) {
                    // Retain the original privately; the audit reference preserves traceability.
                    Audit::record('document.attachment-replaced', $module, $record, 'Attachment replaced; retained original: '.$oldPath.'; replacement: '.$path.'.');
                }
                if ($module === 'billing') {
                    $record->items()->delete();
                    $record->items()->createMany($items);
                }
                Audit::record($record->wasRecentlyCreated ? 'created' : 'updated', $module, $record, ucfirst(Modules::get($module)['singular']).' saved; status: '.($record->status ?? '').'.');

                if ($module === 'clients' && ! $record->wasRecentlyCreated && $previousClient !== null) {
                    $diff = $this->clientChangeSummary($previousClient, $record);
                    if ($diff !== '') {
                        Audit::record('client.updated', 'clients', $record, 'Client details changed: '.$diff.'.');
                    }
                }

                if ($previousStatus !== $record->status) {
                    if ($module === 'documents' && $record->status !== 'Submitted') {
                        Audit::record('document.validated', $module, $record, 'Document decision: '.$record->status.'.');
                    }
                    if ($module === 'compliance' && $record->status === 'Filed') {
                        Audit::record('compliance.filed', $module, $record, 'Requirement filed; reference: '.$record->reference_number.'.');
                    }
                    if ($module === 'knowledge' && $record->status === 'Published') {
                        Audit::record('knowledge.published', $module, $record, 'Knowledge article published.');
                    }
                }

                return $record;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }
        if ($module === 'documents') {
            Notify::record($saved, 'documents', 'Document '.$saved->status.': '.$saved->title);
        }
        if ($module === 'compliance') {
            $title = $deadlineChanged ? 'Compliance deadline changed: '.$saved->requirement : 'Compliance assignment: '.$saved->requirement;
            Notify::record($saved, 'compliance', $title);
        }

        return $saved;
    }

    private function clientTrackedFields(): array
    {
        return ['business_name', 'business_type', 'contact_person', 'email', 'phone', 'tin', 'address', 'registration_status', 'business_license_status', 'status', 'notes', 'assigned_to'];
    }

    private function clientChangeSummary(array $before, Client $after): string
    {
        $changes = [];
        foreach ($this->clientTrackedFields() as $field) {
            $old = (string) ($before[$field] ?? '');
            $new = (string) ($after->{$field} ?? '');
            if ($old === $new) {
                continue;
            }
            if ($field === 'assigned_to') {
                $oldLabel = $old !== '' ? User::find($old)?->name ?? 'Unknown' : 'Unassigned';
                $newLabel = $after->assignee?->name ?? 'Unassigned';
                $changes[] = 'assigned_to "'.$oldLabel.'" → "'.$newLabel.'"';
                continue;
            }
            $changes[] = $field.' "'.($old === '' ? '—' : $old).'" → "'.($new === '' ? '—' : $new).'"';
        }

        return implode('; ', $changes);
    }

    /**
     * Resolve and persist the internal client submission deadline for a
     * compliance record. A stored (manually overridden) deadline is preserved
     * when the official filing deadline changes; otherwise the submission
     * deadline is recalculated from the filing deadline.
     */
    private function prepareComplianceDeadlines(array $data, $record): array
    {
        $filingRaw = $data['due_date'] ?? ($record ? $record->due_date?->toDateString() : null);
        if (empty($filingRaw)) {
            return $data;
        }
        $filing = Carbon::parse($filingRaw, 'Asia/Manila')->startOfDay();
        $auto = DeadlineCalculator::submissionDeadline($filing);

        $submitted = $data['submission_deadline'] ?? null;
        $hasManualInput = $submitted !== null && $submitted !== '';

        if ($hasManualInput) {
            $submission = Carbon::parse($submitted, 'Asia/Manila')->startOfDay();
            if ($submission->gt($filing)) {
                throw ValidationException::withMessages(['submission_deadline' => 'The internal submission deadline must be on or before the official filing deadline.']);
            }
            $isOverride = $submission->ne($auto);
            if ($isOverride && empty(trim((string) ($data['submission_deadline_override_reason'] ?? '')))) {
                throw ValidationException::withMessages(['submission_deadline_override_reason' => 'Provide a reason for overriding the automatic submission deadline.']);
            }
            $data['submission_deadline'] = $submission->toDateString();
            $data['submission_deadline_is_manual'] = $isOverride;
        } elseif ($record && (bool) $record->submission_deadline_is_manual && $record->getRawOriginal('submission_deadline') !== null) {
            // Preserve a prior manual override when the filing deadline changes.
            $data['submission_deadline'] = $record->getRawOriginal('submission_deadline');
            $data['submission_deadline_is_manual'] = true;
        } else {
            $data['submission_deadline'] = $auto->toDateString();
            $data['submission_deadline_is_manual'] = false;
        }

        return $data;
    }
}
