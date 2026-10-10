<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\JournalWriter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JournalFixtures;
use Tests\TestCase;

class JournalConcurrencyTest extends TestCase
{
    use JournalFixtures;

    public static function operations(): array
    {
        return [['submit', 'ineligible-account'], ['edit', 'edit-forbidden']];
    }

    #[DataProvider('operations')]
    public function test_concurrent_eligibility_and_edits_use_locked_current_state(string $operation, string $expected): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->actingAs($bookkeeper);
        $entry = $this->structuredJournal($bookkeeper);
        $payload = $this->journalPayload($entry->client);
        $ready = tempnam(sys_get_temp_dir(), 'veritas-journal-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            Client::whereKey($entry->client_id)->lockForUpdate()->firstOrFail();
            if ($operation === 'submit') {
                $entry->items->first()->account->update(['is_active' => false]);
            } else {
                JournalWriter::transition($entry, 'submit', null);
            }
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/journal-workflow-worker.php'), json_encode([
                'entry' => $entry->id, 'user' => $bookkeeper->id, 'operation' => $operation, 'payload' => $payload,
            ], JSON_THROW_ON_ERROR), $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
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
            $this->assertTrue($waiting, 'Competing journal operation must wait.');
            DB::commit();
            $this->assertSame($expected, stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertSame($operation === 'submit' ? 'Draft' : 'For Review', $entry->fresh()->status);
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
