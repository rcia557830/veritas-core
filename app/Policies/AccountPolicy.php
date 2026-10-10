<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('account.view') && $user->hasPermission('client.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $this->viewAny($user) && $account->client && Gate::forUser($user)->allows('view', $account->client);
    }

    public function create(User $user, ?Client $client = null): bool
    {
        return $this->viewAny($user) && $user->hasAnyRole(['owner', 'office-manager'])
            && $user->hasPermission('account.create') && (! $client || Gate::forUser($user)->allows('view', $client));
    }

    public function update(User $user, Account $account): bool
    {
        return $this->view($user, $account) && $user->hasAnyRole(['owner', 'office-manager']) && $user->hasPermission('account.update');
    }

    public function status(User $user, Account $account): bool
    {
        return $this->view($user, $account) && $user->hasAnyRole(['owner', 'office-manager']) && $user->hasPermission('account.deactivate');
    }

    public function initialize(User $user, ?Client $client = null): bool
    {
        return $this->viewAny($user) && $user->hasRole('owner') && $user->hasPermission('account.initialize')
            && (! $client || Gate::forUser($user)->allows('view', $client));
    }
}
