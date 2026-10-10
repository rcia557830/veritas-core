<?php

namespace App\Models;

use App\Services\DeadlineCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class ComplianceRecord extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'agency', 'requirement', 'reporting_period', 'due_date', 'status',
        'submission_deadline', 'submission_deadline_is_manual', 'submission_deadline_override_reason',
        'filed_date', 'reference_number', 'notes', 'assigned_to', 'created_by',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'filed_date' => 'date', 'submission_deadline_is_manual' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followUps()
    {
        return $this->hasMany(ComplianceFollowUp::class);
    }

    /**
     * Internal client document submission deadline. A stored value (manual
     * override or explicitly recorded deadline) takes precedence; otherwise the
     * deadline is derived from the official filing deadline. Historical rows
     * therefore resolve consistently without any destructive backfill.
     */
    public function getSubmissionDeadlineAttribute($value): ?Carbon
    {
        if ($value !== null && $value !== '') {
            return Carbon::parse($value, 'Asia/Manila')->startOfDay();
        }
        if (! $this->due_date) {
            return null;
        }

        return DeadlineCalculator::submissionDeadline($this->due_date);
    }

    /**
     * True when the submission deadline was not stored explicitly and is instead
     * derived from the filing deadline (i.e. the provisional prototype value).
     */
    public function getSubmissionDeadlineIsProvisionalAttribute(): bool
    {
        $raw = $this->attributes['submission_deadline'] ?? null;

        return $raw === null || $raw === '';
    }

    public function getDisplayStatusAttribute(): string
    {
        return (string) $this->status;
    }

    public function getUrgencyAttribute(): string
    {
        return DeadlineCalculator::urgency($this);
    }
}
