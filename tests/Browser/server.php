<?php

// Explicit opt-in test router. Never used by public/index.php or production routes.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
if (config('database.default') !== 'mysql') {
    throw new RuntimeException('Browser tests require the verified disposable MySQL database.');
}
$sessions = dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-sessions';
if (! is_dir($sessions)) {
    mkdir($sessions, 0700, true);
}
config(['session.driver' => 'file', 'session.files' => $sessions, 'session.secure' => false, 'cache.default' => 'array', 'mail.default' => 'array']);
config(['filesystems.disks.local.root' => dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-files']);
$path = realpath(public_path(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
if ($path && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', public_path()).'/') && is_file($path) && pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
$app->handleRequest(Request::capture());
