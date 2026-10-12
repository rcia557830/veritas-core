<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class EmployeeSecurityTest extends TestCase
{
    use RefreshDatabase, SuperAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function employee(): User
    {
        return User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    private function invite(string $slug = 'bookkeeper'): array
    {
        $this->actingAs($this->superAdmin())->post('/admin/users', [
            'name' => 'Invited Employee', 'email' => 'invited@example.test', 'role_id' => Role::where('slug', $slug)->value('id'),
        ])->assertRedirect('/admin/users');
        $invitation = EmployeeInvitation::latest('id')->firstOrFail();
        $mail = Mail::sent(EmployeeInvitationMail::class)->last();
        parse_str(parse_url($mail->acceptUrl, PHP_URL_QUERY), $query);

        return [$invitation, $query['token'], $mail];
    }

    private function accept(EmployeeInvitation $invitation, string $token, array $extra = [])
    {
        auth()->forgetGuards();

        return $this->post('/invitations/'.$invitation->id, array_merge([
            'email' => $invitation->email, 'token' => $token,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ], $extra));
    }

    public function test_four_roles_and_idempotent_rollout_preserve_existing_owner_and_business_records(): void
    {
        $owner = User::whereHas('role', fn ($q) => $q->where('slug', 'owner'))->firstOrFail();
        $before = $owner->getAttributes();
        $clients = DB::table('clients')->get()->toJson();
        $migration = require database_path('migrations/2026_10_12_000002_separate_technical_roles.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertSame($clients, DB::table('clients')->get()->toJson());
        $this->assertDatabaseCount('roles', 4);
        $this->assertFalse($owner->hasPermission('user.create'));
        $this->assertTrue($owner->hasPermission('audit.view'));
        $this->assertTrue($owner->hasPermission('bookkeeping.post'));
        $this->assertFalse($this->superAdmin()->hasPermission('bookkeeping.view'));
    }

    public function test_business_roles_cannot_access_technical_routes_or_assign_super_admin(): void
    {
        foreach (User::all() as $user) {
            $this->actingAs($user);
            foreach (['/admin/users', '/admin/users/create', '/workspace', '/admin/security-logs'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/users', ['role_id' => Role::where('slug', 'super-admin')->value('id')])->assertForbidden();
            $this->post('/admin/users/'.$user->id.'/revoke-sessions')->assertForbidden();
        }
    }

    public function test_super_admin_is_denied_financial_data_in_all_shared_surfaces(): void
    {
        $this->actingAs($this->superAdmin());
        $client = DB::table('clients')->value('id');
        $document = DB::table('documents')->value('id');
        $invoice = DB::table('invoices')->value('id');
        foreach (['/clients', '/clients/'.$client, '/documents/'.$document.'/download', '/ledger', '/vouchers', '/accounts', '/account-templates', '/general-ledger', '/financial-reports', '/financial-reports/print', '/reports/csv', '/billing/'.$invoice.'/pdf', '/billing/'.$invoice.'/print', '/admin/audit-logs', '/requirements', '/compliance', '/knowledge', '/notices'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/dashboard')->assertOk()->assertSee('Technical administration')->assertDontSee('39,000');
        $this->get('/search?q=Davao')->assertOk()->assertJsonCount(0);
        $this->get('/notifications')->assertOk()->assertJson(['items' => [], 'unread' => 0]);
        $this->get('/admin/security-logs')->assertOk()->assertDontSee('39,000');
    }

    public function test_admin_password_fields_are_absent_and_even_empty_forged_fields_are_rejected(): void
    {
        $user = $this->employee();
        $before = $user->password;
        $this->actingAs($this->superAdmin());
        foreach (['/admin/users/create', '/admin/users/'.$user->id.'/edit'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('name="password"', false)->assertDontSee('name="password_confirmation"', false);
        }
        foreach (['password', 'password_confirmation', 'remember_token', 'session_version'] as $field) {
            $this->put('/admin/users/'.$user->id, $user->only(['name', 'email', 'role_id', 'status']) + [$field => ''])->assertForbidden();
            $this->post('/admin/users', [$field => 'forged-secret'])->assertForbidden();
        }
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_invitation_mail_digest_pending_status_and_successful_single_use_acceptance(): void
    {
        [$invitation, $token, $mail] = $this->invite();
        Mail::assertSent(EmployeeInvitationMail::class, fn ($m) => $m->hasTo('invited@example.test'));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->equalTo($invitation->created_at->addHours(24)));
        $this->assertDatabaseHas('users', ['id' => $invitation->user_id, 'status' => 'Pending Invitation']);
        $this->assertStringNotContainsString($token, DB::table('security_events')->get()->toJson());
        auth()->forgetGuards();
        $this->get($mail->acceptUrl)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('12 characters');
        $this->accept($invitation, $token)->assertRedirect('/login');
        $user = User::findOrFail($invitation->user_id);
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertSame('Active', $user->status);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->accept($invitation, $token)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_invitation_rejects_expiry_wrong_email_tampering_and_wrong_role(): void
    {
        [$invitation, $token] = $this->invite();
        $this->accept($invitation, str_repeat('a', 64))->assertSessionHasErrors('email');
        $this->accept($invitation, $token, ['email' => 'other@example.test'])->assertSessionHasErrors('email');
        $user = User::findOrFail($invitation->user_id);
        $user->update(['role_id' => Role::where('slug', 'owner')->value('id')]);
        $this->accept($invitation, $token)->assertSessionHasErrors('email');
        $user->update(['role_id' => $invitation->role_id]);
        $this->travel(25)->hours();
        $this->accept($invitation, $token)->assertSessionHasErrors('email');
        $this->assertSame('Pending Invitation', $user->fresh()->status);
    }

    public function test_resending_invalidates_old_token_and_uses_configured_expiry(): void
    {
        [$old, $token] = $this->invite();
        config(['security.invitation_hours' => 2]);
        $this->post('/admin/users/'.$old->user_id.'/resend-invitation')->assertRedirect();
        $new = EmployeeInvitation::latest('id')->firstOrFail();
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNotSame($old->token_hash, $new->token_hash);
        $this->assertTrue($new->expires_at->equalTo($new->created_at->addHours(2)));
        $this->accept($old, $token)->assertSessionHasErrors('email');
        parse_str(parse_url(Mail::sent(EmployeeInvitationMail::class)->last()->acceptUrl, PHP_URL_QUERY), $query);
        $this->accept($new, $query['token'])->assertRedirect('/login');
    }

    public function test_pending_accounts_cannot_login_reset_or_be_activated_by_an_admin(): void
    {
        [$invitation] = $this->invite();
        $user = User::findOrFail($invitation->user_id);
        $this->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'email', 'role_id']), ['status' => 'Active']))->assertSessionHasErrors('status');
        $this->post('/admin/users/'.$user->id.'/reset-password')->assertUnprocessable();
        auth()->forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])->assertSessionHasErrors('email');
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        Notification::assertNothingSent();
        $this->post('/reset-password', ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHasErrors('email');
    }

    public function test_invitation_password_requires_twelve_characters_and_confirmation(): void
    {
        [$invitation, $token] = $this->invite();
        $this->accept($invitation, $token, ['password' => '12345678901', 'password_confirmation' => '12345678901'])->assertSessionHasErrors('password');
        $this->accept($invitation, $token, ['password' => '123456789012', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->accept($invitation, $token, ['password' => '123456789012', 'password_confirmation' => '123456789012'])->assertRedirect('/login');
    }

    public function test_change_password_checks_current_password_confirmation_length_and_reuse(): void
    {
        $user = $this->employee();
        $user->update(['password' => 'original-password']);
        $this->actingAs($user);
        foreach ([['wrong', '123456789012', '123456789012', 'current_password'], ['original-password', '12345678901', '12345678901', 'password'], ['original-password', '123456789012', 'different', 'password'], ['original-password', 'original-password', 'original-password', 'password']] as [$current, $new, $confirm, $error]) {
            $this->patch('/profile/password', ['current_password' => $current, 'password' => $new, 'password_confirmation' => $confirm])->assertSessionHasErrors($error);
        }
        $this->patch('/profile/password', ['current_password' => 'original-password', 'password' => '123456789012', 'password_confirmation' => '123456789012'])->assertSessionHas('success');
        $this->assertTrue(Hash::check('123456789012', $user->fresh()->password));
        $this->get('/dashboard')->assertOk();
        $this->patch('/profile/password', [])->assertStatus(429);
    }

    public function test_change_password_revokes_other_sessions_remember_token_and_outstanding_reset_tokens(): void
    {
        $user = $this->employee();
        $token = Password::createToken($user);
        $user->forceFill(['remember_token' => 'old-remember'])->save();
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->patch('/profile/password', ['user_id' => $this->superAdmin()->id, 'current_password' => 'password123', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
        $this->assertNotSame('old-remember', $user->fresh()->remember_token);
        $this->assertFalse(Password::tokenExists($user->fresh(), $token));
        $this->withSession(['security_version.'.$user->id => 0])->get('/dashboard')->assertRedirect('/login');
    }

    public function test_forgot_password_is_neutral_and_sends_laravel_reset_notification(): void
    {
        Notification::fake();
        $user = $this->employee();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        $message = session('success');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('success', $message);
    }

    public function test_reset_has_twelve_character_confirmation_single_use_expiry_and_preserves_inactive_status(): void
    {
        $user = $this->employee();
        $user->update(['status' => 'Inactive']);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => '123456789012', 'password_confirmation' => '123456789012'];
        $this->post('/reset-password', array_merge($payload, ['password' => '12345678901', 'password_confirmation' => '12345678901']))->assertSessionHasErrors('password');
        $this->post('/reset-password', array_merge($payload, ['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post('/reset-password', $payload)->assertRedirect('/login');
        $this->assertSame('Inactive', $user->fresh()->status);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $payload['token'] = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_admin_cannot_change_email_as_an_indirect_password_takeover_or_cross_technical_business_roles(): void
    {
        $admin = $this->superAdmin();
        $user = $this->employee();
        $this->actingAs($admin);
        $this->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'role_id', 'status']), ['email' => 'attacker@example.test']))->assertSessionHasErrors('email');
        $this->put('/admin/users/'.$admin->id, array_merge($admin->only(['name', 'email', 'status']), ['role_id' => $user->role_id]))->assertForbidden();
        $this->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'email', 'status']), ['role_id' => $admin->role_id]))->assertForbidden();
        $this->assertSame('bookkeeper', $user->fresh()->role->slug);
    }

    public function test_last_active_super_admin_and_owner_are_protected(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->put('/admin/users/'.$admin->id, array_merge($admin->only(['name', 'email', 'role_id']), ['status' => 'Inactive']))->assertForbidden();
        $owner = User::whereHas('role', fn ($q) => $q->where('slug', 'owner'))->firstOrFail();
        $this->put('/admin/users/'.$owner->id, array_merge($owner->only(['name', 'email', 'role_id']), ['status' => 'Inactive']))->assertSessionHasErrors('status');
        $this->assertSame('Active', $owner->fresh()->status);
        $this->assertSame('Active', $admin->fresh()->status);
    }

    public function test_admin_session_revocation_and_deactivation_invalidate_existing_sessions(): void
    {
        $user = $this->employee();
        $this->actingAs($this->superAdmin())->post('/admin/users/'.$user->id.'/revoke-sessions')->assertRedirect();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($this->superAdmin())->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'email', 'role_id']), ['status' => 'Inactive']))->assertRedirect();
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_duplicate_invitation_cannot_overwrite_existing_account(): void
    {
        $user = $this->employee();
        $before = $user->getAttributes();
        $this->actingAs($this->superAdmin())->post('/admin/users', ['name' => 'Replacement', 'email' => strtoupper($user->email), 'role_id' => $user->role_id])->assertSessionHasErrors('email');
        $this->assertSame($before, $user->fresh()->getAttributes());
        Mail::assertNothingSent();
    }

    public function test_provisioning_uses_invitation_and_never_converts_owner(): void
    {
        $owner = User::whereHas('role', fn ($q) => $q->where('slug', 'owner'))->firstOrFail();
        $before = $owner->getAttributes();
        $this->artisan('veritas:invite-super-admin', ['email' => 'bootstrap@example.test', '--name' => 'Initial Admin'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'bootstrap@example.test', 'status' => 'Pending Invitation', 'role_id' => Role::where('slug', 'super-admin')->value('id')]);
        $this->assertSame($before, $owner->fresh()->getAttributes());
        Mail::assertSent(EmployeeInvitationMail::class);
        $this->artisan('veritas:invite-super-admin', ['email' => 'bootstrap@example.test', '--name' => 'Initial Admin', '--resend' => true])->assertSuccessful();
        $this->assertDatabaseCount('employee_invitations', 2);
        $this->assertNotNull(EmployeeInvitation::first()->revoked_at);
    }

    public function test_failed_delivery_preserves_pending_account_and_allows_safe_resend(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->superAdmin())->post('/admin/users', [
            'name' => 'Delivery Failure', 'email' => 'delivery@example.test', 'role_id' => $this->employee()->role_id,
        ])->assertSessionHasErrors('email');
        $user = User::where('email', 'delivery@example.test')->firstOrFail();
        $old = EmployeeInvitation::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Pending Invitation', $user->status);
        $this->assertDatabaseHas('security_events', ['action' => 'invitation.delivery-failed', 'subject_id' => $user->id]);
        Mail::assertNothingSent();
        config(['mail.default' => 'array']);
        $this->post('/admin/users/'.$user->id.'/resend-invitation')->assertRedirect();
        Mail::assertSent(EmployeeInvitationMail::class);
        $this->assertNotNull($old->fresh()->revoked_at);
    }

    public function test_pending_deactivation_cancels_invitation_and_role_edit_issues_a_new_bound_invitation(): void
    {
        [$old, $token] = $this->invite();
        $user = User::findOrFail($old->user_id);
        $this->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'email', 'role_id']), ['status' => 'Inactive']))->assertRedirect();
        $this->accept($old, $token)->assertSessionHasErrors('email');
        $this->actingAs($this->superAdmin())->post('/admin/users/'.$user->id.'/resend-invitation')->assertRedirect();
        $second = EmployeeInvitation::latest('id')->firstOrFail();
        $ownerRole = Role::where('slug', 'owner')->value('id');
        $this->put('/admin/users/'.$user->id, array_merge($user->only(['name', 'email']), ['status' => 'Pending Invitation', 'role_id' => $ownerRole]))->assertRedirect();
        $this->assertNotNull($second->fresh()->revoked_at);
        $this->assertSame($ownerRole, EmployeeInvitation::latest('id')->firstOrFail()->role_id);
    }

    public function test_authenticated_admin_reset_action_sends_notification_without_changing_password(): void
    {
        Notification::fake();
        $user = $this->employee();
        $hash = $user->password;
        $this->actingAs($this->superAdmin())->post('/admin/users/'.$user->id.'/reset-password')->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame($hash, $user->fresh()->password);
        $this->get('/admin/users')->assertSee('Send Password Reset Link');
    }

    public function test_bootstrap_refuses_existing_owner_even_with_resend(): void
    {
        $owner = User::whereHas('role', fn ($q) => $q->where('slug', 'owner'))->firstOrFail();
        $before = $owner->getAttributes();
        try {
            $this->artisan('veritas:invite-super-admin', ['email' => $owner->email, '--name' => 'Replacement', '--resend' => true])->run();
            $this->fail('Existing Owner was accepted for bootstrap.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be converted', $exception->getMessage());
        }
        $this->assertSame($before, $owner->fresh()->getAttributes());
        Mail::assertNothingSent();
    }
}
