<?php

use App\Services\EmployeeInvitations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[1], 'ready');
try {
    EmployeeInvitations::accept($data['id'], $data['token'], $data['email'], 'Competing-password-123');
    echo 'accepted';
} catch (ValidationException $error) {
    echo 'rejected';
}
