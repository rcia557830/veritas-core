<?php

use App\Models\LedgerEntry;
use App\Services\Accounting\JournalPosting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
$data = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
auth()->loginUsingId($data['user']);
$entry = LedgerEntry::findOrFail($data['entry']);
file_put_contents($argv[2], 'ready');
try {
    match ($data['operation']) {
        'close' => $entry->accountingPeriod->forceFill(['status' => 'Closed', 'closed_by' => auth()->id(), 'closed_at' => now()])->save(),
        'deactivate' => $entry->items()->first()->account->update(['is_active' => false]),
        default => JournalPosting::post($entry),
    };
    echo 'success';
} catch (ValidationException $error) {
    echo array_key_first($error->errors());
}
