<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class FinancialAuditVoucherTest extends TestCase
{
    use RefreshDatabase, VoucherFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_four_voucher_types_are_counted_once_and_reference_search_is_exactly_scoped(): void
    {
        $client = Client::firstOrFail();
        $vouchers = [];
        foreach (['JV', 'CV', 'CR', 'CD'] as $type) {
            $vouchers[] = $this->postedVoucher($client, $type);
        }
        $this->get('/financial-audit?client_id='.$client->id.'&status=Posted')->assertOk()
            ->assertViewHas('totals', fn ($t) => $t['posted'] === ['debit' => 180000, 'credit' => 180000]);
        foreach ($vouchers as $voucher) {
            $this->get('/financial-audit?q='.$voucher->reference)->assertOk()
                ->assertViewHas('lines', fn ($lines) => $lines->total() === 2 && $lines->pluck('ledger_entry_id')->unique()->all() === [$voucher->ledger_entry_id]);
            $this->get('/financial-audit/'.$voucher->ledger_entry_id)->assertOk()->assertSee($voucher->reference);
        }
    }

    public function test_voucher_edit_preserves_original_reference_and_before_after_amounts(): void
    {
        $client = Client::firstOrFail();
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $payload = $this->voucherPayload($client, 'CR');
        $voucher = VoucherWriter::save($payload);
        $payload['amount'] = '600.00';
        $payload['items'][0]['debit'] = '600.00';
        $payload['items'][1]['credit'] = '600.00';
        VoucherWriter::save($payload, $voucher);
        $event = AuditLog::where('record_id', $voucher->ledger_entry_id)->where('module', 'ledger')->where('action', 'voucher.updated')->firstOrFail();
        $this->assertSame('500.00', $event->evidence['before']['voucher']['amount']);
        $this->assertSame('600.00', $event->evidence['after']['voucher']['amount']);
        $this->assertSame($voucher->reference, $event->evidence['before']['voucher']['reference']);
        $this->assertSame($voucher->reference, $event->evidence['after']['voucher']['reference']);
        $this->get('/financial-audit/'.$voucher->ledger_entry_id)->assertOk()->assertSee('Recorded financial changes');
    }

    public function test_unmapped_legacy_records_are_readable_but_never_added_to_posted_totals(): void
    {
        $client = Client::firstOrFail();
        $this->actingAs(User::where('email', 'owner@veritascore.local')->firstOrFail());
        $entry = LedgerEntry::create(['client_id' => $client->id, 'created_by' => auth()->id(), 'transaction_date' => '2026-04-12', 'reference_number' => 'LEGACY-UNMAPPED', 'description' => 'Original legacy record', 'status' => 'Draft']);
        $entry->items()->create(['account_name' => 'Original account label', 'debit' => '999.00', 'credit' => '0.00']);
        $this->get('/financial-audit?q=LEGACY-UNMAPPED')->assertOk()->assertViewHas('lines', fn ($p) => $p->total() === 0);
        $this->get('/financial-audit/'.$entry->id)->assertOk()->assertSee('Original account label')->assertSee('Unposted');
    }
}
