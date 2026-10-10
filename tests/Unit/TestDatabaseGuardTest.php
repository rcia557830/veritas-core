<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_memory_connection_is_verified_and_alternate_connections_removed(): void
    {
        TestDatabaseGuard::check($this->app);
        $this->assertSame([config('database.default')], array_keys(config('database.connections')));
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        } else {
            $this->assertMatchesRegularExpression('/^veritas_stage21_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        }
    }

    public function test_unsafe_resolved_connections_are_rejected_before_connection_or_migration(): void
    {
        $safe = config('database.connections');
        $default = config('database.default');
        foreach ([
            ['driver' => 'mysql', 'database' => 'veritas_core_test_codex'],
            ['driver' => 'sqlite', 'database' => '/must-not-open.sqlite', 'foreign_key_constraints' => true],
            ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => false],
            ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true, 'url' => 'mysql://unused:unused@127.0.0.1/must_not_connect'],
        ] as $connection) {
            config(['database.default' => 'guard_probe', 'database.connections.guard_probe' => $connection]);
            try {
                TestDatabaseGuard::check($this->app);
                $this->fail('Unsafe test connection was accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Tests require', $error->getMessage());
            } finally {
                DB::purge('guard_probe');
                config(['database.default' => $default, 'database.connections' => $safe]);
            }
        }
    }
}
