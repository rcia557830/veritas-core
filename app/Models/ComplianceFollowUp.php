<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceFollowUp extends Model
{
    use SoftDeletes;

    protected $fillable = ['compliance_record_id', 'status', 'assigned_to', 'follow_up_date', 'remarks', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return ['follow_up_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function complianceRecord()
    {
        return $this->belongsTo(ComplianceRecord::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
