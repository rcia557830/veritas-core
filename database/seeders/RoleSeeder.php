<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('rbac.roles') as $slug => $name) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
