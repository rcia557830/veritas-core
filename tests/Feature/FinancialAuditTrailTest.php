<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalEvidence;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GeneralLedgerFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class FinancialAuditTrailTest extends TestCase
{
    use GeneralLedgerFixtures, RefreshDatabase, SuperAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@veritascore.local')->firstOrFail();
    }

    private function url(array $filters = []): string
    {
        return '/financial-audit?'.http_build_query($filters);
    }

    public function test_posted_totals_reconcile_with_ledger_and_pending_is_separate(): void
    {
        $client = Client::firstOrFail();
        $this->ledgerJournal($client, '2026-03-31', [99900]);
        $entry = $this->ledgerJournal($client, '2026-04-01', [10, 20], reference: 'AUDIT-EXACT');
        $this->ledgerJournal($client, '2026-04-30', [-15]);
        $this->ledgerJournal($client, '2026-05-01', [88800]);
        $this->ledgerJournal($client, '2026-04-12', [77700], 'Draft');
        $filters = ['client_id' => $client->id, 'from' => '2026-04-01', 'to' => '2026-04-30'];
        $this->get($this->url($filters))->assertOk()->assertViewHas('totals', [
            'posted' => ['debit' => 45, 'credit' => 45], 'unposted' => ['debit' => 77700, 'credit' => 77700],
        ]);
        $cash = Account::findOrFail($entry->items()->first()->account_id);
        $ledger = (new GeneralLedger)->account($client, $cash, '2026-04-01', '2026-04-30');
        $this->get($this->url($filters + ['account_id' => $cash->id, 'status' => 'Posted']))->assertOk()
            ->assertViewHas('totals', fn ($t) => $t['posted']['debit'] === $ledger['balance']->debits && $t['posted']['credit'] === $ledger['balance']->credits && $t['unposted']['debit'] === 0);
    }

    public function test_search_status_date_filters_and_pagination_have_full_matching_totals(): void
    {
        $client = Client::firstOrFail();
        for ($i = 0; $i < 6; $i++) {
            $this->ledgerJournal($client, '2026-04-12', [123], 'Draft', 'AUDIT-PAGE-'.$i);
        }
        $filters = ['client_id' => $client->id, 'q' => 'AUDIT-PAGE', 'status' => 'Draft', 'per_page' => 10];
        $first = $this->get($this->url($filters))->assertOk()->assertViewHas('totals', fn ($t) => $t['unposted'] === ['debit' => 738, 'credit' => 738])->viewData('lines');
        $second = $this->get($this->url($filters + ['page' => 2]))->assertOk()->viewData('lines');
        $this->assertSame(12, $first->total());
        $this->assertCount(10, $first);
        $this->assertCount(2, $second);
        $this->assertEmpty(array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));
        foreach ([['q' => 'absent'], ['status' => 'Posted'], ['from' => '2026-04-13'], ['to' => '2026-04-11']] as $override) {
            $this->get($this->url(array_replace($filters, $override)))->assertOk()->assertViewHas('lines', fn ($p) => $p->total() === 0);
        }
        $this->getJson($this->url(['status' => 'invented']))->assertUnprocessable();
        $this->getJson($this->url(['from' => '2026-04-30', 'to' => '2026-04-01']))->assertUnprocessable();
    }

    public function test_details_export_and_event_snapshots_are_read_only_and_formula_safe(): void
    {
        $client = Client::firstOrFail();
        $entry = $this->ledgerJournal($client, '2026-04-12', [30], 'Draft', '=SUM(1,2)');
        $this->actingAs($this->user('manager'));
        $before = [DB::table('ledger_entries')->get()->toJson(), DB::table('ledger_items')->get()->toJson(), AuditLog::count()];
        $this->get('/financial-audit/'.$entry->id)->assertOk()->assertSee('=SUM(1,2)')->assertSee('Unposted')->assertSee('Sample Bookkeeper');
        $csv = $this->get('/financial-audit/export?'.http_build_query(['q' => '=SUM(1,2)']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))));
        $this->assertSame($before, [DB::table('ledger_entries')->get()->toJson(), DB::table('ledger_items')->get()->toJson(), AuditLog::count()]);
        $event = AuditLog::where('module', 'ledger')->where('record_id', $entry->id)->where('action', 'created')->firstOrFail();
        $this->assertSame('financial', $event->event_class);
        $this->assertSame(LedgerEntry::class, $event->entity_type);
        $this->assertCount(2, $event->evidence['after']['lines']);
        $this->assertNull($event->evidence['before']);
        $this->put('/financial-audit/'.$entry->id)->assertMethodNotAllowed();
        $this->delete('/financial-audit/'.$entry->id)->assertMethodNotAllowed();
        $this->user('manager')->role->permissions()->detach(Permission::where('name', 'report.export')->value('id'));
        $this->get('/financial-audit/export')->assertForbidden();
    }

    public function test_business_roles_are_scoped_and_technical_admin_cannot_access_financial_records(): void
    {
        $client = Client::firstOrFail();
        $entry = $this->ledgerJournal($client, '2026-04-12', [100], 'Draft', 'VISIBLE-AUDIT');
        $private = Client::create(['business_name' => 'PRIVATE AUDIT CLIENT', 'client_code' => 'AUDIT-PRIVATE', 'business_type' => 'Corporation', 'created_by' => $this->user('owner')->id, 'assigned_to' => $this->user('bookkeeper')->id, 'status' => 'Active']);
        $other = $this->ledgerJournal($private, '2026-04-12', [99900], 'Draft', 'PRIVATE-REFERENCE');
        $private->update(['assigned_to' => null]);
        foreach (['owner', 'manager', 'bookkeeper'] as $name) {
            $this->actingAs($this->user($name))->get($this->url(['q' => 'VISIBLE-AUDIT']))->assertOk()->assertSee('VISIBLE-AUDIT');
            $this->get('/financial-audit/'.$entry->id)->assertOk();
            $this->get('/admin/activity-logs')->assertForbidden();
        }
        $this->actingAs($this->user('bookkeeper'));
        $this->get('/financial-audit')->assertOk()->assertDontSee('PRIVATE-REFERENCE')->assertDontSee('PRIVATE AUDIT CLIENT');
        $this->get('/financial-audit/'.$other->id)->assertNotFound();
        foreach (['/financial-audit', '/financial-audit/export'] as $url) {
            $this->get($url.'?client_id='.$private->id)->assertNotFound();
            $this->get($url.'?account_id='.$other->items()->first()->account_id)->assertNotFound();
        }
        $this->actingAs($this->superAdmin());
        foreach (['/financial-audit', '/financial-audit/export', '/financial-audit/'.$entry->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/admin/activity-logs')->assertOk()->assertDontSee('VISIBLE-AUDIT');
    }

    public function test_supporting_document_download_reuses_authorization_and_retained_evidence(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('bookkeeper'));
        $entry = $this->structuredJournal($this->user('bookkeeper'));
        $document = Document::where('client_id', $entry->client_id)->firstOrFail();
        Storage::disk('local')->put('audit-original.pdf', 'SYNTHETIC ORIGINAL');
        $document->update(['file_path' => 'audit-original.pdf', 'original_file_name' => 'audit-original.pdf']);
        JournalEvidence::sync($entry, [$document->id], []);
        $evidence = $entry->evidence()->firstOrFail();
        JournalWriter::transition($entry, 'submit', null);
        $this->actingAs($this->user('manager'));
        JournalWriter::transition($entry, 'review', null);
        JournalPosting::post($entry);
        $this->get('/financial-audit/'.$entry->id)->assertOk()->assertSee('Download retained audit-original.pdf');
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertOk()->assertDownload('audit-original.pdf');
        $this->get('/ledger/'.$entry->id.'/edit')->assertForbidden();
        $this->delete('/ledger/'.$entry->id)->assertForbidden();
        $postedEvent = AuditLog::where('module', 'ledger')->where('record_id', $entry->id)->where('action', 'post')->firstOrFail();
        $this->assertSame('Reviewed', $postedEvent->evidence['before']['status']);
        $this->assertSame('Posted', $postedEvent->evidence['after']['status']);
        $this->user('manager')->role->permissions()->detach(Permission::where('name', 'document.download')->value('id'));
        $this->get('/financial-audit/'.$entry->id)->assertOk()->assertDontSee('Download retained');
        $this->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/ledger/'.$entry->id.'/evidence/'.$evidence->id)->assertForbidden();
    }

    public static function mutations(): array
    {
        return [['audit_logs', 'update'], ['audit_logs', 'delete'], ['security_events', 'update'], ['security_events', 'delete']];
    }

    #[DataProvider('mutations')]
    public function test_database_history_is_append_only(string $table, string $operation): void
    {
        if ($table === 'audit_logs') {
            $id = DB::table($table)->insertGetId(['module' => 'auth', 'action' => 'historical', 'description' => 'RETAINED TECHNICAL EVENT', 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $id = DB::table($table)->insertGetId(['action' => 'historical', 'created_at' => now()]);
        }
        $before = DB::table($table)->where('id', $id)->first();
        try {
            $operation === 'update' ? DB::table($table)->where('id', $id)->update(['action' => 'tampered']) : DB::table($table)->where('id', $id)->delete();
            $this->fail('Audit mutation was permitted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertEquals($before, DB::table($table)->where('id', $id)->first());
        $this->actingAs($this->superAdmin())->get($table === 'audit_logs' ? '/admin/activity-logs' : '/admin/security-logs')->assertOk()->assertSee('historical');
        $this->actingAs($this->user('owner'))->get('/financial-audit')->assertOk()->assertDontSee('RETAINED TECHNICAL EVENT');
    }
}
