<?php

use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
if (config('database.default') !== 'mysql') {
    throw new RuntimeException('Browser fixtures require guarded disposable MySQL.');
}
config(['demo.enabled' => true, 'filesystems.disks.local.root' => dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-files']);
foreach (DemoDataSeeder::ROLES as $role) {
    config(['demo.passwords.'.$role => hash_hmac('sha256', $role, getenv('VERITAS_TEST_TOKEN'))]);
}
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
echo "Disposable browser fixtures prepared.\n";
