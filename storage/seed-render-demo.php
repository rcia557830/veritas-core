<?php
require __DIR__.'/../vendor/autoload.php';
$settings = Dotenv\Dotenv::createArrayBacked(dirname(__DIR__), '.env.render-demo')->load();
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY', 'DEMO_OWNER_EMAIL', 'DEMO_STAFF_PASSWORD'] as $key) {
    putenv($key.'='.$settings[$key]);
    $_ENV[$key] = $_SERVER[$key] = $settings[$key];
}
foreach (['APP_ENV' => 'production', 'DB_CONNECTION' => 'mysql', 'DEMO_MODE' => 'true', 'DEMO_SKIP_ACCOUNTING_TRIGGERS' => 'true', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.connections.mysql.host') !== $settings['DB_HOST'] || config('database.connections.mysql.database') !== $settings['DB_DATABASE']) {
        throw new RuntimeException('Target configuration mismatch.');
    }
    $before = [];
    foreach (['users', 'clients', 'documents', 'compliance_records', 'ledger_entries', 'invoices', 'payments'] as $table) {
        $before[$table] = Illuminate\Support\Facades\DB::table($table)->count();
    }
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\DemoSeeder::class, '--force' => true]);
    $after = [];
    foreach (array_keys($before) as $table) {
        $after[$table] = Illuminate\Support\Facades\DB::table($table)->count();
    }
    echo json_encode(['before' => $before, 'after' => $after]);
} catch (Throwable $e) {
    echo 'Hosted demo seeding failed: '.get_class($e).' (details withheld).';
    exit(1);
}
