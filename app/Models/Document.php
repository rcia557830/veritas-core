<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'document_number', 'title', 'document_type', 'status', 'received_date', 'due_date', 'file_path', 'original_file_name', 'mime_type', 'notes', 'uploaded_by', 'file_sha256'];

    protected static function booted(): void
    {
        static::updating(function (self $document) {
            if ($document->isDirty(['client_id', 'received_date'])) {
                foreach ($document->requirements()->with('accountingPeriod')->get() as $requirement) {
                    if ((int) $document->client_id !== $requirement->client_id) {
                        throw ValidationException::withMessages(['client_id' => 'A linked document must remain with its requirement client.']);
                    }
                    $period = $requirement->accountingPeriod;
                    $date = $document->received_date?->toDateString();
                    if ($period && (! $date || $date < $period->starts_on || $date > $period->ends_on)) {
                        throw ValidationException::withMessages(['received_date' => 'The received date must remain within the linked requirement period.']);
                    }
                }
            }
            if ($document->isDirty('client_id') && JournalDocument::where('document_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'A document referenced by journal evidence cannot change clients.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['received_date' => 'date', 'due_date' => 'date'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function requirements()
    {
        return $this->belongsToMany(DocumentRequirement::class, 'requirement_documents', 'document_id', 'requirement_id')
            ->withPivot('linked_by', 'id', 'created_at')
            ->withTimestamps();
    }
}
