<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Access;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\JournalEvidence;
use App\Services\OnboardingReadiness;
use App\Support\DemoDatabase;
use App\Support\Money;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_linked_balanced_secure_and_repeatable(): void
    {
        Storage::fake('local');
        config(['demo.enabled' => true]);
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('clients', 30);
        $this->assertDatabaseCount('document_requirements', 210);
        $this->assertDatabaseCount('documents', 204);
        $this->assertDatabaseCount('knowledge_articles', 36);
        $this->assertDatabaseCount('compliance_records', 120);
        $this->assertDatabaseCount('ledger_entries', 360);
        $this->assertDatabaseCount('vouchers', 360);
        $this->assertDatabaseCount('invoices', 30);
        $this->assertDatabaseCount('payments', 22);
        $this->assertEqualsCanonicalizing(['JV', 'CV', 'CR', 'CD'], Voucher::distinct()->pluck('type')->all());
        $this->assertEqualsCanonicalizing(['Draft', 'For Review', 'Reviewed', 'Posted', 'Needs Correction'], LedgerEntry::distinct()->pluck('status')->all());
        foreach (LedgerEntry::with('items', 'evidence')->get() as $entry) {
            $this->assertSame($entry->items->sum(fn ($i) => Money::cents($i->debit)), $entry->items->sum(fn ($i) => Money::cents($i->credit)));
            foreach ($entry->items as $item) {
                $this->assertSame($entry->client_id, $item->account->client_id);
            }
            JournalEvidence::verify($entry->evidence->sole());
            if ($entry->status === 'Posted') {
                $this->assertNotSame($entry->created_by, $entry->reviewed_by);
                $this->assertNotNull($entry->posted_at);
                $this->assertDatabaseHas('audit_logs', ['module' => 'ledger', 'record_id' => $entry->id, 'action' => 'post']);
            }
        }
        $this->actingAs(User::where('email', 'owner@demo.example.test')->sole());
        foreach (Client::all() as $client) {
            $report = app(FinancialReports::class)->generate($client, 'trial-balance', today()->startOfYear()->toDateString(), today()->toDateString());
            $this->assertSame(0, $report['difference']);
            $this->assertSame(14800000, $report['debits']);
            $balance = app(FinancialReports::class)->generate($client, 'balance-sheet', today()->startOfYear()->toDateString(), today()->toDateString());
            $this->assertSame(0, $balance['equationDifference']);
            $this->assertSame(1500000, $balance['currentEarnings']);
        }
        foreach (Client::all() as $client) {
            $this->assertMatchesRegularExpression('/^[0-9+()\-. ]{7,20}$/', $client->phone, 'Demo client phone passes update validation');
            $this->assertMatchesRegularExpression('/^\d{3}[ -]?\d{3}[ -]?\d{3}([ -]?\d{0,3})?$/', $client->tin, 'Demo client TIN passes update validation');
        }
        $states = Client::all()->map(fn ($client) => OnboardingReadiness::state($client))->unique()->all();
        $this->assertContains('onboarded', $states);
        $this->assertContains('ready_for_activation', $states);
        $this->assertContains('needs_clarification', $states);
        foreach (Document::with('requirements')->get() as $document) {
            $this->assertSame($document->client_id, $document->requirements->sole()->client_id);
            $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($document->file_path));
        }
        foreach (ComplianceRecord::all() as $record) {
            $this->assertSame($record->due_date->copy()->subDays(10)->toDateString(), $record->submission_deadline->toDateString());
        }
        $this->assertEqualsCanonicalizing(['Open', 'Partially Paid', 'Paid', 'Overdue'], Invoice::all()->map->display_status->unique()->all());
        $this->assertSame(13350000, Invoice::all()->sum->balance_cents);
        $bookkeeper = User::where('email', 'bookkeeper@demo.example.test')->sole();
        $this->assertSame(25, Access::query(Client::class, $bookkeeper)->count());
        $this->actingAs($bookkeeper);
        foreach ([['/knowledge', 36], ['/knowledge?type=Internal%20Procedure', 14], ['/knowledge?q=template', 8], ['/knowledge?q=guideline', 8], ['/ledger?work=mine', 300]] as [$url, $count]) {
            $this->get($url)->assertOk()->assertViewHas('records', fn ($records) => $records->total() === $count);
        }
        $article = KnowledgeArticle::where('title', 'Accounting Voucher Template')->sole();
        $this->get('/knowledge/'.$article->id)->assertOk()->assertSee('COMPLETION CHECK');
        $this->get('/knowledge?q=template&page=2')->assertOk();
        $this->get('/ledger?work=mine&client_id=6')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->actingAs(User::where('email', 'owner@demo.example.test')->sole());
        $this->assertFalse(Hash::check('password123', $bookkeeper->password));
        $tables = ['users', 'clients', 'documents', 'document_requirements', 'ledger_entries', 'ledger_items', 'vouchers', 'payments', 'audit_logs', 'knowledge_articles'];
        $counts = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $hash = $bookkeeper->password;
        $this->seed(DemoDataSeeder::class);
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($hash, $bookkeeper->fresh()->password);
    }

    public function test_demo_requires_opt_in_and_rejects_production_before_writing(): void
    {
        foreach ([['testing', false], ['production', true], ['staging', true]] as [$env, $enabled]) {
            $this->app->instance('env', $env);
            config(['demo.enabled' => $enabled]);
            try {
                DemoDatabase::check();
                $this->fail('Unsafe environment accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('requires local/testing', $error->getMessage());
            } finally {
                $this->app->instance('env', 'testing');
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_rejects_business_database_name_without_connecting(): void
    {
        $previous = config('database.default');
        config(['demo.enabled' => true, 'database.connections.forbidden' => ['driver' => 'sqlite', 'database' => 'veritas_core_db'], 'database.default' => 'forbidden']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('business databases are forbidden');
        try {
            DemoDatabase::check();
        } finally {
            config(['database.default' => $previous]);
        }
    }

    public function test_demo_refuses_existing_unmarked_data(): void
    {
        config(['demo.enabled' => true]);
        Setting::create(['firm_name' => 'Existing business']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty disposable demo database');
        $this->seed(DemoDataSeeder::class);
    }
}
