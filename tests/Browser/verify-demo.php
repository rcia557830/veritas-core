<?php

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Invoice;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
if (config('database.default') !== 'mysql') {
    throw new RuntimeException('Demo browser verification requires disposable MySQL.');
}
$invoice = Invoice::where('invoice_number', 'DEMO-002-BILL-001')->sole();
if ($invoice->balance_cents !== 0 || $invoice->paid_cents !== 650000 || $invoice->payments()->count() !== 2 || $invoice->display_status !== 'Paid') {
    throw new RuntimeException('Browser payment completion did not reconcile.');
}
if ($invoice->payments()->where('reference_number', 'PHASE6-FINAL-PAYMENT')->sole()->amount !== '4500.00') {
    throw new RuntimeException('Browser overpayment rejection failed.');
}
if (ComplianceRecord::findOrFail(1)->status !== 'Completed') {
    throw new RuntimeException('Browser compliance completion did not persist.');
}
if (Client::findOrFail(1)->phone !== '+63 900 000 9999' || Client::findOrFail(1)->tin !== '000-000-999') {
    throw new RuntimeException('Browser fictional client edit did not persist.');
}
echo "PASS: Phase 6 fictional client edit, completed payment, rejected overpayment and completed compliance independently verified in MySQL.\n";
