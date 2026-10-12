<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class IndependentAccountingReconciliationTest extends TestCase
{
    use RefreshDatabase, VoucherFixtures;

    public function test_independent_six_transaction_book_reconciles_all_reports_and_boundaries(): void
    {
        $this->seed();
        $client = Client::firstOrFail();
        $this->reportChart($client);
        Account::create(['client_id' => $client->id, 'code' => '110', 'name' => 'SYNTHETIC Receivables', 'classification' => 'Asset']);

        // Prior fiscal year: cash 100, revenue 100. Expected prior unclosed earnings: 100.
        $this->reportJournal($client, '2025-06-30', [100 => 10000, 400 => -10000]);
        // Current month opening: cash 1,000; capital 800; payable 200.
        $this->postVoucher($client, 'JV', '2026-04-01', [100 => 100000, 300 => -80000, 200 => -20000]);
        // Credit sale 300; receipt against receivable 120.
        $this->reportJournal($client, '2026-04-02', [110 => 30000, 400 => -30000]);
        $this->postVoucher($client, 'CR', '2026-04-03', [100 => 12000, 110 => -12000], '120.00');
        // Cash expense 50; accrued expense 20; check paying payable 40.
        $this->postVoucher($client, 'CD', '2026-04-05', [500 => 5000, 100 => -5000], '50.00');
        $this->reportJournal($client, '2026-04-06', [500 => 2000, 200 => -2000]);
        $this->postVoucher($client, 'CV', '2026-04-07', [200 => 4000, 100 => -4000], '40.00');

        // Neither a draft nor the next month nor a second client's postings may enter April.
        $this->reportJournal($client, '2026-04-08', [100 => 999900, 400 => -999900], 'Draft');
        $this->reportJournal($client, '2026-05-01', [100 => 77700, 400 => -77700]);
        $other = Client::whereKeyNot($client->id)->firstOrFail();
        $this->reportChart($other);
        $this->reportJournal($other, '2026-04-09', [100 => 999900, 400 => -999900]);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());

        $reports = new FinancialReports;
        $trial = $reports->generate($client, 'trial-balance', '2026-04-01', '2026-04-30');
        $income = $reports->generate($client, 'income-statement', '2026-04-01', '2026-04-30');
        $sheet = $reports->generate($client, 'balance-sheet', '2026-04-01', '2026-04-30');

        // Independent debit-positive ledger expectations, in cents. These are
        // hand-calculated from the transactions above, not another report.
        $signed = ['100' => 113000, '110' => 18000, '200' => -18000,
            '300' => -80000, '310' => 0, '400' => -40000, '500' => 7000];
        $actual = [];
        foreach ($trial['groups'] as $rows) {
            foreach ($rows as $row) {
                $actual[$row['account']->code] = $row['signed'];
            }
        }
        ksort($actual);
        $this->assertSame($signed, $actual);
        $this->assertSame([138000, 138000, 0], [$trial['debits'], $trial['credits'], $trial['difference']]);
        $this->assertSame(['Asset' => 131000, 'Liability' => 18000, 'Equity' => 80000,
            'Revenue' => 40000, 'Expense' => 7000], $trial['totals']);
        $this->assertSame([30000, 7000, 23000],
            [$income['totals']['Revenue'], $income['totals']['Expense'], $income['netIncome']]);
        $this->assertSame([131000, 18000, 80000, 10000, 23000, 131000, 0],
            [$sheet['totals']['Asset'], $sheet['totals']['Liability'], $sheet['totals']['Equity'],
                $sheet['priorEarnings'], $sheet['currentEarnings'], $sheet['liabilitiesEquity'], $sheet['equationDifference']]);

        $cash = $client->accounts()->where('code', '100')->firstOrFail();
        $ledger = (new GeneralLedger)->account($client, $cash, '2026-04-01', '2026-04-30', allRows: true);
        $this->assertSame([10000, 112000, 9000, 113000],
            [$ledger['balance']->opening, $ledger['balance']->debits, $ledger['balance']->credits, $ledger['balance']->closing]);
        $this->assertSame([110000, 122000, 117000, 113000], array_column($ledger['rows'], 'running'));
        $accountsLedger = $reports->generate($client, 'accounts-ledger', '2026-04-01', '2026-04-30', $cash->id);
        $this->assertSame(113000, $accountsLedger['ledger']['balance']->closing);
        $this->assertCount(4, $accountsLedger['ledger']['rows']);
    }

    private function postVoucher(Client $client, string $type, string $date, array $lines, ?string $amount = null): void
    {
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $payload = $this->voucherPayload($client, $type);
        $payload['transaction_date'] = $date;
        if ($type === 'CV') {
            $payload['check_date'] = $date;
        }
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }
        $payload['items'] = [];
        foreach ($lines as $code => $cents) {
            $payload['items'][] = ['account_id' => $client->accounts()->where('code', (string) $code)->value('id'),
                'debit' => number_format(max(0, $cents) / 100, 2, '.', ''),
                'credit' => number_format(max(0, -$cents) / 100, 2, '.', '')];
        }
        $voucher = VoucherWriter::save($payload);
        JournalWriter::transition($voucher->journal, 'submit', null);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($voucher->journal, 'review', null);
        JournalPosting::post($voucher->journal);
    }
}
