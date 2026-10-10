<?php

namespace Tests\Support;

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Support\Str;

trait VoucherFixtures
{
    use FinancialReportFixtures;

    protected function voucherPayload(Client $client, string $type = 'JV'): array
    {
        $this->reportChart($client);
        $account = fn ($code) => $client->accounts()->where('code', $code)->value('id');
        $amount = match ($type) {
            'JV' => '1000.00', 'CR' => '500.00', 'CV' => '200.00', default => '100.00'
        };
        $receipt = in_array($type, ['JV', 'CR']);
        $data = ['type' => $type, 'creation_token' => (string) Str::uuid(), 'client_id' => $client->id, 'transaction_date' => '2026-04-12',
            'accounting_period_id' => AccountingPeriod::where('client_id', $client->id)->where('label', 'SYNTHETIC FY 2025')->value('id'),
            'description' => 'SYNTHETIC '.$type.' voucher', 'items' => [
                ['account_id' => $account('100'), 'debit' => $receipt ? $amount : '0.00', 'credit' => $receipt ? '0.00' : $amount],
                ['account_id' => $account($type === 'JV' ? '300' : ($type === 'CR' ? '400' : '500')), 'debit' => $receipt ? '0.00' : $amount, 'credit' => $receipt ? $amount : '0.00'],
            ]];
        if ($type !== 'JV') {
            $data += ['party' => 'SYNTHETIC payer/payee', 'cash_account_id' => $account('100'), 'amount' => $amount];
        }
        if ($type === 'CV') {
            $data += ['check_number' => 'SYNTHETIC-CHECK-001', 'check_date' => '2026-04-12'];
        }

        return $data;
    }

    protected function postedVoucher(Client $client, string $type): Voucher
    {
        auth()->login(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $voucher = VoucherWriter::save($this->voucherPayload($client, $type));
        JournalWriter::transition($voucher->journal, 'submit', null);
        auth()->login(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($voucher->journal, 'review', null);
        JournalPosting::post($voucher->journal);

        return $voucher->fresh();
    }
}
