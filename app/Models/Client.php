<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_code', 'business_name', 'business_type', 'contact_person', 'email', 'phone', 'tin', 'address', 'registration_status', 'business_license_status', 'status', 'notes', 'created_by', 'assigned_to', 'onboarded_at', 'onboarded_by'];

    protected function casts(): array
    {
        return ['onboarded_at' => 'datetime'];
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function accountingYears()
    {
        return $this->hasMany(AccountingYear::class);
    }

    public function accountingPeriods()
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function complianceRecords()
    {
        return $this->hasMany(ComplianceRecord::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function onboardedBy()
    {
        return $this->belongsTo(User::class, 'onboarded_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function documentRequirements()
    {
        return $this->hasMany(DocumentRequirement::class);
    }
}
