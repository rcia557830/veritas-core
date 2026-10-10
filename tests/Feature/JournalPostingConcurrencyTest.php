<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JournalFixtures;
use Tests\TestCase;

class JournalPostingConcurrencyTest extends TestCase
{
    use JournalFixtures;

    public static function operations(): array
    {
        return [['post', 'post', 'posting'], ['close', 'post', 'accounting_period_id'], ['deactivate', 'post', 'items'], ['post', 'close', 'success'], ['post', 'deactivate', 'success']];
    }

    #[DataProvider('operations')]
    public function test_posting_serializes_with_competing_writes(string $first, string $second, string $expected): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        $creator = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $entry = $this->structuredJournal($creator);
        $this->actingAs($creator);
        JournalWriter::transition($entry, 'submit', null);
        $this->actingAs($manager);
        JournalWriter::transition($entry, 'review', null);
        $ready = tempnam(sys_get_temp_dir(), 'veritas-post-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            Client::whereKey($entry->client_id)->lockForUpdate()->firstOrFail();
            match ($first) {
                'close' => $entry->accountingPeriod->forceFill(['status' => 'Closed', 'closed_by' => $manager->id, 'closed_at' => now()])->save(),
                'deactivate' => $entry->items()->first()->account->update(['is_active' => false]),
                default => JournalPosting::post($entry),
            };
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/journal-posting-worker.php'), json_encode([
                'entry' => $entry->id, 'user' => $manager->id, 'operation' => $second,
            ], JSON_THROW_ON_ERROR), $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            fclose($pipes[0]);
            $deadline = microtime(true) + 6;
            while (file_get_contents($ready) !== 'ready' && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertSame('ready', file_get_contents($ready));
            $waiting = false;
            while (microtime(true) < $deadline) {
                if ((int) DB::scalar('SELECT COUNT(*) FROM performance_schema.data_lock_waits') > 0) {
                    $waiting = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'The competing operation must actually wait for the held InnoDB lock.');
            DB::commit();
            $this->assertSame($expected, stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertSame($first === 'post' ? 'Posted' : 'Reviewed', $entry->fresh()->status);
            $this->assertSame($first === 'post' ? 1 : 0, AuditLog::where('module', 'ledger')->where('record_id', $entry->id)->where('action', 'post')->count());
            $this->assertSame('100.00', $entry->fresh()->total_debit);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (array_slice($pipes, 1) as $pipe) {
                fclose($pipe);
            }
            if (is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
            unlink($ready);
        }
    }
}
