<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Audit;
use App\Services\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends ModuleController
{
    protected string $module = 'documents';

    public function download($record)
    {
        Gate::authorize('download', $record);
        abort_unless($record->file_path && Storage::disk('local')->exists($record->file_path), 404, 'Attachment not found.');
        Audit::record('download', 'documents', $record, 'Document downloaded.');

        return Storage::disk('local')->download($record->file_path, $record->original_file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function validation($record)
    {
        Gate::authorize('validate', $record);

        return redirect()->route('documents.show', $record);
    }

    public function validateDocument(Request $request, $record)
    {
        Gate::authorize('validate', $record);
        $data = $request->validate(['status' => 'required|in:Under Review,Reviewed,Approved,Rejected,Needs Clarification', 'notes' => 'nullable|string|max:30000']);
        DB::transaction(function () use ($record, $data) {
            $document = Document::lockForUpdate()->findOrFail($record->id);
            Gate::authorize('validate', $document);
            if ($data['status'] === 'Approved') {
                Gate::authorize('approve', $document);
            }
            if ($data['status'] === 'Rejected') {
                Gate::authorize('reject', $document);
            }
            $reopened = in_array($document->status, ['Reviewed', 'Approved']) && ! in_array($data['status'], ['Reviewed', 'Approved']);
            $document->update($data);
            Audit::record($reopened ? 'document.reopened' : 'document.validated', 'documents', $document, ($reopened ? 'Document review reopened: ' : 'Document validated: ').$document->status.'.');
        });
        Notify::record($record->fresh(), 'documents', 'Document '.$data['status'].': '.$record->title);

        return back()->with('success', 'Document validation saved.');
    }
}
