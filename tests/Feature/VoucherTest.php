<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class VoucherTest extends TestCase
{
    use RefreshDatabase, VoucherFixtures;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->client = Client::firstOrFail();
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        Storage::fake('local');
    }

    public static function types(): array
    {
        return [['JV'], ['CV'], ['CR'], ['CD']];
    }

    #[DataProvider('types')]
    public function test_each_type_uses_one_journal_and_existing_independent_workflow(string $type): void
    {
        $payload = $this->voucherPayload($this->client, $type);
        $before = LedgerEntry::count();
        $this->post('/vouchers', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $voucher = Voucher::firstOrFail();
        $this->assertSame($type.'-000001', $voucher->reference);
        $this->assertSame($voucher->reference, $voucher->journal->reference_number);
        $this->assertSame($before + 1, LedgerEntry::count());
        $this->assertSame(0, (new GeneralLedger)->balances($this->client, '2026-04-01', '2026-04-30')->sum(fn ($r) => $r['balance']->debits));
        $this->get('/vouchers/'.$voucher->id)->assertOk()->assertSee($voucher->reference);
        $this->get('/vouchers/'.$voucher->id.'/edit')->assertOk()->assertSee('Save voucher');
        $this->get('/ledger/'.$voucher->ledger_entry_id.'/edit')->assertRedirect('/vouchers/'.$voucher->id.'/edit');
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/transition', ['action' => 'review'])->assertSessionHasNoErrors();
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/post')->assertSessionHasNoErrors();
        $this->assertSame('Posted', $voucher->journal->fresh()->status);
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/post')->assertSessionHasErrors('posting');
        $this->assertSame($before + 1, LedgerEntry::count());
        $this->get('/vouchers/'.$voucher->id.'/print')->assertOk()->assertSee($voucher->reference)->assertSee('Posted journal:');
        $this->get('/ledger/'.$voucher->ledger_entry_id)->assertOk()->assertSee('Open '.$voucher->reference);
        $this->assertDatabaseHas('audit_logs', ['module' => 'ledger', 'record_id' => $voucher->ledger_entry_id, 'action' => 'voucher.created']);
    }

    public function test_number_sequences_idempotent_requests_and_fixed_issued_identity(): void
    {
        $payload = $this->voucherPayload($this->client);
        $first = VoucherWriter::save($payload);
        $this->assertSame($first->id, VoucherWriter::save($payload)->id);
        $this->assertDatabaseCount('vouchers', 1);
        $this->post('/vouchers', array_replace($payload, ['description' => 'Changed replay']))->assertSessionHasErrors('voucher');
        $second = VoucherWriter::save(array_replace($payload, ['creation_token' => (string) Str::uuid()]));
        $receipt = VoucherWriter::save($this->voucherPayload($this->client, 'CR'));
        $this->assertSame(['JV-000001', 'JV-000002', 'CR-000001'], [$first->reference, $second->reference, $receipt->reference]);
        $this->put('/vouchers/'.$first->id, array_replace($payload, ['type' => 'CD']))->assertSessionHasErrors('voucher');
        $this->put('/vouchers/'.$first->id, $payload + ['reference' => 'FORGED'])->assertSessionHasErrors('reference');
        $this->delete('/ledger/'.$first->ledger_entry_id)->assertForbidden();
        $this->delete('/vouchers/'.$first->id)->assertStatus(405);
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $this->actingAs(User::where('email', 'owner@veritascore.local')->firstOrFail());
        $this->assertSame('JV-000001', VoucherWriter::save($this->voucherPayload($other))->reference);
    }

    public function test_invalid_balances_amounts_accounts_checks_and_periods_roll_back_atomically(): void
    {
        $payload = $this->voucherPayload($this->client, 'CV');
        $before = LedgerEntry::count();
        $badBalance = $payload;
        $badBalance['items'][1]['debit'] = '199.99';
        foreach ([$badBalance, array_replace($payload, ['amount' => '199.99']), array_replace($payload, ['amount' => '0']),
            array_replace($payload, ['amount' => '0.001']), array_replace($payload, ['check_number' => '']),
            array_replace($payload, ['check_date' => '2026-02-30']), array_replace($payload, ['accounting_period_id' => null]),
            array_replace($payload, ['cash_account_id' => $this->client->accounts()->where('code', '500')->value('id')])] as $invalid) {
            $this->postJson('/vouchers', $invalid)->assertUnprocessable();
            $this->assertSame($before, LedgerEntry::count());
            $this->assertDatabaseCount('vouchers', 0);
        }
        VoucherWriter::save($payload);
        $this->post('/vouchers', array_replace($payload, ['creation_token' => (string) Str::uuid(), 'check_number' => ' synthetic-check-001 ']))->assertSessionHasErrors('check_number');
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_correction_updates_same_journal_and_reviewed_metadata_cannot_be_changed(): void
    {
        $payload = $this->voucherPayload($this->client, 'CR');
        $voucher = VoucherWriter::save($payload);
        JournalWriter::transition($voucher->journal, 'submit', null);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($voucher->journal, 'review', null);
        $digest = $voucher->journal->fresh()->review_digest;
        $this->put('/vouchers/'.$voucher->id, $payload)->assertForbidden();
        JournalWriter::transition($voucher->journal, 'return', 'Correct payer name');
        $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
        $this->put('/vouchers/'.$voucher->id, array_replace($payload, ['party' => 'Corrected SYNTHETIC payer']))->assertSessionHasNoErrors();
        $this->assertSame($voucher->ledger_entry_id, $voucher->fresh()->ledger_entry_id);
        $this->assertNull($voucher->journal->fresh()->review_digest);
        JournalWriter::transition($voucher->journal, 'submit', null);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($voucher->journal, 'review', null);
        $this->assertNotSame($digest, $voucher->journal->fresh()->review_digest);
        JournalPosting::post($voucher->journal);
    }

    public function test_generic_journal_save_cannot_bypass_voucher_validation(): void
    {
        $payload = $this->voucherPayload($this->client, 'CR');
        $voucher = VoucherWriter::save($payload);
        $this->expectException(ValidationException::class);
        JournalWriter::save($payload, $voucher->journal);
    }

    public function test_posted_vouchers_reject_forged_edits_bulk_writes_and_deletion(): void
    {
        $voucher = $this->postedVoucher($this->client, 'CV');
        $payload = $this->voucherPayload($this->client, 'CV');
        foreach ([['type' => 'CR'], ['amount' => '99'], ['cash_account_id' => 999], ['client_id' => 2], ['document_ids' => [999]], ['party' => 'FORGED']] as $changes) {
            $this->put('/vouchers/'.$voucher->id, array_replace($payload, $changes))->assertForbidden();
        }
        foreach ([fn () => DB::table('vouchers')->where('id', $voucher->id)->update(['party' => 'FORGED']),
            fn () => DB::table('vouchers')->where('id', $voucher->id)->delete(),
            fn () => DB::table('ledger_entries')->where('id', $voucher->ledger_entry_id)->update(['deleted_at' => now()])] as $operation) {
            try {
                $operation();
                $this->fail('Protected database mutation succeeded.');
            } catch (QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertSame('SYNTHETIC payer/payee', $voucher->fresh()->party);
        $this->expectException(ValidationException::class);
        $voucher->update(['amount' => '1.00']);
    }

    public function test_voucher_movements_reconcile_in_every_financial_report(): void
    {
        foreach (array_keys(Voucher::TYPES) as $type) {
            $this->postedVoucher($this->client, $type);
        }
        $reports = new FinancialReports;
        $trial = $reports->generate($this->client, 'trial-balance', '2026-04-01', '2026-04-30');
        $this->assertSame([150000, 150000, 0], [$trial['debits'], $trial['credits'], $trial['difference']]);
        $income = $reports->generate($this->client, 'income-statement', '2026-04-01', '2026-04-30');
        $this->assertSame([50000, 30000, 20000], [$income['totals']['Revenue'], $income['totals']['Expense'], $income['netIncome']]);
        $sheet = $reports->generate($this->client, 'balance-sheet', '2026-04-01', '2026-04-30');
        $this->assertSame([120000, 120000, 0], [$sheet['totals']['Asset'], $sheet['liabilitiesEquity'], $sheet['equationDifference']]);
        $ledger = $reports->generate($this->client, 'accounts-ledger', '2026-04-01', '2026-04-30', $this->client->accounts()->where('code', '100')->value('id'))['ledger'];
        $this->assertSame(4, $ledger['count']);
        $this->assertSame(120000, $ledger['balance']->closing);
        $this->assertSame(['JV-000001', 'CV-000001', 'CR-000001', 'CD-000001'], array_column($ledger['rows'], 'reference'));
    }

    public function test_evidence_retains_integrity_and_respects_document_permissions(): void
    {
        $this->post('/documents', ['client_id' => $this->client->id, 'title' => 'SYNTHETIC Voucher Evidence', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => '2026-04-12',
            'file' => UploadedFile::fake()->createWithContent('voucher.pdf', 'SYNTHETIC ORIGINAL')])->assertSessionHasNoErrors();
        $document = Document::latest('id')->firstOrFail();
        $payload = $this->voucherPayload($this->client, 'CR') + ['document_ids' => [$document->id]];
        $voucher = VoucherWriter::save($payload);
        $evidence = $voucher->journal->evidence()->firstOrFail();
        $this->assertSame(hash('sha256', 'SYNTHETIC ORIGINAL'), $evidence->sha256);
        $this->get('/ledger/'.$voucher->ledger_entry_id.'/evidence/'.$evidence->id)->assertDownload('voucher.pdf');
        Storage::disk('local')->put($evidence->file_path, 'SYNTHETIC TAMPER');
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/transition', ['action' => 'submit'])->assertSessionHasErrors();
        auth()->user()->role->permissions()->detach(Permission::where('name', 'document.view')->value('id'));
        $this->get('/vouchers/'.$voucher->id.'/print')->assertOk()->assertDontSee('SYNTHETIC Voucher Evidence');
        $this->get('/ledger/'.$voucher->ledger_entry_id.'/evidence/'.$evidence->id)->assertForbidden();
    }

    public function test_client_boundaries_permissions_and_print_access(): void
    {
        $payload = $this->voucherPayload($this->client);
        $voucher = VoucherWriter::save($payload);
        $other = Client::where('id', '!=', $this->client->id)->firstOrFail();
        $this->reportChart($other);
        $forged = $payload;
        $forged['items'][0]['account_id'] = $other->accounts()->where('code', '100')->value('id');
        $forged['creation_token'] = (string) Str::uuid();
        $this->post('/vouchers', $forged)->assertSessionHasErrors();
        $this->client->update(['assigned_to' => null]);
        $this->get('/vouchers/'.$voucher->id)->assertForbidden();
        $this->get('/vouchers/'.$voucher->id.'/print')->assertForbidden();
        $this->post('/vouchers', $payload)->assertNotFound();
        $this->get('/vouchers')->assertOk()->assertDontSee($voucher->reference);
        $this->client->update(['assigned_to' => auth()->id()]);
        auth()->user()->role->permissions()->detach(Permission::where('name', 'report.print')->value('id'));
        $this->get('/vouchers/'.$voucher->id.'/print')->assertForbidden();
        auth()->user()->role->permissions()->detach(Permission::where('name', 'bookkeeping.create')->value('id'));
        $this->post('/vouchers', $payload)->assertForbidden();
        $this->post('/ledger/'.$voucher->ledger_entry_id.'/post')->assertForbidden();
        auth()->logout();
        $this->get('/vouchers')->assertRedirect('/login');
    }

    public function test_rollback_and_draft_reference_changes_are_refused_after_issuance(): void
    {
        $voucher = VoucherWriter::save($this->voucherPayload($this->client));
        foreach ([fn () => DB::table('vouchers')->where('id', $voucher->id)->update(['reference' => 'FORGED']),
            fn () => DB::table('ledger_entries')->where('id', $voucher->ledger_entry_id)->update(['reference_number' => 'FORGED']),
            fn () => DB::table('ledger_entries')->where('id', $voucher->ledger_entry_id)->update(['deleted_at' => now()])] as $operation) {
            try {
                $operation();
                $this->fail('Issued reference changed.');
            } catch (QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $migration = require database_path('migrations/2026_10_10_000007_create_vouchers.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_zero_check_number_is_retained_and_duplicate_protected(): void
    {
        $payload = array_replace($this->voucherPayload($this->client, 'CV'), ['check_number' => '0']);
        $voucher = VoucherWriter::save($payload);
        $this->assertSame('0', $voucher->check_number);
        $this->post('/vouchers', array_replace($payload, ['creation_token' => (string) Str::uuid()]))->assertSessionHasErrors('check_number');
        $this->post('/vouchers', array_replace($this->voucherPayload($this->client), ['check_number' => '0']))->assertSessionHasErrors('voucher');
        $this->assertDatabaseCount('vouchers', 1);
    }

    #[DataProvider('types')]
    public function test_split_cent_lines_post_and_failed_edits_preserve_original(string $type): void
    {
        $payload = $this->voucherPayload($this->client, $type);
        $incoming = in_array($type, ['JV', 'CR'], true);
        $side = $incoming ? 'debit' : 'credit';
        $opposite = $incoming ? 'credit' : 'debit';
        $payload['items'][0][$side] = '0.10';
        $payload['items'][1][$opposite] = '0.30';
        $payload['items'][] = array_replace($payload['items'][0], [$side => '0.20']);
        if ($type !== 'JV') {
            $payload['amount'] = '0.30';
        }
        $voucher = VoucherWriter::save($payload);
        $original = $voucher->journal->items()->orderBy('id')->get()->toArray();
        $invalid = $payload;
        $invalid['description'] = 'Must roll back';
        $invalid['items'][1][$opposite] = '0.29';
        $this->put('/vouchers/'.$voucher->id, $invalid)->assertSessionHasErrors();
        $this->assertSame($payload['description'], $voucher->journal->fresh()->description);
        $this->assertSame($original, $voucher->journal->items()->orderBy('id')->get()->toArray());
        JournalWriter::transition($voucher->journal, 'submit', null);
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        JournalWriter::transition($voucher->journal, 'review', null);
        JournalPosting::post($voucher->journal);
        $trial = (new FinancialReports)->generate($this->client, 'trial-balance', '2026-04-01', '2026-04-30');
        $this->assertSame([30, 30, 0], [$trial['debits'], $trial['credits'], $trial['difference']]);
    }
}
