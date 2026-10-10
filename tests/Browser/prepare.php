<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinancialReportFixtures;
use Tests\Support\GeneralLedgerFixtures;
use Tests\Support\JournalFixtures;
use Tests\Support\TestDatabaseGuard;
use Tests\Support\VoucherFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check($app);
if (config('database.default') !== 'mysql') {
    throw new RuntimeException('Browser tests require the verified disposable MySQL database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--force' => true]);
foreach (Client::all() as $client) {
    $client->update(['business_name' => 'SYNTHETIC BROWSER CLIENT '.$client->id]);
}
Setting::first()->update(['firm_name' => 'SYNTHETIC BROWSER DEMO']);
if (in_array('--journal', $argv, true)) {
    $fixtures = new class
    {
        use JournalFixtures;

        public function seedJournalClient(Client $client): void
        {
            $this->journalPayload($client);
        }
    };
    foreach (Client::orderBy('id')->limit(2)->get() as $client) {
        $fixtures->seedJournalClient($client);
    }
    config(['filesystems.disks.local.root' => dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-files']);
    Storage::disk('local')->put('documents/synthetic-browser.pdf', 'SYNTHETIC JOURNAL BROWSER EVIDENCE');
    Document::first()->update(['title' => 'SYNTHETIC Journal Evidence', 'file_path' => 'documents/synthetic-browser.pdf', 'original_file_name' => 'synthetic-browser.pdf', 'mime_type' => 'application/pdf']);
}
if (in_array('--general-ledger', $argv, true)) {
    $fixtures = new class
    {
        use GeneralLedgerFixtures;

        public function seedLedger(): void
        {
            $client = Client::create(['client_code' => 'SYN-GL', 'business_name' => 'SYNTHETIC LEDGER CLIENT', 'business_type' => 'Corporation',
                'created_by' => User::where('email', 'owner@veritascore.local')->value('id'),
                'assigned_to' => User::where('email', 'bookkeeper@veritascore.local')->value('id'), 'status' => 'Active']);
            $this->ledgerJournal($client, '2026-03-31', [100000], reference: 'GL-OPENING');
            $this->ledgerJournal($client, '2026-04-01', [20000], reference: 'GL-DEBIT');
            $this->ledgerJournal($client, '2026-04-30', [-7500], reference: 'GL-CREDIT');
            $this->ledgerJournal($client, '2026-04-15', [100, 200, 300, 400, 500, 600], reference: 'GL-PAGE-DEBITS');
            $this->ledgerJournal($client, '2026-04-15', [-100, -200, -300, -400, -500, -600], reference: 'GL-PAGE-CREDITS');
            $this->ledgerJournal($client, '2026-04-20', [99900], 'Reviewed', 'GL-UNPOSTED-HIDDEN');
            $client->accounts()->where('code', '001-SYN')->firstOrFail()->update(['is_active' => false]);
        }
    };
    $fixtures->seedLedger();
}
if (in_array('--financial-reports', $argv, true)) {
    $fixtures = new class
    {
        use FinancialReportFixtures;

        public function seedReports(): void
        {
            $client = Client::create(['client_code' => 'SYN-FR', 'business_name' => 'SYNTHETIC FINANCIAL CLIENT', 'business_type' => 'Corporation',
                'created_by' => User::where('email', 'owner@veritascore.local')->value('id'),
                'assigned_to' => User::where('email', 'bookkeeper@veritascore.local')->value('id'), 'status' => 'Active']);
            $this->independentReportFixture($client);
            $this->reportJournal($client, '2026-04-15', [100 => 99999, 400 => -99999], 'Reviewed');
        }
    };
    $fixtures->seedReports();
}

if (in_array('--vouchers', $argv, true)) {
    $fixtures = new class
    {
        use VoucherFixtures;

        public function seedVoucherClient(): void
        {
            $client = Client::create(['client_code' => 'SYN-VCH', 'business_name' => 'SYNTHETIC VOUCHER CLIENT', 'business_type' => 'Corporation',
                'created_by' => User::where('email', 'owner@veritascore.local')->value('id'),
                'assigned_to' => User::where('email', 'bookkeeper@veritascore.local')->value('id'), 'status' => 'Active']);
            $this->reportChart($client);
            config(['filesystems.disks.local.root' => dirname(getenv('VERITAS_TEST_DATADIR')).'/browser-files']);
            Storage::disk('local')->put('documents/synthetic-voucher.pdf', 'SYNTHETIC VOUCHER EVIDENCE');
            Document::create(['client_id' => $client->id, 'document_number' => 'SYN-VOUCHER-EVIDENCE', 'title' => 'SYNTHETIC Voucher Evidence', 'document_type' => 'Receipt', 'status' => 'Submitted',
                'received_date' => '2026-04-12', 'file_path' => 'documents/synthetic-voucher.pdf', 'original_file_name' => 'synthetic-voucher.pdf', 'mime_type' => 'application/pdf', 'uploaded_by' => $client->assigned_to]);
        }
    };
    $fixtures->seedVoucherClient();
}

echo "Disposable browser fixtures prepared.\n";
