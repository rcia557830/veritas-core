<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Archives the filesystem-backed application data (uploaded documents,
 * retained journal evidence, and any other private-disk files) into a
 * timestamped ZIP under the private backup directory. Disposable test
 * artifacts and the backup directory itself are always excluded.
 */
final class FileBackup
{
    private const EXCLUDED = ['backups', 'increment32-', 'stage'];

    /**
     * @param  array{retention?: int, include_public?: bool}  $options
     */
    public function run(array $options = []): array
    {
        $kind = 'files';
        $dir = BackupStore::ensureDirectory($kind);
        $retention = max(1, (int) ($options['retention'] ?? 14));
        $name = 'veritas-files-'.BackupStore::timestamp().'.zip';
        $target = $dir.DIRECTORY_SEPARATOR.$name;

        $privateRoot = config('filesystems.disks.local.root', storage_path('app/private'));
        $this->zipDirectory($privateRoot, $target);

        if (! empty($options['include_public'])) {
            $publicRoot = config('filesystems.disks.public.root', storage_path('app/public'));
            if (is_dir($publicRoot)) {
                $this->zipDirectory($publicRoot, $target, true);
            }
        }

        if (! is_file($target) || filesize($target) <= 0) {
            @unlink($target);
            throw new BackupException('The file backup archive is missing or empty.');
        }

        $result = [
            'type' => 'files',
            'created_at' => now()->toIso8601String(),
            'status' => 'ok',
            'file' => $name,
            'size_bytes' => filesize($target),
            'sha256' => BackupStore::sha256($target),
            'retention_days' => $retention,
            'pruned' => BackupStore::prune($kind, $retention),
        ];
        BackupStore::writeManifest($kind, $result);
        Log::info('File backup completed.', $result);

        return $result;
    }

    private function zipDirectory(string $source, string $target, bool $append = false): void
    {
        if (! is_dir($source)) {
            return;
        }

        $zip = new ZipArchive;
        $mode = $append ? ZipArchive::CREATE : (ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($zip->open($target, $mode) !== true) {
            throw new BackupException("Unable to open the backup archive: {$target}");
        }

        $rootLength = strlen(rtrim(str_replace('\\', '/', $source), '/')) + 1;
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            fn ($item) => ! $this->excluded(substr(str_replace('\\', '/', $item->getPathname()), $rootLength)),
        );
        $iterator = new \RecursiveIteratorIterator($filter);

        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), $rootLength);
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($file->getPathname(), $relative);
            }
        }

        $zip->close();
    }

    private function excluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

}
