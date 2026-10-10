<?php

namespace App\Services;

use App\Http\Requests\RecordRequest;
use App\Models\User;
use App\Services\Accounting\JournalWriter;
use App\Support\Modules;
use App\Support\Money;
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
        try {
            $saved = DB::transaction(function () use ($module, $record, $data, $items, $request, &$path) {
                $model = Modules::get($module)['model'];
                $previousStatus = null;
                if ($record) {
                    $record = $model::lockForUpdate()->findOrFail($record->id);
                    Gate::authorize('update', $record);
                    RecordInput::authorize($module, $request->user(), $data, $record);
                    $previousStatus = $record->status;
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
                if ($path && $oldPath) {
                    // Retain the original privately; the audit reference preserves traceability.
                    Audit::record('document.attachment-replaced', $module, $record, 'Attachment replaced; retained original: '.$oldPath.'; replacement: '.$path.'.');
                }
                if ($module === 'billing') {
                    $record->items()->delete();
                    $record->items()->createMany($items);
                }
                Audit::record($record->wasRecentlyCreated ? 'created' : 'updated', $module, $record, ucfirst(Modules::get($module)['singular']).' saved; status: '.($record->status ?? '').'.');

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
            Notify::record($saved, 'compliance', 'Compliance assignment: '.$saved->requirement);
        }

        return $saved;
    }
}
