<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasRole('owner') && $user->hasPermission('user.'.$permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, User $record): bool
    {
        return $this->allows($user, 'update');
    }

    public function changeRole(User $user, User $record): bool
    {
        return $this->allows($user, 'role') && $user->id !== $record->id;
    }

    public function activate(User $user, User $record): bool
    {
        return $this->allows($user, 'activate');
    }

    public function deactivate(User $user, User $record): bool
    {
        return $this->allows($user, 'deactivate') && $user->id !== $record->id;
    }

    public function resetPassword(User $user, User $record): bool
    {
        return $this->allows($user, 'reset-password');
    }
}
