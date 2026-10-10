<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Consistent MySQL/MariaDB (mysqldump) and SQLite database backups.
 *
 * Credentials are never exposed on the command line, in shell history, or in
 * logs: mysqldump reads them from a temporary option file written with the
 * tightest permissions and deleted immediately afterwards.
 */
final class DatabaseBackup
{
    /**
     * @param  array{retention?: int, binary?: ?string}  $options
     */
    public function run(array $options = []): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $driver = strtolower($config['driver'] ?? $connection);
        $retention = max(1, (int) ($options['retention'] ?? 14));
        $kind = 'database';
        $dir = BackupStore::ensureDirectory($kind);

        $result = match ($driver) {
            'mysql', 'mariadb' => $this->runMysql($config, $driver, $dir, $options),
            'sqlite' => $this->runSqlite($config, $dir, $options),
            default => throw new BackupException("Unsupported database driver for backup: {$driver}"),
        };

        $result['retention_days'] = $retention;
        $result['pruned'] = BackupStore::prune($kind, $retention);
        BackupStore::writeManifest($kind, $result);
        Log::info('Database backup completed.', $this->loggable($result));

        return $result;
    }

    private function runMysql(array $config, string $driver, string $dir, array $options): array
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            throw new BackupException('No database name is configured for the default connection.');
        }

        $binary = $this->resolveBinary('mysqldump', $options['binary'] ?? null);
        $defaultsFile = $this->writeDefaultsFile($config);
        $name = 'veritas-core-'.BackupStore::timestamp().'-'.$database.'.sql';
        $target = $dir.DIRECTORY_SEPARATOR.$name;

        $args = array_merge(
            ['--defaults-extra-file='.$defaultsFile],
            ['--single-transaction', '--triggers', '--hex-blob', '--no-tablespaces'],
            [$database],
        );

        try {
            $fh = fopen($target, 'wb');
            if ($fh === false) {
                throw new BackupException('Unable to open the backup target for writing.');
            }

            $stderr = '';
            $process = new Process(array_merge([$binary], $args));
            $process->setTimeout(null);
            $process->run(function ($type, $buffer) use ($fh, &$stderr) {
                if ($type === Process::OUT) {
                    fwrite($fh, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });
            fclose($fh);

            if (! $process->isSuccessful()) {
                @unlink($target);
                throw new BackupException('mysqldump failed: '.trim($stderr));
            }
        } finally {
            @unlink($defaultsFile);
        }

        return $this->finalize($dir, $name, $target, $config, $driver);
    }

    private function runSqlite(array $config, string $dir, array $options): array
    {
        $database = $config['database'] ?? database_path('database.sqlite');
        if ($database === '' || $database === ':memory:') {
            throw new BackupException('In-memory SQLite databases cannot be backed up.');
        }
        if (! is_file($database)) {
            throw new BackupException("SQLite database file not found: {$database}");
        }

        $name = 'veritas-core-'.BackupStore::timestamp().'-'.basename($database);
        $target = $dir.DIRECTORY_SEPARATOR.$name;

        // VACUUM INTO produces a consistent snapshot without touching source data.
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec("VACUUM INTO '".str_replace("'", "''", $target)."'");
        unset($pdo);

        return $this->finalize($dir, $name, $target, $config, 'sqlite');
    }

    private function finalize(string $dir, string $name, string $target, array $config, string $driver): array
    {
        if (! is_file($target) || filesize($target) <= 0) {
            @unlink($target);
            throw new BackupException('The backup artifact is missing or empty.');
        }

        return [
            'type' => 'database',
            'created_at' => now()->toIso8601String(),
            'status' => 'ok',
            'database' => (string) ($config['database'] ?? ''),
            'driver' => $driver,
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'file' => $name,
            'size_bytes' => filesize($target),
            'sha256' => BackupStore::sha256($target),
        ];
    }

    private function loggable(array $result): array
    {
        return array_diff_key($result, array_flip(['password']));
    }

    /**
     * Write a temporary MySQL option file holding the connection credentials.
     */
    private function writeDefaultsFile(array $config): string
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
            $password = (string) $config['password'];
            $escaped = str_replace(['\\', '"'], ['\\\\', '""'], $password);
            $lines[] = 'password="'.$escaped.'"';
        }

        $path = tempnam(sys_get_temp_dir(), 'veritas-db-');
        if ($path === false) {
            throw new BackupException('Unable to create a temporary credentials file.');
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    private function resolveBinary(string $name, ?string $explicit): string
    {
        $candidates = [];
        if ($explicit) {
            $candidates[] = $explicit;
        } else {
            $candidates[] = $name;
            $candidates[] = $name.'.exe';
            if (is_dir('C:/laragon/bin/mysql')) {
                foreach (glob('C:/laragon/bin/mysql/*/bin/'.$name.'.exe') ?: [] as $path) {
                    $candidates[] = $path;
                }
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($this->locatable($candidate)) {
                return $candidate;
            }
        }

        throw new BackupException("The {$name} executable was not found. Pass --binary to specify its full path.");
    }

    private function locatable(string $candidate): bool
    {
        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return is_file($candidate);
        }

        // Bare name: search the system PATH.
        $finder = \DIRECTORY_SEPARATOR === '\\' ? 'where' : 'which';
        $process = new Process([$finder, $candidate]);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }
}

