<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceRecord extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'agency', 'requirement', 'reporting_period', 'due_date', 'status', 'filed_date', 'reference_number', 'notes', 'assigned_to', 'created_by'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'filed_date' => 'date'];
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

    public function getDisplayStatusAttribute()
    {
        return $this->status === 'Filed' ? 'Filed' : ($this->due_date->lt(today()) ? 'Overdue' : $this->status);
    }

    public function getUrgencyAttribute()
    {
        return $this->status === 'Filed' ? 'Filed' : ($this->due_date->lt(today()) ? 'Overdue' : ($this->due_date->isToday() ? 'Due Today' : ($this->due_date->lte(today()->addDays(10)) ? 'Due Soon' : 'Upcoming')));
    }
}
