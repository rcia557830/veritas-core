<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class DocumentRequirement extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'template_id', 'name', 'type', 'description', 'accounting_period_id', 'is_required', 'is_active', 'due_date', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'is_required' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $requirement) {
            if ($requirement->isDirty('client_id') && $requirement->documents()->exists()) {
                throw ValidationException::withMessages(['client_id' => 'A requirement already linked to a document cannot change clients.']);
            }
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function template()
    {
        return $this->belongsTo(DocumentRequirementTemplate::class, 'template_id')->withTrashed();
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'requirement_documents', 'requirement_id', 'document_id')
            ->withPivot('linked_by', 'id', 'created_at')
            ->withTimestamps()
            ->orderByPivot('id', 'desc');
    }

    public function followUps()
    {
        return $this->hasMany(DocumentFollowUp::class, 'requirement_id');
    }
}
