<?php

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Voucher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
if (config('database.default') !== 'mysql') {
    throw new RuntimeException('Browser verification requires disposable MySQL.');
}
config(['filesystems.disks.local.root' => dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-files']);
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$client = Client::where('business_name', 'SYNTHETIC Browser Client')->firstOrFail();
$check($client->onboarded_at !== null, 'Browser client was not onboarded.');
$document = Document::where('title', 'SYNTHETIC Browser Registration Upload')->firstOrFail();
$check($document->client_id === $client->id && $document->status === 'Approved', 'Wrong document ownership or review.');
$check(Storage::disk('local')->exists($document->file_path), 'Uploaded browser attachment is missing.');
$check(hash_file('sha256', Storage::disk('local')->path($document->file_path)) === $document->file_sha256, 'Attachment hash mismatch.');
$check($document->requirements()->where('client_id', $client->id)->count() === 1, 'Requirement matching not persisted.');
$check(AccountingPeriod::where('client_id', $client->id)->where('status', 'Open')->count() === 1, 'Browser calendar missing.');
$compliance = ComplianceRecord::where('requirement', 'SYNTHETIC Browser Compliance')->firstOrFail();
$check($compliance->submission_deadline->toDateString() === '2026-12-21', 'Internal target is not ten days before filing.');
$check($compliance->followUps()->where('remarks', 'SYNTHETIC browser follow-up saved')->exists(), 'Follow-up was not persisted.');
$invoice = Invoice::whereHas('items', fn ($q) => $q->where('description', 'SYNTHETIC Browser Service'))->firstOrFail();
$check($invoice->total_cents === 10000 && $invoice->paid_cents === 2500 && $invoice->balance_cents === 7500, 'Browser billing reconciliation failed.');
$check($invoice->payments()->count() === 1, 'Browser payment count is incorrect.');
$check(LedgerEntry::posted()->whereColumn('created_by', 'reviewed_by')->count() === 0, 'Self-reviewed posting detected.');
$check(Voucher::whereHas('journal', fn ($q) => $q->posted())->count() >= 4, 'Posted browser vouchers missing.');
echo "PASS: Persisted browser client, attachment hash, requirement link, approval, onboarding, calendar, compliance target/follow-up, billing balance and independent voucher postings.\n";
