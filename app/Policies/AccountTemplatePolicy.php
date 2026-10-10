<?php

namespace App\Policies;

use App\Models\User;

class AccountTemplatePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole('owner') && $user->hasPermission('account-template.manage');
    }
}
