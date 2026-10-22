<?php

use App\Models\LedgerEntry;
use App\Services\Accounting\JournalReview;
use App\Support\DemoDatabase;
use Database\Seeders\DemoLabelCleanupSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DemoDatabase::check();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath(database_path('veritas_demo.sqlite'))) {
    throw new RuntimeException('Only veritas_demo.sqlite may be cleaned.');
}
$backup = storage_path('app/private/labels-before-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite');
DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));
$tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
$snapshot = function (bool $excludeLabels) use ($tables) {
    return collect($tables)->mapWithKeys(function ($table) use ($excludeLabels) {
        $excluded = $excludeLabels ? (DemoLabelCleanupSeeder::FIELDS[$table] ?? []) : [];
        if ($excludeLabels && $table === 'clients') {
            $excluded[] = 'address';
        }
        $rows = DB::table($table)->get()->map(fn ($row) => array_diff_key((array) $row, array_flip($excluded)))->all();

        return [$table => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    })->all();
};
$protected = $snapshot(true);
$original = $snapshot(false);
$digests = LedgerEntry::whereNotNull('review_digest')->get()->mapWithKeys(fn ($entry) => [$entry->id => JournalReview::digest($entry)])->all();
Artisan::call('db:seed', ['--class' => DemoLabelCleanupSeeder::class, '--force' => true]);
if ($protected !== $snapshot(true)) {
    throw new RuntimeException('A protected field changed.');
}
foreach ($digests as $id => $digest) {
    if ($digest !== JournalReview::digest(LedgerEntry::findOrFail($id))) {
        throw new RuntimeException('A reviewed accounting payload changed.');
    }
}
$cleaned = $snapshot(false);
Artisan::call('db:seed', ['--class' => DemoLabelCleanupSeeder::class, '--force' => true]);
if ($cleaned !== $snapshot(false)) {
    throw new RuntimeException('Cleanup is not idempotent.');
}
if (DB::selectOne('PRAGMA integrity_check')->integrity_check !== 'ok' || DB::select('PRAGMA foreign_key_check') !== []) {
    throw new RuntimeException('Database integrity failed.');
}
echo json_encode(['backup' => $backup, 'changed_tables' => array_keys(array_diff_assoc($original, $cleaned)), 'protected_tables_verified' => count($tables), 'review_payloads_preserved' => count($digests), 'repeat_run' => 'unchanged'], JSON_PRETTY_PRINT).PHP_EOL;
