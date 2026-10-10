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
                $role = Role::where('slug', $slug)->firstOrFail();
                // Posting grants are provisional for development. An existing
                // business installation requires an explicit permission rollout.
                if (! app()->environment(['local', 'testing'])) {
                    $grants = array_values(array_diff($grants, ['bookkeeping.post']));
                    if ($role->permissions()->where('name', 'bookkeeping.post')->exists()) {
                        $grants[] = 'bookkeeping.post';
                    }
                }
                $role->permissions()->sync(Permission::whereIn('name', $grants)->pluck('id'));
            }
        });
    }
}
