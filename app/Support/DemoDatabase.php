<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

final class DemoDatabase
{
    public static function check(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('demo.enabled') || app()->configurationIsCached()) {
            throw new \RuntimeException('Demo data requires local/testing and VERITAS_DEMO_DATA=true.');
        }
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        if ($connection->getDriverName() === 'sqlite') {
            if (app()->environment('testing') && $database === ':memory:') {
                return;
            }
            if (realpath($database) !== false && realpath($database) === realpath(database_path('veritas_demo.sqlite'))) {
                return;
            }
        }
        // The regression runner independently verifies server identity and a disposable token.
        if (app()->environment('testing') && $connection->getDriverName() === 'mysql') {
            TestDatabaseGuard::check(app());

            return;
        }
        throw new \RuntimeException('Demo data requires a dedicated veritas_demo.sqlite database; business databases are forbidden.');
    }
}
