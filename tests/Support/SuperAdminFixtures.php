<?php

namespace Tests\Support;

use App\Models\Role;
use App\Models\User;

trait SuperAdminFixtures
{
    protected function superAdmin(): User
    {
        return User::firstOrCreate(['email' => 'technical@example.test'], [
            'name' => 'Technical Administrator', 'password' => 'Technical-password-123',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id, 'status' => 'Active',
        ]);
    }
}
