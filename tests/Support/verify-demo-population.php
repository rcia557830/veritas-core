<?php

use App\Models\Client;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\JournalEvidence;
use App\Services\Accounting\VoucherWriter;
use App\Services\Records;
use App\Support\DemoDatabase;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DemoDatabase::check();
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
check(DB::selectOne('PRAGMA integrity_check')->integrity_check === 'ok', 'SQLite integrity');
check(DB::select('PRAGMA foreign_key_check') === [], 'Foreign keys');
$bookkeeper = User::where('email', 'bookkeeper@demo.example.test')->sole();
auth()->setUser($bookkeeper);
$counts = [];
foreach (['journals' => '/ledger?work=mine', 'articles' => '/knowledge', 'procedures' => '/knowledge?type=Internal%20Procedure', 'templates' => '/knowledge?q=template', 'guidelines' => '/knowledge?q=guideline'] as $name => $url) {
    $counts[$name] = Records::query($name === 'journals' ? 'ledger' : 'knowledge', Request::create($url))->count();
}
foreach (Records::query('ledger', Request::create('/ledger?work=mine'))->get() as $entry) {
    check($entry->created_by === $bookkeeper->id && $entry->client->assigned_to === $bookkeeper->id, 'My Journals assignment');
}
auth()->setUser(User::where('email', 'owner@demo.example.test')->sole());
foreach (LedgerEntry::with('items.account', 'evidence', 'accountingPeriod')->get() as $entry) {
    $debits = $entry->items->sum(fn ($item) => Money::cents($item->debit));
    check($debits > 0 && $debits === $entry->items->sum(fn ($item) => Money::cents($item->credit)), 'Journal balance');
    check($entry->accountingPeriod->client_id === $entry->client_id, 'Period client');
    check($entry->transaction_date->between($entry->accountingPeriod->starts_on, $entry->accountingPeriod->ends_on), 'Period date');
    foreach ($entry->items as $item) {
        check($item->account->client_id === $entry->client_id && $item->account->is_active, 'Account client and status');
    }
    foreach ($entry->evidence as $evidence) {
        JournalEvidence::verify($evidence);
    }
    if (in_array($entry->status, ['Reviewed', 'Posted'])) {
        check($entry->reviewed_by !== null && $entry->reviewed_by !== $entry->created_by, 'Independent review');
    }
    if ($entry->status === 'Posted') {
        check($entry->posted_by !== null && $entry->posted_by !== $entry->created_by, 'Independent posting');
    }
}
check(Voucher::count() === Voucher::distinct()->count('ledger_entry_id'), 'Duplicate voucher journals');
foreach (Voucher::with('journal.items')->get() as $voucher) {
    check($voucher->client_id === $voucher->journal->client_id, 'Voucher client');
    VoucherWriter::validate($voucher->journal);
}
foreach (Client::all() as $client) {
    $start = $client->accountingPeriods()->min('starts_on');
    $end = $client->accountingPeriods()->max('ends_on');
    $report = app(FinancialReports::class)->generate($client, 'trial-balance', $start, $end);
    check($report['difference'] === 0, 'Trial balance');
    $balance = app(FinancialReports::class)->generate($client, 'balance-sheet', $start, $end);
    check($balance['equationDifference'] === 0, 'Balance sheet');
}
check(KnowledgeArticle::selectRaw('LOWER(title) as normalized')->groupBy('normalized')->havingRaw('COUNT(*) > 1')->get()->isEmpty(), 'Duplicate article titles');
check(KnowledgeArticle::whereNull('content')->orWhere('content', '')->count() === 0, 'Empty articles');
echo json_encode([
    'database' => DB::connection()->getDatabaseName(), 'visible' => $counts,
    'journals' => LedgerEntry::count(),
    'statuses' => LedgerEntry::selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
    'vouchers' => Voucher::selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type'),
    'result' => 'PASS: balances, periods, accounts, assignments, evidence hashes, independent review/posting, voucher semantics, unique journal links, all client reports, article quality',
], JSON_PRETTY_PRINT).PHP_EOL;
