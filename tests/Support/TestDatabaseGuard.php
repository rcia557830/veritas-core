<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function check(Application $app): void
    {
        // Resolve URL overrides before checking, without opening a database connection.
        $connection = $app->make('db')->connection();
        $mysql = $connection->getConfig('driver') === 'mysql';
        $sqlite = $connection->getConfig('driver') === 'sqlite' && $connection->getDatabaseName() === ':memory:' && $connection->getConfig('foreign_key_constraints');
        $token = getenv('VERITAS_TEST_TOKEN');
        $directory = getenv('VERITAS_TEST_DATADIR');
        $disposable = $mysql && $token && $directory
            && ! $connection->getConfig('url')
            && $connection->getConfig('host') === '127.0.0.1'
            && (int) $connection->getConfig('port') >= 20000
            && $connection->getConfig('username') === 'veritas_stage21_test'
            && preg_match('/^veritas_stage21_[a-f0-9]{16}$/', $connection->getDatabaseName());
        if (! $app->environment('testing') || $app->configurationIsCached() || (! $sqlite && ! $disposable)) {
            throw new RuntimeException('Tests require uncached testing configuration and SQLite :memory: with foreign keys, or a verified disposable MySQL server.');
        }
        if ($disposable) {
            $identity = $connection->selectOne('SELECT @@datadir AS directory');
            $marker = $connection->selectOne('SELECT token FROM veritas_test_guard.environment WHERE database_name = ?', [$connection->getDatabaseName()]);
            $normalize = fn ($path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
            if (! $marker || ! hash_equals($token, $marker->token) || $normalize($identity->directory) !== $normalize($directory)) {
                throw new RuntimeException('Disposable MySQL identity verification failed; no migrations are allowed.');
            }
            if ((int) $connection->scalar('SELECT @@foreign_key_checks') !== 1) {
                throw new RuntimeException('MySQL foreign key enforcement is required.');
            }
        }
        // Do not leave alternate live connections available to application tests.
        $name = $app['config']['database.default'];
        foreach (array_keys($app->make('db')->getConnections()) as $other) {
            if ($other !== $name) {
                $app->make('db')->purge($other);
            }
        }
        $app['config']->set('database.connections', [$name => $connection->getConfig()]);
        if ($sqlite && (int) $connection->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('SQLite foreign key enforcement is required.');
        }
    }
}
