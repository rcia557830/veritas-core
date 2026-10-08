<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            throw ValidationException::withMessages(['email' => 'The credentials are incorrect or the account is inactive.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);
        Audit::record('login', 'auth', $request->user(), 'User logged in.');

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
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
        Password::sendResetLink($request->only('email'));

        return back()->with('success', 'If this address has an account, a password reset link has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
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
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', Rule::unique('users')->ignore($request->user()->id)], 'current_password' => 'required|current_password', 'password' => ['nullable', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        $user = $request->user();
        $user->fill(collect($data)->only(['name', 'email'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $user->save();
        Audit::record('profile', 'users', $user, 'Profile updated.');

        return back()->with('success', 'Profile updated successfully.');
    }
}
