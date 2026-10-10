<?php

use App\Services\Accounting\VoucherWriter;
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
file_put_contents($argv[2], 'ready');
try {
    $voucher = VoucherWriter::save($data['payload']);
    echo $voucher->reference;
} catch (ValidationException $error) {
    echo array_key_first($error->errors());
}
