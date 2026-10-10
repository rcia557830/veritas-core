<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\FileBackup;
use Illuminate\Console\Command;

class BackupFiles extends Command
{
    protected $signature = 'veritas:backup:files
                            {--retention=14 : Number of days to retain file backups}
                            {--include-public : Also archive the public disk (firm logo)}';

    protected $description = 'Archive the private-disk attachments into a timestamped ZIP with a sidecar manifest';

    public function handle(FileBackup $backup): int
    {
        try {
            $result = $backup->run([
                'retention' => (int) $this->option('retention'),
                'include_public' => (bool) $this->option('include-public'),
            ]);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('File backup created: '.$result['file']);
        $this->info('Size: '.number_format($result['size_bytes']).' bytes; SHA-256: '.$result['sha256']);

        return self::SUCCESS;
    }
}
