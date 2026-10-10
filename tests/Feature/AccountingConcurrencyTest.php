<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\TestCase;

class AccountingConcurrencyTest extends TestCase
{
    use AccountingFixtures;

    public function test_mysql_concurrent_overlapping_periods_are_serialized(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the separately verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $client = $this->syntheticClient();
        $year = $this->syntheticYear($client);
        $ready = tempnam(sys_get_temp_dir(), 'veritas-period-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/accounting-period-worker.php'), json_encode([
                'client_id' => $client->id, 'accounting_year_id' => $year->id, 'label' => 'Synthetic concurrent overlap',
                'starts_on' => '2026-04-15', 'ends_on' => '2026-05-15',
            ], JSON_THROW_ON_ERROR), $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            while (file_get_contents($ready) !== 'ready' && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertSame('ready', file_get_contents($ready));
            AccountingPeriod::create(['client_id' => $client->id, 'accounting_year_id' => $year->id,
                'label' => 'Synthetic first writer', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30']);
            DB::commit();
            $this->assertSame('overlap-rejected', stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertSame(1, AccountingPeriod::where('client_id', $client->id)->count());
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
