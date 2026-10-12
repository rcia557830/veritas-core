<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user()?->fresh();
        $key = 'security_version.'.($user?->id ?? 0);
        if ($user && Auth::viaRemember()) {
            $request->session()->put($key, $user->session_version);
        }
        if ($user && ($user->status !== 'Active' || (int) $request->session()->get($key, 0) !== $user->session_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $user->status !== 'Active' ? 'Your account is not active. Contact an administrator.' : 'Your session has ended. Please sign in again.']);
        }

        return $next($request);
    }
}
