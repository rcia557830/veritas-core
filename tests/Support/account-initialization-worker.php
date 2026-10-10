<?php

use App\Models\AccountTemplate;
use App\Models\Client;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\TemplateManager;
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
$client = Client::findOrFail($data['client']);
$template = AccountTemplate::findOrFail($data['template']);
file_put_contents($argv[2], 'ready');
if ($data['operation'] === 'initialize') {
    echo json_encode(ChartOfAccounts::initialize($client, $template));
} else {
    try {
        TemplateManager::item($template, ['code' => '003', 'name' => 'SYNTHETIC concurrent addition', 'classification' => 'Asset', 'is_active' => true]);
        echo 'unexpected-success';
        exit(1);
    } catch (ValidationException $error) {
        echo isset($error->errors()['template']) ? 'used-template-rejected' : 'unexpected-validation';
    }
}
