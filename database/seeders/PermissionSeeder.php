<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        DB::transaction(function () {
            foreach (config('rbac.permissions') as $name) {
                Permission::firstOrCreate(['name' => $name]);
            }
            foreach (config('rbac.roles') as $slug => $name) {
                $grants = $slug === 'owner' ? config('rbac.permissions') : config('rbac.grants.'.$slug, []);
                Role::where('slug', $slug)->firstOrFail()->permissions()->sync(Permission::whereIn('name', $grants)->pluck('id'));
            }
        });
    }
}
