<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class VoucherConcurrencyTest extends TestCase
{
    use VoucherFixtures;

    public static function requests(): array
    {
        return [['new', 'JV-000002', 2], ['retry', 'JV-000001', 1], ['duplicate-check', 'check_number', 1]];
    }

    #[DataProvider('requests')]
    public function test_concurrent_creation_preserves_numbers_and_one_journal_per_request(string $operation, string $expected, int $count): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        $creator = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->actingAs($creator);
        $client = Client::firstOrFail();
        $payload = $this->voucherPayload($client, $operation === 'duplicate-check' ? 'CV' : 'JV');
        $other = $operation === 'retry' ? $payload : array_replace($payload, ['creation_token' => (string) Str::uuid()]);
        $before = LedgerEntry::count();
        $ready = tempnam(sys_get_temp_dir(), 'veritas-voucher-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            VoucherWriter::save($payload);
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/voucher-worker.php'), json_encode(['user' => $creator->id, 'payload' => $other], JSON_THROW_ON_ERROR), $ready],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $this->assertTrue($waiting, 'The competing voucher must wait for the held client lock.');
            DB::commit();
            $this->assertSame($expected, stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertSame($count, Voucher::count());
            $this->assertSame($before + $count, LedgerEntry::count());
            $this->assertSame($count, Voucher::distinct()->count('ledger_entry_id'));
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
