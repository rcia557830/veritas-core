<?php

namespace App\Policies;

use App\Models\ComplianceFollowUp;
use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ComplianceFollowUpPolicy
{
    public function create(User $user, ComplianceRecord $record): bool
    {
        return $user->hasPermission('compliance.follow-up') && Gate::forUser($user)->allows('view', $record);
    }

    public function update(User $user, ComplianceFollowUp $followUp): bool
    {
        return $user->hasPermission('compliance.follow-up') && Gate::forUser($user)->allows('view', $followUp->complianceRecord);
    }

    public function delete(User $user, ComplianceFollowUp $followUp): bool
    {
        return $user->hasPermission('compliance.follow-up') && Gate::forUser($user)->allows('view', $followUp->complianceRecord);
    }
}
