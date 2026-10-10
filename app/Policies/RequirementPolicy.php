<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\User;

class RequirementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('requirement.view');
    }

    private function viewClient(User $user, Client $client): bool
    {
        if ($user->hasAnyRole(['owner', 'office-manager'])) {
            return true;
        }

        return $user->hasRole('bookkeeper') && $client->assigned_to === $user->id;
    }

    public function view(User $user, DocumentRequirement $requirement): bool
    {
        return $this->viewAny($user) && $this->viewClient($user, $requirement->client);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('requirement.create');
    }

    public function update(User $user, DocumentRequirement $requirement): bool
    {
        return $this->view($user, $requirement) && $user->hasPermission('requirement.update');
    }

    public function activate(User $user, DocumentRequirement $requirement): bool
    {
        return $this->view($user, $requirement) && $user->hasPermission('requirement.activate');
    }

    public function link(User $user, DocumentRequirement $requirement): bool
    {
        return $this->view($user, $requirement) && $user->hasPermission('requirement.link');
    }

    public function followUp(User $user, DocumentRequirement $requirement): bool
    {
        return $this->view($user, $requirement) && $user->hasPermission('requirement.follow-up');
    }

    public function delete(User $user, DocumentRequirement $requirement): bool
    {
        return $this->view($user, $requirement) && $user->hasPermission('requirement.archive');
    }
}
