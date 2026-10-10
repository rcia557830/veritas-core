<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        // Account-code canonicalization handles only outer ASCII spaces. Do not
        // let HTTP middleware silently trim other meaningful input characters.
        $middleware->trimStrings(except: ['code']);
        $middleware->alias(['active' => ActiveAccount::class, 'role' => RoleMiddleware::class, 'permission' => PermissionMiddleware::class]);
    })
    ->withCommands()
    ->withExceptions(function (Exceptions $exceptions) {})
    ->create();
