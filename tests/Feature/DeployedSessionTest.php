<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AccountSecurity;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeployedSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        config(['session.driver' => 'database', 'session.encrypt' => true]);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function account(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->sole()->id,
            'status' => 'Active', 'password' => 'Session-test-password-123',
        ]);
    }

    private function signIn(User $user): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false);
        $id = session()->getId();
        $this->post('/login', [
            '_token' => session()->token(), 'email' => $user->email,
            'password' => 'Session-test-password-123',
        ])->assertRedirect('/dashboard');
        $this->assertNotSame($id, session()->getId());
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $user->id]);
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['super-admin', 'owner', 'office-manager', 'bookkeeper']);
    }

    #[DataProvider('roles')]
    public function test_each_role_can_log_in_with_database_sessions_and_log_out_with_csrf(string $role): void
    {
        $user = $this->account($role);
        $this->signIn($user);
        // Re-read the encrypted database payload rather than retaining the
        // in-memory session or authenticated guard from the login request.
        $id = session()->getId();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        auth()->forgetGuards();
        $this->withCookie(config('session.cookie'), $id)->get('/dashboard')
            ->assertOk()->assertSee('data-dashboard-role="'.$role.'"', false);
        $this->assertAuthenticatedAs($user);
        foreach (['/admin/users', '/admin/roles', '/admin/security-logs', '/admin/activity-logs'] as $url) {
            $this->get($url)->assertStatus($role === 'super-admin' ? 200 : 403);
        }
        $this->get('/clients')->assertStatus($role === 'super-admin' ? 403 : 200);
        $this->post('/logout', ['_token' => session()->token()])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    public function test_missing_and_stale_csrf_tokens_are_rejected(): void
    {
        $user = $this->account('super-admin');
        $this->get('/login')->assertOk();
        foreach ([null, 'stale-token'] as $token) {
            $this->post('/login', ['_token' => $token, 'email' => $user->email, 'password' => 'Session-test-password-123'])->assertStatus(419);
            $this->assertGuest();
        }
        $this->signIn($user);
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
    }

    public function test_super_admin_dashboard_does_not_require_business_or_technical_grants(): void
    {
        $user = $this->account('super-admin');
        $user->role->permissions()->detach();
        $this->signIn($user);
        $this->get('/dashboard')->assertOk()->assertSee('Technical administration');
        foreach (['/admin/users', '/admin/roles', '/workspace', '/admin/security-logs', '/clients'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_revoked_database_session_cannot_access_dashboard(): void
    {
        $user = $this->account('super-admin');
        $this->signIn($user);
        $id = session()->getId();
        DB::transaction(fn () => AccountSecurity::revoke(User::lockForUpdate()->findOrFail($user->id)));
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_proxy_configuration_generates_https_login_form_and_secure_host_only_cookie(): void
    {
        config(['trustedproxy.proxies' => '*', 'session.secure' => true, 'session.domain' => null, 'session.same_site' => 'lax']);
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get('http://veritas.example.test/login')->assertOk()
            ->assertSee('action="https://veritas.example.test/login"', false);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
    }
}
