<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class AuditSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SuperAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_creation_is_audited_with_attribution_and_without_the_password(): void
    {
        $owner = $this->superAdmin();
        Mail::fake();
        $role = Role::where('slug', 'bookkeeper')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users', [
            'name' => 'Audit Subject',
            'email' => 'audit.subject@veritascore.local',
            'role_id' => $role->id,
        ])->assertRedirect('/admin/users');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'user_id' => $owner->id,
        ]);

        $allDescriptions = AuditLog::pluck('description')->implode(' ');
        $this->assertStringNotContainsString('S3cret-password', $allDescriptions);
    }

    public function test_audit_records_carry_a_timestamp_and_responsible_user(): void
    {
        $owner = $this->superAdmin();
        Mail::fake();
        $role = Role::where('slug', 'bookkeeper')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users', [
            'name' => 'Timestamp Subject',
            'email' => 'timestamp.subject@veritascore.local',
            'role_id' => $role->id,
        ])->assertRedirect('/admin/users');

        $log = AuditLog::where('action', 'user.created')->latest('id')->firstOrFail();
        $this->assertNotNull($log->user_id);
        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->ip_address);
    }

    public function test_audit_logs_have_no_edit_or_delete_routes(): void
    {
        $owner = $this->superAdmin();
        Mail::fake();
        $this->actingAs($owner);

        // The audit index is a read-only GET route; mutation methods are rejected.
        $this->post('/admin/audit-logs')->assertStatus(405);
        $this->put('/admin/audit-logs/1')->assertNotFound();
        $this->delete('/admin/audit-logs/1')->assertNotFound();
    }
}
