<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy extends RecordPolicy
{
    protected string $permission = 'client';

    public function archive(User $user, Client $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('client.archive');
    }

    public function restore(User $user, Client $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('client.restore');
    }

    public function activate(User $user, Client $record): bool
    {
        return $this->view($user, $record) && $user->hasPermission('client.activate');
    }
}
