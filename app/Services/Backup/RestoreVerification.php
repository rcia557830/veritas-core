<?php

namespace App\Services\Backup;

use PDO;
use Symfony\Component\Process\Process;

/**
 * Safe database restoration verification. Restore only into an explicitly
 * designated disposable target; never overwrite the configured primary
 * application database; never auto-restore into production.
 */
final class RestoreVerification
{
    /**
     * Guard against dangerous restore targets.
     *
     * @param  array{driver?: string, database?: ?string, host?: ?string, port?: ?string}  $primary
     * @param  array{driver?: string, database?: ?string, host?: ?string, port?: ?string}  $target
     */
    public static function preflight(array $primary, array $target, ?string $environment, bool $force): void
    {
        $env = $environment ?? app()->environment();
        $driver = strtolower($target['driver'] ?? 'mysql');
        $primaryDb = (string) ($primary['database'] ?? '');
        $targetDb = (string) ($target['database'] ?? '');

        if ($driver === 'sqlite') {
            if ($primaryDb !== '' && $targetDb !== '' && self::normalizePath($targetDb) === self::normalizePath($primaryDb)) {
                throw new BackupException('Refusing to restore over the configured primary SQLite database file.');
            }
        } else {
            $sameHost = ($primary['host'] ?? null) === ($target['host'] ?? null);
            $samePort = (string) ($primary['port'] ?? '') === (string) ($target['port'] ?? '');
            if ($sameHost && $samePort && $targetDb !== '' && $targetDb === $primaryDb) {
                throw new BackupException('Refusing to restore into the configured primary application database.');
            }
            if (! $force && $targetDb !== '' && ! preg_match('/^veritas_restore_[a-z0-9_]+$/i', $targetDb)) {
                throw new BackupException('Target database must use the disposable "veritas_restore_*" naming convention, or pass --force.');
            }
        }

        if ($env === 'production' && ! $force) {
            throw new BackupException('Restore verification is disabled in production without --force.');
        }
    }

    public static function verifyArtifact(string $file): void
    {
        if (! is_file($file)) {
            throw new BackupException("Backup artifact not found: {$file}");
        }
        if (filesize($file) <= 0) {
            throw new BackupException('Backup artifact is empty.');
        }
    }

    /**
     * Restore a SQLite backup into a disposable target file and connect to it.
     */
    public static function restoreSqlite(string $file, string $target): PDO
    {
        self::verifyArtifact($file);
        if (is_file($target)) {
            throw new BackupException('Refusing to overwrite an existing SQLite restore target.');
        }
        if (! copy($file, $target)) {
            throw new BackupException('Failed to copy the SQLite backup to the restore target.');
        }

        $pdo = new PDO('sqlite:'.$target);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Import a mysqldump artifact into the disposable target and connect to it.
     *
     * @param  array{database?: string, host?: string, port?: string, username?: string, password?: string}  $target
     */
    public static function restoreMysql(array $target, string $file, ?string $binary = null): PDO
    {
        self::verifyArtifact($file);
        $database = (string) ($target['database'] ?? '');
        if ($database === '') {
            throw new BackupException('No target database name is configured for restore.');
        }

        $mysql = self::resolveBinary('mysql', $binary);
        $defaults = self::writeDefaultsFile($target);
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            @unlink($defaults);
            throw new BackupException('Unable to read the backup artifact for import.');
        }

        try {
            // Create the disposable database if it does not exist.
            $dsn = 'mysql:host='.($target['host'] ?? '127.0.0.1').';port='.($target['port'] ?? '3306').';charset=utf8mb4';
            $admin = new PDO($dsn, $target['username'] ?? 'root', $target['password'] ?? '');
            $admin->exec('CREATE DATABASE IF NOT EXISTS '.self::quoteIdent($database));
            unset($admin);

            $process = new Process([$mysql, '--defaults-extra-file='.$defaults, $database]);
            $process->setTimeout(null);
            $process->setInput($stream);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new BackupException('mysql import failed: '.trim($process->getErrorOutput()));
            }

            return new PDO($dsn.';dbname='.$database, $target['username'] ?? 'root', $target['password'] ?? '');
        } finally {
            fclose($stream);
            @unlink($defaults);
        }
    }

    /**
     * Verify the restored database's schema, foreign keys, row counts, and
     * representative records. A successful SQL import alone is insufficient;
     * this demonstrates the restored application can read real data.
     */
    public static function verify(PDO $pdo): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = self::tables($pdo, $driver);
        $countable = ['migrations', 'users', 'roles', 'clients', 'settings', 'documents', 'ledger_entries', 'vouchers', 'invoices', 'compliance_records'];
        $rowCounts = [];
        foreach ($countable as $table) {
            if (in_array($table, $tables, true)) {
                $rowCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            }
        }

        $representative = [];
        if (in_array('settings', $tables, true)) {
            $representative['settings_firm_name'] = $pdo->query('SELECT firm_name FROM settings ORDER BY id LIMIT 1')->fetchColumn() ?: null;
        }
        if (in_array('clients', $tables, true)) {
            $representative['first_client'] = $pdo->query('SELECT business_name FROM clients ORDER BY id LIMIT 1')->fetchColumn() ?: null;
        }
        if (in_array('users', $tables, true)) {
            $representative['first_user_email'] = $pdo->query('SELECT email FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: null;
        }

        $fkEnforced = true;
        $violations = [];
        if ($driver === 'sqlite') {
            $fkEnforced = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($driver === 'mysql') {
            $fkEnforced = (int) $pdo->query('SELECT @@foreign_key_checks')->fetchColumn() === 1;
        }

        return [
            'driver' => $driver,
            'tables' => $tables,
            'migrations_present' => in_array('migrations', $tables, true),
            'foreign_keys_enforced' => $fkEnforced,
            'foreign_key_violations' => $violations,
            'row_counts' => $rowCounts,
            'representative' => $representative,
        ];
    }

    private static function tables(PDO $pdo, string $driver): array
    {
        if ($driver === 'sqlite') {
            return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        }

        return array_map(fn ($row) => $row[0], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
    }

    private static function quoteIdent(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private static function normalizePath(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    private static function writeDefaultsFile(array $config): string
    {
        $lines = ['[client]'];
        if (! empty($config['host'])) {
            $lines[] = 'host='.$config['host'];
        }
        if (! empty($config['port'])) {
            $lines[] = 'port='.$config['port'];
        }
        if (! empty($config['username'])) {
            $lines[] = 'user='.$config['username'];
        }
        if (! empty($config['password'])) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '""'], (string) $config['password']);
            $lines[] = 'password="'.$escaped.'"';
        }

        $path = tempnam(sys_get_temp_dir(), 'veritas-restore-');
        if ($path === false) {
            throw new BackupException('Unable to create a temporary credentials file.');
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    private static function resolveBinary(string $name, ?string $explicit): string
    {
        $candidates = $explicit ? [$explicit] : [$name, $name.'.exe'];
        if (! $explicit && is_dir('C:/laragon/bin/mysql')) {
            foreach (glob('C:/laragon/bin/mysql/*/bin/'.$name.'.exe') ?: [] as $path) {
                $candidates[] = $path;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (self::locatable($candidate)) {
                return $candidate;
            }
        }

        throw new BackupException("The {$name} executable was not found. Pass --binary to specify its full path.");
    }

    private static function locatable(string $candidate): bool
    {
        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return is_file($candidate);
        }

        $finder = \DIRECTORY_SEPARATOR === '\\' ? 'where' : 'which';
        $process = new Process([$finder, $candidate]);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }
}

