<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_reports_missing_grants_without_changing_accounts_or_exposing_secrets(): void
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::where('slug', 'super-admin')->sole();
        $role->permissions()->detach();
        $user = User::factory()->create(['role_id' => $role->id, 'status' => 'Active']);
        $before = $user->fresh()->getAttributes();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('veritas:inspect-access'));
        $output = Artisan::output();
        $this->assertStringContainsString('required columns present', $output);
        $this->assertStringContainsString('"role":"super-admin"', $output);
        $this->assertStringContainsString('"missing_baseline_grants":["user.view"', $output);
        foreach ([$user->email, $user->password, $user->remember_token, config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query);
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_missing_session_table_is_reported_without_creating_it(): void
    {
        config(['session.table' => 'missing_sessions']);
        $this->artisan('veritas:inspect-access')
            ->expectsOutputToContain('Session table: missing required columns:')
            ->assertFailed();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('missing_sessions'));
    }
}
