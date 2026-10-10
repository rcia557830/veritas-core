<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use App\Services\Accounting\JournalEvidence;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JournalFixtures;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use JournalFixtures, RefreshDatabase;

    private User $bookkeeper;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        Storage::fake('local');
    }

    private function reviewed(bool $evidence = false): LedgerEntry
    {
        $this->actingAs($this->bookkeeper);
        $entry = $this->structuredJournal($this->bookkeeper);
        if ($evidence) {
            Storage::put('retained.pdf', 'SYNTHETIC REVIEWED EVIDENCE');
            $document = Document::where('client_id', $entry->client_id)->firstOrFail();
            $document->update(['file_path' => 'retained.pdf', 'original_file_name' => 'retained.pdf']);
            JournalEvidence::sync($entry, [$document->id], []);
        }
        JournalWriter::transition($entry, 'submit', null);
        $this->actingAs($this->manager);

        return JournalWriter::transition($entry, 'review', null);
    }

    public function test_authorized_post_has_exact_metadata_single_audit_and_read_only_view(): void
    {
        $entry = $this->reviewed();
        $this->freezeTime();
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Post journal')->assertSee('data-confirm=', false);
        $this->post('/ledger/'.$entry->id.'/post')->assertRedirect()->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame('Posted', $entry->status);
        $this->assertTrue(LedgerEntry::posted()->whereKey($entry->id)->exists());
        $this->assertSame($this->manager->id, $entry->posted_by);
        $this->assertTrue($entry->posted_at->toDateTimeString() === now()->toDateTimeString());
        $this->assertSame($this->manager->id, $entry->reviewed_by);
        $this->assertSame(1, AuditLog::where('record_id', $entry->id)->where('module', 'ledger')->where('action', 'post')->count());
        $this->postJson('/ledger/'.$entry->id.'/post')->assertUnprocessable()->assertJsonValidationErrors('posting');
        $this->assertSame(1, AuditLog::where('record_id', $entry->id)->where('action', 'post')->count());
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Posted by')->assertDontSee('Post journal')->assertDontSee('Return for correction');
        $this->get('/ledger/'.$entry->id.'/edit')->assertForbidden();
        $this->delete('/ledger/'.$entry->id)->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'return'])->assertStatus(409);
    }

    public static function invalidStates(): array
    {
        return array_map(fn ($v) => [$v], ['draft', 'unbalanced', 'inactive', 'missing-account', 'closed', 'missing-period', 'outside-period', 'self-review', 'missing-review', 'header', 'balanced-change', 'evidence', 'missing-file', 'missing-snapshot']);
    }

    #[DataProvider('invalidStates')]
    public function test_posting_revalidates_current_accounting_and_review_evidence(string $case): void
    {
        $entry = $this->reviewed(in_array($case, ['evidence', 'missing-file']));
        $line = $entry->items()->first();
        match ($case) {
            'draft' => DB::table('ledger_entries')->where('id', $entry->id)->update(['status' => 'Draft']),
            'unbalanced' => $line->update(['debit' => '99.00']),
            'inactive' => $line->account->update(['is_active' => false]),
            'missing-account' => DB::table('ledger_items')->where('id', $line->id)->update(['account_id' => null, 'client_id' => null]),
            'closed' => $entry->accountingPeriod->forceFill(['status' => 'Closed', 'closed_by' => $this->manager->id, 'closed_at' => now()])->save(),
            'missing-period' => DB::table('ledger_entries')->where('id', $entry->id)->update(['accounting_period_id' => null]),
            'outside-period' => DB::table('ledger_entries')->where('id', $entry->id)->update(['transaction_date' => '2027-01-01']),
            'self-review' => DB::table('ledger_entries')->where('id', $entry->id)->update(['reviewed_by' => $entry->created_by]),
            'missing-review' => DB::table('ledger_entries')->where('id', $entry->id)->update(['reviewed_at' => null]),
            'header' => $entry->update(['description' => 'Changed after review']),
            'balanced-change' => DB::table('ledger_items')->where('ledger_entry_id', $entry->id)->update(['debit' => DB::raw('debit * 2'), 'credit' => DB::raw('credit * 2')]),
            'evidence' => Storage::put('retained.pdf', 'CHANGED'),
            'missing-file' => Storage::delete('retained.pdf'),
            'missing-snapshot' => DB::table('ledger_entries')->where('id', $entry->id)->update(['review_digest' => null]),
        };
        $this->postJson('/ledger/'.$entry->id.'/post')->assertUnprocessable();
        $this->assertNull($entry->fresh()->posted_at);
        $this->assertSame(0, AuditLog::where('record_id', $entry->id)->where('action', 'post')->count());
    }

    public function test_permissions_creator_scope_and_legacy_are_enforced(): void
    {
        $entry = $this->reviewed();
        $this->actingAs($this->bookkeeper)->post('/ledger/'.$entry->id.'/post')->assertForbidden();
        $this->actingAs($this->manager);
        $this->manager->role->permissions()->detach(Permission::where('name', 'bookkeeping.post')->value('id'));
        $this->post('/ledger/'.$entry->id.'/post')->assertForbidden();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner)->post('/ledger/'.$entry->id.'/post')->assertSessionHasNoErrors();
        $legacy = LedgerEntry::whereNull('accounting_period_id')->firstOrFail();
        $legacy->update(['status' => 'Reviewed', 'reviewed_by' => $this->manager->id, 'reviewed_at' => now()]);
        $this->postJson('/ledger/'.$legacy->id.'/post')->assertUnprocessable();
        $this->assertSame('Reviewed', $legacy->fresh()->status);
        $own = $this->structuredJournal($owner, 'Reviewed');
        $this->post('/ledger/'.$own->id.'/post')->assertForbidden();
        $this->post('/ledger/'.$own->id.'/transition', ['action' => 'review'])->assertForbidden();
    }

    public function test_source_replacement_preserves_posted_evidence_and_download_permissions(): void
    {
        $entry = $this->reviewed(true);
        $evidence = $entry->evidence()->firstOrFail();
        $evidence->document->update(['file_path' => 'replacement.pdf']);
        JournalPosting::post($entry);
        $this->assertSame('retained.pdf', $evidence->fresh()->file_path);
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertOk();
        $this->manager->role->permissions()->detach(Permission::where('name', 'document.download')->value('id'));
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertForbidden();
    }

    public function test_audit_failure_rolls_back_posting(): void
    {
        $entry = $this->reviewed();
        AuditLog::creating(function ($audit) {
            if ($audit->action === 'post') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            JournalPosting::post($entry);
            $this->fail('Audit failure must abort posting.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame('Reviewed', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->posted_by);
        $this->assertNull($entry->fresh()->posted_at);
    }

    public function test_cross_client_references_cannot_be_persisted_for_posting(): void
    {
        $entry = $this->reviewed();
        $other = Client::where('id', '!=', $entry->client_id)->firstOrFail();
        $payload = $this->journalPayload($other);
        foreach ([
            fn () => DB::table('ledger_items')->where('ledger_entry_id', $entry->id)->update(['account_id' => $payload['items'][0]['account_id']]),
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['accounting_period_id' => $payload['accounting_period_id']]),
        ] as $write) {
            try {
                $write();
                $this->fail('Cross-client references must fail.');
            } catch (QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertNull($entry->fresh()->posted_at);
        $this->assertSame($entry->client_id, $entry->items()->first()->account->client_id);
    }

    public function test_client_access_is_rechecked_and_model_cannot_forge_posting_metadata(): void
    {
        $entry = $this->reviewed();
        $this->manager->role->permissions()->detach(Permission::where('name', 'client.view')->value('id'));
        $this->post('/ledger/'.$entry->id.'/post')->assertNotFound();
        foreach ([['status' => 'Posted'], ['posted_by' => $this->manager->id], ['posted_at' => now()]] as $attributes) {
            try {
                $entry->fresh()->forceFill($attributes)->save();
                $this->fail('Posting requires the service.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('posting', $error->errors());
            }
        }
        $this->assertSame('Reviewed', $entry->fresh()->status);
    }

    public function test_changed_review_can_only_post_after_correction_and_new_review(): void
    {
        $entry = $this->reviewed();
        $entry->update(['description' => 'Changed after review']);
        $this->postJson('/ledger/'.$entry->id.'/post')->assertUnprocessable();
        JournalWriter::transition($entry, 'return', 'Recheck changes');
        $this->assertNull($entry->fresh()->review_digest);
        $this->actingAs($this->bookkeeper);
        JournalWriter::transition($entry, 'submit', null);
        $this->actingAs($this->manager);
        JournalWriter::transition($entry, 'review', null);
        JournalPosting::post($entry);
        $this->assertSame('Posted', $entry->fresh()->status);
    }

    public function test_posting_migration_rollback_preserves_reviewed_evidence(): void
    {
        $entry = $this->reviewed();
        $migration = require database_path('migrations/2026_10_10_000006_protect_journal_posting.php');
        try {
            $migration->down();
            $this->fail('Review evidence must survive rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('rollback refused', $error->getMessage());
        }
        $this->assertNotNull($entry->fresh()->review_digest);
    }

    public function test_production_permission_seeding_does_not_add_provisional_posting_grants(): void
    {
        $permission = Permission::where('name', 'bookkeeping.post')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        $this->app->instance('env', 'production');
        try {
            $this->app->make(PermissionSeeder::class)->setContainer($this->app)->run();
            $this->assertSame(0, DB::table('permission_role')->where('permission_id', $permission)->count());
            $this->manager->role->permissions()->attach($permission);
            $this->app->make(PermissionSeeder::class)->setContainer($this->app)->run();
            $this->assertSame(1, DB::table('permission_role')->where('permission_id', $permission)->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function immutableWrites(): array
    {
        return array_map(fn ($v) => [$v], ['header', 'status', 'metadata', 'delete', 'line', 'line-delete', 'line-insert', 'evidence', 'evidence-delete', 'evidence-insert']);
    }

    #[DataProvider('immutableWrites')]
    public function test_database_protects_posted_evidence_even_for_bulk_writes(string $case): void
    {
        $entry = $this->reviewed(true);
        JournalPosting::post($entry);
        $header = DB::table('ledger_entries')->where('id', $entry->id);
        $lines = DB::table('ledger_items')->where('ledger_entry_id', $entry->id);
        $evidence = DB::table('journal_documents')->where('ledger_entry_id', $entry->id);
        $this->expectException(QueryException::class);
        match ($case) {
            'header' => $header->update(['description' => 'Overwrite']),
            'status' => $header->update(['status' => 'Draft']),
            'metadata' => $header->update(['posted_by' => $this->bookkeeper->id]),
            'delete' => $header->delete(),
            'line' => $lines->update(['debit' => '200.00']),
            'line-delete' => $lines->delete(),
            'line-insert' => DB::table('ledger_items')->insert(['ledger_entry_id' => $entry->id, 'account_name' => 'Forged', 'debit' => '1.00', 'credit' => '0.00']),
            'evidence' => $evidence->update(['detached_at' => now()]),
            'evidence-delete' => $evidence->delete(),
            'evidence-insert' => DB::table('journal_documents')->insert(collect((array) $evidence->first())->except('id')->all()),
        };
    }
}
