<?php

namespace Tests\Feature;

use App\Models\AccountTemplate;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountInitializationConcurrencyTest extends TestCase
{
    public static function operations(): array
    {
        return [['initialize', '{"created":0,"existing":2}'], ['edit', 'used-template-rejected']];
    }

    #[DataProvider('operations')]
    public function test_mysql_concurrent_initialization_and_template_edits_are_serialized(string $operation, string $expected): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the separately verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);
        $client = Client::firstOrFail();
        $template = AccountTemplate::create(['name' => 'SYNTHETIC CONCURRENCY', 'version' => 1, 'is_active' => true]);
        foreach (['001', '002'] as $code) {
            $template->items()->create(['code' => $code, 'name' => 'SYNTHETIC '.$code, 'classification' => 'Asset']);
        }
        $ready = tempnam(sys_get_temp_dir(), 'veritas-initialize-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            // First initialization holds both locks and has uncommitted accounts.
            $this->assertSame(['created' => 2, 'existing' => 0], ChartOfAccounts::initialize($client, $template));
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/account-initialization-worker.php'), json_encode([
                'client' => $client->id, 'template' => $template->id, 'user' => $owner->id, 'operation' => $operation,
            ], JSON_THROW_ON_ERROR), $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            while (file_get_contents($ready) !== 'ready' && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertSame('ready', file_get_contents($ready));
            // Verify the child actually reached an InnoDB lock wait before commit.
            $waiting = false;
            while (microtime(true) < $deadline) {
                if ((int) DB::scalar('SELECT COUNT(*) FROM performance_schema.data_lock_waits') > 0) {
                    $waiting = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'The competing writer must wait on the first transaction.');
            DB::commit();
            $this->assertSame($expected, stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertSame(2, $client->accounts()->count());
            $this->assertSame(2, $template->items()->count());
            $this->assertSame(2, DB::table('audit_logs')->where('module', 'accounts')->count());
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
