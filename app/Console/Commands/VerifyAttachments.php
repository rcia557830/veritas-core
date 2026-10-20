<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VerifyAttachments extends Command
{
    protected $signature = 'veritas:verify-attachments {--disk= : Defaults to DOCUMENTS_DISK}';

    protected $description = 'Read-only SHA-256 verification of all current and historical attachment references';

    public function handle(): int
    {
        $disk = Storage::disk($this->option('disk') ?: config('filesystems.documents'));
        $verified = [];
        $failures = 0;
        $references = 0;
        foreach (['documents' => 'file_sha256', 'journal_documents' => 'sha256'] as $table => $column) {
            // Query builder includes archived records and detached evidence.
            foreach (DB::table($table)->whereNotNull('file_path')->where('file_path', '!=', '')->orderBy('id')->cursor() as $row) {
                $references++;
                try {
                    if (! isset($verified[$row->file_path])) {
                        $stream = $disk->readStream($row->file_path);
                        if (! is_resource($stream)) {
                            throw new \RuntimeException('Unreadable attachment');
                        }
                        try {
                            $hash = hash_init('sha256');
                            hash_update_stream($hash, $stream);
                            $verified[$row->file_path] = hash_final($hash);
                        } finally {
                            fclose($stream);
                        }
                    }
                    if (! $row->{$column} || ! hash_equals($row->{$column}, $verified[$row->file_path])) {
                        throw new \RuntimeException('Missing or mismatched digest');
                    }
                } catch (\Throwable $error) {
                    // Do not print SDK exceptions, endpoints or credentials.
                    $this->error("Verification failed: {$table} record {$row->id}.");
                    $failures++;
                }
            }
        }
        $this->info(count($verified)." objects, {$references} references, {$failures} failures.");

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
