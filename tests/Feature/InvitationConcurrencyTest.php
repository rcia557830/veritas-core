<?php

namespace Tests\Feature;

use App\Models\EmployeeInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeInvitations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvitationConcurrencyTest extends TestCase
{
    public static function operations(): array
    {
        return [['accept'], ['revoke'], ['deactivate']];
    }

    #[DataProvider('operations')]
    public function test_acceptance_waits_for_competing_account_changes_and_fails_closed(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the verified disposable MySQL server.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        $user = User::create(['name' => 'Concurrent invitation', 'email' => 'race@example.test', 'password' => 'Unusable-placeholder', 'status' => 'Pending Invitation', 'role_id' => Role::where('slug', 'bookkeeper')->value('id')]);
        $token = bin2hex(random_bytes(32));
        $invitation = EmployeeInvitation::create(['user_id' => $user->id, 'role_id' => $user->role_id, 'email' => $user->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $ready = tempnam(sys_get_temp_dir(), 'veritas-invite-');
        $worker = null;
        $pipes = [];
        DB::beginTransaction();
        try {
            Role::orderBy('id')->lockForUpdate()->get();
            $locked = User::lockForUpdate()->findOrFail($user->id);
            match ($operation) {
                'accept' => EmployeeInvitations::accept($invitation->id, $token, $user->email, 'Original-password-123'),
                'revoke' => EmployeeInvitations::revoke($locked),
                'deactivate' => $locked->update(['status' => 'Inactive']),
            };
            $worker = proc_open([PHP_BINARY, base_path('tests/Support/invitation-worker.php'), $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($worker);
            fwrite($pipes[0], json_encode(['id' => $invitation->id, 'token' => $token, 'email' => $user->email], JSON_THROW_ON_ERROR));
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
            $this->assertTrue($waiting, 'Acceptance must wait for the held InnoDB lock.');
            DB::commit();
            $this->assertSame('rejected', stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
            $this->assertFalse(Hash::check('Competing-password-123', $user->fresh()->password));
            $this->assertSame($operation === 'accept' ? 1 : 0, EmployeeInvitation::whereNotNull('accepted_at')->count());
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
