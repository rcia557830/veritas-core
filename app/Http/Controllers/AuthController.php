<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\Audit;
use App\Services\SecurityMail;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Please try again in a minute.']);
        }
        if (! Auth::attempt($data + ['status' => 'Active'], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            AccountSecurity::record('login.failed');
            throw ValidationException::withMessages(['email' => 'The credentials are incorrect or the account is inactive.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('security_version.'.$request->user()->id, $request->user()->session_version);
        AccountSecurity::record('login.succeeded', $request->user());
        $request->user()->update(['last_login_at' => now()]);
        Audit::record('login', 'auth', $request->user(), 'User logged in.');

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        AccountSecurity::record('logout', $request->user());
        Audit::record('logout', 'auth', $request->user(), 'User logged out.');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot()
    {
        return view('auth.forgot');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', Str::lower($request->input('email')))->first();
        if ($user && $this->canReset($user)) {
            SecurityMail::resetLink($user);
        }

        return back()->with('success', 'If this address has an account, a password reset link has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return response()->view('auth.reset', ['token' => $token, 'email' => $request->query('email')])
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => PasswordPolicy::rules()]);
        $status = DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! $this->canReset($user)) {
                return Password::INVALID_TOKEN;
            }

            // Serialize token checking and deletion with password writes on this account.
            return Password::reset($data, function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
                AccountSecurity::revoke($user);
                AccountSecurity::record('password.reset', $user);
                event(new PasswordReset($user));
            });
        }, 3);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('success', 'Password reset. Sign in with your new password.');
    }

    public function profile()
    {
        return view('auth.profile');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)], 'current_password' => 'required|current_password', 'password' => 'prohibited', 'password_confirmation' => 'prohibited']);
        DB::transaction(function () use ($data, $request) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $email = Str::lower($data['email']);
            if ($email !== $user->email) {
                if (User::whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an account.']);
                }
                DB::table('password_reset_tokens')->whereIn('email', [$user->email, $email])->delete();
                $user->email_verified_at = null;
                AccountSecurity::revoke($user, $request->session()->getId());
                $request->session()->put('security_version.'.$user->id, $user->session_version);
            }
            $user->fill(['name' => $data['name'], 'email' => $email])->save();
            AccountSecurity::record('profile.updated', $user);
            Audit::record('profile', 'users', $user, 'Profile updated.');
        });

        return back()->with('success', 'Profile updated successfully.');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|current_password', 'password' => PasswordPolicy::rules()]);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a password different from your current password.']);
            }
            $user->password = $data['password'];
            $user->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            AccountSecurity::revoke($user, $request->session()->getId());
            $request->session()->regenerate();
            $request->session()->put('security_version.'.$user->id, $user->session_version);
            AccountSecurity::record('password.changed', $user);
        });

        return back()->with('success', 'Password changed. Other sessions have been signed out.');
    }

    private function canReset(User $user): bool
    {
        return $user->status !== 'Pending Invitation'
            && ($user->invitation_accepted_at || ! EmployeeInvitation::where('user_id', $user->id)->exists());
    }
}
