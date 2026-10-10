<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $credentials = ['email' => 'nobody@veritascore.local', 'password' => 'wrong-password'];

        $this->post('/login', $credentials)->assertSessionHasErrors('email');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', $credentials);
        }

        // The sixth attempt trips the per-account rate limiter.
        $this->post('/login', $credentials)->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            implode(' ', session('errors')->get('email')),
        );
    }

    public function test_inactive_account_cannot_authenticate(): void
    {
        User::where('email', 'bookkeeper@veritascore.local')->update(['status' => 'Inactive']);

        $this->post('/login', ['email' => 'bookkeeper@veritascore.local', 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_session_is_invalidated_on_next_request(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->actingAs($bookkeeper);
        $this->get('/dashboard')->assertOk();

        $bookkeeper->update(['status' => 'Inactive']);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_reset_rejects_weak_passwords(): void
    {
        $user = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_profile_update_requires_the_current_password(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->actingAs($bookkeeper);

        $this->patch('/profile', [
            'name' => 'Renamed',
            'email' => $bookkeeper->email,
            'current_password' => 'definitely-wrong',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_password_reset_route_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'nobody@veritascore.local']);
        }

        $this->post('/forgot-password', ['email' => 'nobody@veritascore.local'])->assertStatus(429);
    }
}
