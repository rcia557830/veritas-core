<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Document;
use App\Models\JournalDocument;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\JournalFixtures;
use Tests\TestCase;

class GeneralJournalTest extends TestCase
{
    use JournalFixtures, RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    private User $manager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->client = Client::firstOrFail();
        $this->actingAs($this->bookkeeper);
        Storage::fake('local');
    }

    private function save(array $payload): LedgerEntry
    {
        $this->post('/ledger', $payload)->assertSessionHasNoErrors()->assertRedirect();

        return LedgerEntry::latest('id')->firstOrFail();
    }

    private function document(): Document
    {
        $this->post('/documents', ['client_id' => $this->client->id, 'title' => 'SYNTHETIC Journal Receipt', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => '2026-04-12', 'file' => UploadedFile::fake()->createWithContent('synthetic.txt.pdf', 'SYNTHETIC ORIGINAL EVIDENCE')])->assertSessionHasNoErrors();

        return Document::latest('id')->firstOrFail();
    }

    public function test_structured_draft_submit_independent_review_and_correction_preserve_ids(): void
    {
        $payload = $this->journalPayload($this->client);
        $entry = $this->save($payload);
        $this->assertSame('Draft', $entry->status);
        $this->assertSame($this->client->id, $entry->items->first()->client_id);
        $this->assertSame('SYNTHETIC Asset', $entry->items->first()->account_name);
        $ids = $entry->items->pluck('id')->all();
        $payload['items'] = $entry->items->map(fn ($i) => $i->only(['id', 'account_id', 'debit', 'credit']))->all();
        $this->put('/ledger/'.$entry->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame($ids, $entry->items()->pluck('id')->all());
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('For Review', $entry->fresh()->status);
        $this->put('/ledger/'.$entry->id, $payload)->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasNoErrors();
        $this->assertSame('Reviewed', $entry->fresh()->status);
        $this->assertSame($this->manager->id, $entry->fresh()->reviewed_by);
        $this->assertNull($entry->fresh()->posted_at);
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Return for correction');
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'return', 'notes' => 'SYNTHETIC correction'])->assertSessionHasNoErrors();
        $this->assertSame('Needs Correction', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->reviewed_by);
        $this->assertNull($entry->fresh()->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['module' => 'ledger', 'record_id' => $entry->id, 'action' => 'review']);
        $this->actingAs($this->bookkeeper)->put('/ledger/'.$entry->id, $payload)->assertSessionHasNoErrors();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasNoErrors();
        $this->assertSame('Reviewed', $entry->fresh()->status);
    }

    public function test_account_options_and_server_reject_inactive_cross_client_and_incomplete_references(): void
    {
        $payload = $this->journalPayload($this->client);
        $other = Client::whereKeyNot($this->client->id)->first();
        $foreign = $this->journalPayload($other)['items'][0]['account_id'];
        $inactive = Account::create(['client_id' => $this->client->id, 'code' => 'INACTIVE', 'name' => 'SYNTHETIC Inactive', 'classification' => 'Asset', 'is_active' => false]);
        $this->getJson('/ledger/options?client_id='.$this->client->id)->assertOk()->assertJsonCount(2, 'accounts')->assertJsonCount(1, 'periods')->assertDontSee('INACTIVE');
        foreach ([$foreign, $inactive->id, 9999999, null] as $account) {
            $bad = $payload;
            $bad['items'][0]['account_id'] = $account;
            $this->post('/ledger', $bad)->assertSessionHasErrors('items.0.account_id');
        }
        $bad = $payload;
        $bad['items'][0]['account_name'] = 'FORGED free text';
        $this->post('/ledger', $bad)->assertSessionHasErrors('items.0');
        $other->update(['assigned_to' => $this->owner->id]);
        $this->getJson('/ledger/options?client_id='.$other->id)->assertNotFound();
        $this->post('/ledger', $this->journalPayload($other))->assertNotFound();
        $this->bookkeeper->role->permissions()->detach(Permission::where('name', 'account.view')->value('id'));
        $this->getJson('/ledger/options?client_id='.$this->client->id)->assertForbidden();
        $this->post('/ledger', $payload)->assertForbidden();
    }

    public function test_period_rules_apply_at_save_submission_and_review(): void
    {
        $payload = $this->journalPayload($this->client);
        $foreign = $this->journalPayload(Client::whereKeyNot($this->client->id)->first());
        foreach ([['accounting_period_id' => $foreign['accounting_period_id']], ['accounting_period_id' => 999999], ['transaction_date' => '2027-01-01'], ['transaction_date' => '2026-02-30']] as $change) {
            $this->post('/ledger', array_replace($payload, $change))->assertSessionHasErrors();
        }
        $entry = $this->save(array_replace($payload, ['accounting_period_id' => null]));
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('accounting_period_id');
        $this->put('/ledger/'.$entry->id, $payload)->assertSessionHasNoErrors();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        DB::table('accounting_periods')->where('id', $payload['accounting_period_id'])->update(['status' => 'Closed', 'closed_by' => $this->owner->id, 'closed_at' => now()]);
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasErrors('accounting_period_id');
        $this->assertSame('For Review', $entry->fresh()->status);
        $this->actingAs($this->bookkeeper)->post('/ledger', $payload)->assertSessionHasErrors('accounting_period_id');
        $this->getJson('/ledger/options?client_id='.$this->client->id)->assertJsonCount(0, 'periods');
    }

    public function test_exact_balancing_duplicate_lines_and_malformed_amounts(): void
    {
        $payload = $this->journalPayload($this->client, '99.99');
        $entry = $this->save($payload);
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('items');
        foreach (['-1', '0.001', '1e2', 'NaN', '1000000000', ['100']] as $amount) {
            $bad = $payload;
            $bad['items'][0]['debit'] = $amount;
            $this->post('/ledger', $bad)->assertSessionHasErrors('items.0.debit');
        }
        foreach ([['debit' => '0', 'credit' => '0'], ['debit' => '1', 'credit' => '1']] as $sides) {
            $bad = $payload;
            $bad['items'][0] = array_replace($bad['items'][0], $sides);
            $this->post('/ledger', $bad)->assertSessionHasErrors('items.0.debit');
        }
        $bad = $payload;
        $bad['items'][] = $bad['items'][0];
        $this->post('/ledger', $bad)->assertSessionHasErrors('items.2.account_id');
        $this->post('/ledger', array_replace($payload, ['items' => ['bad' => $payload['items'][0]]]))->assertSessionHasErrors('items');
        $payload['items'][0]['debit'] = '0.10';
        $payload['items'][1]['credit'] = '0.30';
        $payload['items'][] = array_replace($payload['items'][0], ['debit' => '0.20']);
        $entry = $this->save($payload);
        $this->assertSame('0.30', $entry->total_debit);
        $this->assertSame('0.30', $entry->total_credit);
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('0.30')->assertSee('This transaction is balanced.');
    }

    public function test_line_id_injection_and_duplicate_ids_cannot_mutate_another_journal(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper);
        $other = $this->structuredJournal($this->bookkeeper);
        $payload = $this->journalPayload($this->client);
        $payload['items'][0]['id'] = $other->items->first()->id;
        $this->put('/ledger/'.$entry->id, $payload)->assertSessionHasErrors('items.0.id');
        $payload['items'][0]['id'] = $entry->items->first()->id;
        $payload['items'][1]['id'] = $entry->items->first()->id;
        $this->put('/ledger/'.$entry->id, $payload)->assertSessionHasErrors('items.0.id');
        $this->assertSame('100.00', $other->fresh()->total_debit);
        $this->put('/ledger/'.$entry->id, array_replace($this->journalPayload($this->client), ['client_id' => Client::whereKeyNot($this->client->id)->value('id')]))->assertSessionHasErrors('client_id');
    }

    public function test_review_rechecks_inactive_accounts_and_balancing(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper, 'For Review');
        $account = $entry->items->first()->account;
        $account->update(['is_active' => false]);
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasErrors('items');
        $account->update(['is_active' => true]);
        $entry->items->first()->update(['debit' => '99.99']);
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasErrors('items');
        $this->assertNull($entry->fresh()->reviewed_at);
    }

    public function test_replaced_attachments_remain_downloadable_and_refresh_is_explicit(): void
    {
        $document = $this->document();
        $payload = $this->journalPayload($this->client) + ['document_ids' => [$document->id]];
        $entry = $this->save($payload);
        $original = $entry->evidence()->firstOrFail();
        $oldPath = $document->file_path;
        $this->assertSame(hash('sha256', 'SYNTHETIC ORIGINAL EVIDENCE'), $original->sha256);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->actingAs($this->owner)->put('/documents/'.$document->id, ['client_id' => $this->client->id, 'title' => $document->title, 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => '2026-04-12', 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'SYNTHETIC REPLACEMENT')])->assertSessionHasNoErrors();
        $this->assertNotSame($oldPath, $document->fresh()->file_path);
        Storage::disk('local')->assertExists($oldPath);
        $this->actingAs($this->bookkeeper)->put('/ledger/'.$entry->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $entry->evidence()->count());
        $this->get('/ledger/'.$entry->id.'/evidence/'.$original->id)->assertDownload('synthetic.txt.pdf');
        $this->get('/ledger/'.$entry->id)->assertSee('Original attachment retained');
        $this->put('/ledger/'.$entry->id, $payload + ['refresh_document_ids' => [$document->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($original->fresh()->detached_at);
        $this->assertSame(2, $entry->evidence()->count());
        $this->assertSame(1, $entry->evidence()->whereNull('detached_at')->count());
        $this->get('/ledger/'.$entry->id.'/evidence/'.$original->id)->assertDownload();
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->put('/ledger/'.$entry->id, array_replace($payload, ['document_ids' => []]))->assertSessionHasNoErrors();
        $this->assertSame(0, $entry->evidence()->whereNull('detached_at')->count());
        $this->assertSame(2, $entry->evidence()->count());
    }

    public function test_document_authorization_and_integrity_fail_closed(): void
    {
        $document = $this->document();
        $payload = $this->journalPayload($this->client) + ['document_ids' => [$document->id]];
        $entry = $this->save($payload);
        $evidence = $entry->evidence()->first();
        $other = Client::whereKeyNot($this->client->id)->first();
        $foreign = Document::where('client_id', $other->id)->first();
        $this->post('/ledger', array_replace($payload, ['document_ids' => [$foreign->id]]))->assertSessionHasErrors('document_ids');
        $this->assertSame(1, JournalDocument::count());
        $this->bookkeeper->role->permissions()->detach(Permission::where('name', 'document.download')->value('id'));
        $this->post('/ledger', $payload)->assertForbidden();
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertForbidden();
        $this->actingAs($this->owner);
        $otherEntry = $this->structuredJournal($this->bookkeeper, client: $other);
        $this->get('/ledger/'.$otherEntry->id.'/evidence/'.$evidence->id)->assertNotFound();
        Storage::disk('local')->put($evidence->file_path, 'SYNTHETIC TAMPERING');
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertSessionHasErrors('document_ids');
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('document_ids');
        $this->assertSame('Draft', $entry->fresh()->status);
    }

    public function test_legacy_records_remain_visible_read_only_and_unmapped(): void
    {
        $entry = LedgerEntry::firstOrFail();
        $beforeEntry = $entry->getRawOriginal();
        $beforeLines = $entry->items()->get()->toJson();
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Unmapped legacy entry')->assertSee($entry->items->first()->account_name);
        $this->get('/ledger/'.$entry->id.'/edit')->assertForbidden();
        $this->put('/ledger/'.$entry->id, $this->journalPayload($this->client))->assertForbidden();
        $this->delete('/ledger/'.$entry->id)->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertSessionHasErrors('accounting_period_id');
        $this->assertSame($beforeEntry, $entry->fresh()->getRawOriginal());
        $this->assertSame($beforeLines, $entry->items()->get()->toJson());
        $this->assertNull($entry->fresh()->accounting_period_id);
        $this->assertNull($entry->fresh()->posted_at);
    }

    public function test_evidence_foreign_keys_and_non_destructive_rollback(): void
    {
        $document = $this->document();
        $entry = $this->save($this->journalPayload($this->client) + ['document_ids' => [$document->id]]);
        $evidence = $entry->evidence()->first();
        $other = Client::whereKeyNot($this->client->id)->first();
        try {
            DB::table('journal_documents')->where('id', $evidence->id)->update(['document_id' => Document::where('client_id', $other->id)->value('id')]);
            $this->fail('Cross-client evidence must fail.');
        } catch (QueryException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $migration = require database_path('migrations/2026_10_10_000005_create_journal_documents.php');
        try {
            $migration->down();
            $this->fail('Retained evidence must block rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('rollback refused', $error->getMessage());
        }
        $this->assertSame(1, JournalDocument::count());
    }

    public function test_historical_decimal_range_renders_without_changing_input_limits(): void
    {
        $legacy = LedgerEntry::first();
        // Synthetic legacy storage may predate today's narrower entry limits.
        $legacy->items()->first()->update(['debit' => '1000000000.01', 'credit' => '-0.01']);
        $this->assertSame('1000000000.01', $legacy->fresh()->total_debit);
        $this->assertSame('2499.99', $legacy->fresh()->total_credit);
        $this->get('/ledger/'.$legacy->id)->assertOk()->assertSee('1,000,000,000.01')->assertSee('-0.01');
        $payload = $this->journalPayload($this->client);
        $payload['items'][0]['debit'] = '1000000000.01';
        $this->post('/ledger', $payload)->assertSessionHasErrors('items.0.debit');
    }

    public function test_explicit_account_change_updates_structured_label_but_preserves_line_id(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper);
        $new = Account::create(['client_id' => $this->client->id, 'code' => '003', 'name' => 'SYNTHETIC Expense', 'classification' => 'Expense']);
        $payload = $this->journalPayload($this->client);
        $payload['items'] = $entry->items->map(fn ($i) => $i->only(['id', 'account_id', 'debit', 'credit']))->all();
        $id = $payload['items'][0]['id'];
        $payload['items'][0]['account_id'] = $new->id;
        $this->put('/ledger/'.$entry->id, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ledger_items', ['id' => $id, 'account_id' => $new->id, 'account_name' => 'SYNTHETIC Expense']);
    }

    public function test_archived_source_evidence_stays_private_and_historical_snapshots_are_immutable(): void
    {
        $document = $this->document();
        $entry = $this->save($this->journalPayload($this->client) + ['document_ids' => [$document->id]]);
        $snapshot = $entry->evidence()->first();
        $document->delete();
        $this->get('/ledger/'.$entry->id.'/evidence/'.$snapshot->id)->assertDownload();
        $this->post('/ledger', $this->journalPayload($this->client) + ['document_ids' => [$document->id]])->assertSessionHasErrors('document_ids');
        $this->assertSame(1, JournalDocument::count());
        foreach ([fn () => $snapshot->update(['sha256' => str_repeat('0', 64)]), fn () => $snapshot->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Retained evidence must be immutable.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('documents', $error->errors());
            }
        }
        $this->client->update(['assigned_to' => $this->owner->id]);
        $this->get('/ledger/'.$entry->id.'/evidence/'.$snapshot->id)->assertForbidden();
    }
}
