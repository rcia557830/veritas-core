<?php

use App\Models\Setting;
use App\Support\DemoDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Local operator entry point; never loaded by the web application or deployment.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DemoDatabase::check();
// A new empty SQLite file or a previously initialized demo is required before migrations.
$db = DB::connection();
if ($db->getDriverName() !== 'sqlite') {
    throw new RuntimeException('The local demo launcher only supports its dedicated SQLite file.');
}
$tables = $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
if ($tables && (! Schema::hasTable('settings') || ! Setting::where('firm_name', 'VERITAS FICTIONAL PHASE 6 DEMO')->exists())) {
    throw new RuntimeException('Existing unmarked database refused before migration. Use a new disposable demo file.');
}
exit(Artisan::call('migrate', ['--force' => true]));
