<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreVerification;
use Illuminate\Console\Command;

class RestoreVerify extends Command
{
    protected $signature = 'veritas:restore:verify
                            {--file= : Path to the backup artifact (.sql or SQLite file)}
                            {--target= : Target database name (MySQL) or file path (SQLite)}
                            {--driver= : Target driver (mysql|sqlite); defaults to the default DB driver}
                            {--force : Bypass production and disposable-naming safeguards}';

    protected $description = 'Restore a backup into a disposable target and verify its integrity';

    public function handle(): int
    {
        $file = $this->option('file');
        $target = $this->option('target');
        $driver = strtolower($this->option('driver') ?: config('database.default'));
        $force = (bool) $this->option('force');

        if (! $file || ! $target) {
            $this->error('The --file and --target options are required.');

            return self::FAILURE;
        }

        $primary = config("database.connections.".config('database.default'));
        $targetConfig = array_merge($primary, ['database' => $target, 'driver' => $driver]);

        try {
            RestoreVerification::preflight($primary, $targetConfig, null, $force);

            if ($driver === 'sqlite') {
                $pdo = RestoreVerification::restoreSqlite($file, $target);
            } else {
                $pdo = RestoreVerification::restoreMysql($targetConfig, $file);
            }

            $report = RestoreVerification::verify($pdo);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore verification complete (driver: '.$report['driver'].').');
        $this->info('Migrations table present: '.($report['migrations_present'] ? 'yes' : 'no'));
        $this->info('Foreign keys enforced: '.($report['foreign_keys_enforced'] ? 'yes' : 'no'));
        $this->info('Foreign-key violations: '.count($report['foreign_key_violations']));
        $this->info('Row counts: '.json_encode($report['row_counts']));
        $this->info('Representative records: '.json_encode($report['representative']));

        if (! $report['migrations_present'] || ! $report['foreign_keys_enforced'] || count($report['foreign_key_violations']) > 0) {
            $this->error('Restore verification failed integrity checks.');

            return self::FAILURE;
        }

        $this->info('Restored data verified successfully.');

        return self::SUCCESS;
    }
}
