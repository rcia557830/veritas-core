<?php

namespace App\Policies;

use App\Models\DocumentRequirementTemplate;
use App\Models\User;

class RequirementTemplatePolicy
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'office-manager']);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('requirement.view');
    }

    public function view(User $user, DocumentRequirementTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manager($user) && $user->hasPermission('requirement.create');
    }

    public function update(User $user, DocumentRequirementTemplate $template): bool
    {
        return $this->manager($user) && $user->hasPermission('requirement.update');
    }

    public function apply(User $user, DocumentRequirementTemplate $template): bool
    {
        return $this->manager($user) && $user->hasPermission('requirement.create');
    }

    public function delete(User $user, DocumentRequirementTemplate $template): bool
    {
        return $this->manager($user) && $user->hasPermission('requirement.archive');
    }
}
