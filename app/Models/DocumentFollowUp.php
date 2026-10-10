<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentFollowUp extends Model
{
    use SoftDeletes;

    protected $fillable = ['requirement_id', 'status', 'assigned_to', 'follow_up_date', 'remarks', 'created_by'];

    protected function casts(): array
    {
        return ['follow_up_date' => 'date'];
    }

    public function requirement()
    {
        return $this->belongsTo(DocumentRequirement::class, 'requirement_id');
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
