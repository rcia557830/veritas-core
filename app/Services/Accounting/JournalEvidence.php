<?php

namespace App\Services\Accounting;

use App\Models\Document;
use App\Models\JournalDocument;
use App\Models\LedgerEntry;
use App\Services\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;

final class JournalEvidence
{
    public static function digest(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        try {
            $stream = Storage::disk('local')->readStream($path);
        } catch (UnableToReadFile $error) {
            throw ValidationException::withMessages(['document_ids' => 'A supporting attachment is missing or unreadable.']);
        }
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['document_ids' => 'A supporting attachment is missing or unreadable.']);
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    // Caller holds client and entry locks. Document writes already take document locks.
    public static function sync(LedgerEntry $entry, array $ids, array $refresh): void
    {
        $ids = array_map('intval', $ids);
        $refresh = array_map('intval', $refresh);
        if (array_diff($refresh, $ids)) {
            throw ValidationException::withMessages(['refresh_document_ids' => 'Select the document before choosing its current attachment version.']);
        }
        $active = $entry->evidence()->whereNull('detached_at')->get();
        $lockIds = array_unique(array_merge($ids, $active->pluck('document_id')->all()));
        $documents = Document::withTrashed()->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($active as $evidence) {
            if (! in_array($evidence->document_id, $ids) || in_array($evidence->document_id, $refresh)) {
                Gate::authorize('view', $evidence->document);
                $evidence->update(['detached_at' => now()]);
                Audit::record('evidence.detached', 'ledger', $entry, 'Evidence #'.$evidence->id.' retained in history.');
            }
        }
        foreach ($ids as $id) {
            $document = $documents->get($id);
            if (! $document || $document->client_id !== $entry->client_id) {
                throw ValidationException::withMessages(['document_ids' => 'Choose documents belonging to this client.']);
            }
            Gate::authorize('view', $document);
            if ($document->file_path) {
                Gate::authorize('download', $document);
            }
            if ($active->contains(fn ($e) => $e->document_id === $id) && ! in_array($id, $refresh)) {
                continue;
            }
            if ($document->trashed()) {
                throw ValidationException::withMessages(['document_ids' => 'Archived documents cannot be newly associated.']);
            }
            $evidence = $entry->evidence()->create($document->only(['client_id', 'document_number', 'title', 'file_path', 'original_file_name', 'mime_type']) + ['document_id' => $id, 'sha256' => self::digest($document->file_path), 'attached_by' => auth()->id()]);
            Audit::record('evidence.attached', 'ledger', $entry, 'Evidence #'.$evidence->id.' attached from document '.$document->document_number.'; SHA-256: '.($evidence->sha256 ?? 'metadata only').'.');
        }
    }

    public static function validate(LedgerEntry $entry): void
    {
        foreach ($entry->evidence()->whereNull('detached_at')->get() as $evidence) {
            if (! $evidence->document || $evidence->document->client_id !== $entry->client_id) {
                throw ValidationException::withMessages(['document_ids' => 'Supporting document ownership is invalid.']);
            }
            Gate::authorize('view', $evidence->document);
            if ($evidence->file_path) {
                Gate::authorize('download', $evidence->document);
                self::verify($evidence);
            }
        }
    }

    public static function verify(JournalDocument $evidence): void
    {
        if (! $evidence->sha256 || ! hash_equals($evidence->sha256, self::digest($evidence->file_path) ?? '')) {
            throw ValidationException::withMessages(['document_ids' => 'The retained supporting attachment failed its integrity check.']);
        }
    }
}
