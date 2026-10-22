<?php

use App\Support\DemoDatabase;
use Database\Seeders\DemoNoticeSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DemoDatabase::check();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath(database_path('veritas_demo.sqlite'))) {
    throw new RuntimeException('Only the isolated veritas_demo.sqlite may be populated.');
}
$backup = storage_path('app/private/notices-before-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite');
DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));
echo 'Backup: '.$backup.PHP_EOL;
$tables = ['clients', 'documents', 'document_requirements', 'ledger_entries', 'ledger_items', 'vouchers', 'compliance_records', 'knowledge_articles', 'users', 'invoices', 'payments', 'notifications'];
$snapshot = fn ($names) => collect($names)->mapWithKeys(fn ($table) => [$table => hash('sha256', DB::table($table)->orderBy('id')->get()->toJson())])->all();
$before = $snapshot($tables);
$existing = DB::table('notices')->orderBy('id')->get();
Artisan::call('db:seed', ['--class' => DemoNoticeSeeder::class, '--force' => true]);
if ($before !== $snapshot($tables) || $existing->toJson() !== DB::table('notices')->whereIn('id', $existing->pluck('id'))->orderBy('id')->get()->toJson()) {
    throw new RuntimeException('Existing records changed. Review the backup before continuing.');
}
$seeded = $snapshot(['notices', 'audit_logs']);
Artisan::call('db:seed', ['--class' => DemoNoticeSeeder::class, '--force' => true]);
if ($seeded !== $snapshot(['notices', 'audit_logs']) || $before !== $snapshot($tables)) {
    throw new RuntimeException('Repeat seeding changed records.');
}
if (DB::selectOne('PRAGMA integrity_check')->integrity_check !== 'ok' || DB::select('PRAGMA foreign_key_check') !== []) {
    throw new RuntimeException('Database integrity check failed.');
}
echo json_encode([
    'total' => DB::table('notices')->count(),
    'statuses' => DB::table('notices')->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status'),
    'general' => DB::table('notices')->whereNull('client_id')->count(),
    'client_specific' => DB::table('notices')->whereNotNull('client_id')->count(),
    'preserved_tables_sha256' => $before,
], JSON_PRETTY_PRINT).PHP_EOL;
echo "PASS: existing records unchanged; repeat run unchanged; SQLite integrity and foreign keys valid.\n";
