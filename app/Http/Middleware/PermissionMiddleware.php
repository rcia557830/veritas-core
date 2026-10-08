<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        foreach ($permissions as $permission) {
            abort_unless($request->user()?->hasPermission($permission), 403);
        }

        return $next($request);
    }
}
