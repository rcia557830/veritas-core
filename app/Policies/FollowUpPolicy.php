<?php

namespace App\Policies;

use App\Models\DocumentFollowUp;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class FollowUpPolicy
{
    public function view(User $user, DocumentFollowUp $followUp): bool
    {
        return Gate::forUser($user)->allows('view', $followUp->requirement);
    }

    public function create(User $user, DocumentRequirement $requirement): bool
    {
        return $user->hasPermission('requirement.follow-up') && Gate::forUser($user)->allows('view', $requirement);
    }

    public function update(User $user, DocumentFollowUp $followUp): bool
    {
        return $user->hasPermission('requirement.follow-up') && Gate::forUser($user)->allows('view', $followUp->requirement);
    }

    public function delete(User $user, DocumentFollowUp $followUp): bool
    {
        return $user->hasPermission('requirement.follow-up') && Gate::forUser($user)->allows('view', $followUp->requirement);
    }
}
