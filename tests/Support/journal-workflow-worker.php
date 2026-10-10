<?php

use App\Models\LedgerEntry;
use App\Services\Accounting\JournalWriter;
use Illuminate\Auth\Access\AuthorizationException;
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
    if ($data['operation'] === 'edit') {
        JournalWriter::save($data['payload'], $entry);
    } else {
        JournalWriter::transition($entry, 'submit', null);
    }
    echo 'unexpected-success';
    exit(1);
} catch (AuthorizationException $error) {
    echo 'edit-forbidden';
} catch (ValidationException $error) {
    echo isset($error->errors()['items']) ? 'ineligible-account' : 'unexpected-validation';
}
