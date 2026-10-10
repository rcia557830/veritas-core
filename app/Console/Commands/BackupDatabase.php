<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'veritas:backup:database
                            {--retention=14 : Number of days to retain database backups}
                            {--binary= : Explicit path to the mysqldump executable}';

    protected $description = 'Create a consistent, timestamped database backup with a sidecar manifest';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $result = $backup->run([
                'retention' => (int) $this->option('retention'),
                'binary' => $this->option('binary') ?: null,
            ]);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Database backup created: '.$result['file']);
        $this->info('Size: '.number_format($result['size_bytes']).' bytes; SHA-256: '.$result['sha256']);
        $this->info('Retention prune removed '.$result['pruned'].' expired backup(s).');

        return self::SUCCESS;
    }
}
