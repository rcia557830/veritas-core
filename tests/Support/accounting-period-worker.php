<?php

use App\Models\AccountingPeriod;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseGuard;

// Invoked only by the isolated MySQL concurrency test; never an application route.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$data = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[2], 'ready');
try {
    AccountingPeriod::create($data);
    echo 'unexpected-success';
    exit(1);
} catch (ValidationException $error) {
    echo isset($error->errors()['starts_on']) ? 'overlap-rejected' : 'unexpected-validation';
}
