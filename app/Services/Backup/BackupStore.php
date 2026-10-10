<?php

namespace App\Services\Backup;

/**
 * Shared storage helpers for Veritas Core backup artifacts.
 *
 * All artifacts and their sidecar manifests live under the private local disk
 * (storage/app/private/backups), which is excluded from the web root and from
 * Git (storage/app/private/.gitignore contains "*"). Nothing is ever written
 * under public/.
 */
final class BackupStore
{
    public static function basePath(): string
    {
        return storage_path('app/private/backups');
    }

    public static function ensureDirectory(string $kind): string
    {
        $dir = static::basePath().DIRECTORY_SEPARATOR.$kind;
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public static function timestamp(): string
    {
        return now()->format('Ymd-His');
    }

    public static function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new BackupException("Unable to hash backup artifact: {$path}");
        }

        return $hash;
    }

    public static function writeManifest(string $kind, array $manifest): string
    {
        $dir = static::ensureDirectory($kind);
        $name = $manifest['file'] ?? 'unknown';
        $path = $dir.DIRECTORY_SEPARATOR.$name.'.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);

        return $path;
    }

    /**
     * Remove backup artifacts older than the retention window. Returns the
     * number of backup sets removed.
     */
    public static function prune(string $kind, int $retentionDays): int
    {
        $dir = static::ensureDirectory($kind);
        $cutoff = now()->subDays(max(1, $retentionDays))->getTimestamp();
        $removed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $manifestFile) {
            $mtime = filemtime($manifestFile);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }
            $artifact = substr($manifestFile, 0, -5); // strip trailing ".json"
            if (is_file($artifact)) {
                @unlink($artifact);
            }
            @unlink($manifestFile);
            $removed++;
        }

        return $removed;
    }
}
