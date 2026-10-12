<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately preserve every existing user role_id, password and assignment.
        // Business grants (including previously authorized posting) are unchanged.
        DB::transaction(function () {
            foreach (['owner' => 'Owner', 'office-manager' => 'Office Manager', 'bookkeeper' => 'Bookkeeper', 'super-admin' => 'Super Admin'] as $slug => $name) {
                DB::table('roles')->updateOrInsert(['slug' => $slug], ['name' => $name]);
            }
            $technical = ['user.view', 'user.create', 'user.update', 'user.role', 'user.activate', 'user.deactivate', 'user.reset-password', 'user.revoke-sessions', 'workspace.manage', 'security.view'];
            foreach ($technical as $permission) {
                DB::table('permissions')->updateOrInsert(['name' => $permission], []);
            }
            $ids = DB::table('permissions')->whereIn('name', $technical)->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            $admin = DB::table('roles')->where('slug', 'super-admin')->value('id');
            DB::table('permission_role')->where('role_id', $admin)->delete();
            foreach ($ids as $id) {
                DB::table('permission_role')->insert(['role_id' => $admin, 'permission_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Role separation cannot be rolled back by restoring Owner technical privileges.');
    }
};
