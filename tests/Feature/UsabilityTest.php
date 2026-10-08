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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function addClients(): void
    {
        for ($i = 1; $i <= 37; $i++) {
            Client::create(['client_code' => 'PAG-'.$i, 'business_name' => 'ABC Pagination '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'business_type' => 'Corporation', 'status' => $i % 2 ? 'Active' : 'Inactive', 'created_by' => $this->owner->id, 'assigned_to' => User::where('email', 'bookkeeper@veritascore.local')->value('id')]);
        }
    }

    public function test_default_and_selectable_page_sizes_and_persisted_filters(): void
    {
        $this->addClients();
        $this->get('/clients')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && $r->count() === 10 && $r->total() === 43)->assertSee('Showing')->assertSee('records');
        foreach ([10, 25, 50] as $size) {
            $this->get('/clients?per_page='.$size)->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === $size);
        }
        $response = $this->get('/clients?search=ABC&status=Active&sort=business_name&direction=asc&per_page=10&page=2')->assertOk();
        $response->assertViewHas('records', function ($records) {
            parse_str(parse_url($records->previousPageUrl(), PHP_URL_QUERY), $params);

            return $records->total() === 19 && $records->count() === 9 && $records->firstItem() === 11 && $params === ['search' => 'ABC', 'status' => 'Active', 'sort' => 'business_name', 'direction' => 'asc', 'per_page' => '10', 'page' => '1'];
        });
        $response->assertSee('name="sort" value="business_name"', false)->assertSee('name="direction" value="asc"', false);
        $this->getJson('/clients?per_page=100000')->assertUnprocessable();
        $this->getJson('/clients?per_page[]=10')->assertUnprocessable();
        $this->get('/clients?q=NoMatchingClient')->assertOk()->assertViewHas('records', fn ($r) => $r->total() === 0)->assertSee('Showing');
    }

    public function test_all_major_modules_and_reports_use_backend_pagination(): void
    {
        $map = ['documents' => [Document::class, 'document_number'], 'ledger' => [LedgerEntry::class, 'reference_number'], 'compliance' => [ComplianceRecord::class, 'requirement'], 'billing' => [Invoice::class, 'invoice_number'], 'knowledge' => [KnowledgeArticle::class, 'slug']];
        foreach ($map as $module => [$model,$unique]) {
            $original = $model::first();
            for ($i = 1; $i <= 15; $i++) {
                $copy = $original->replicate();
                $copy->$unique = 'PAG-'.$module.'-'.$i;
                $copy->save();
            }
            $this->get('/'.$module.'?page=2')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && $r->currentPage() === 2 && $r->count() > 0);
            $this->get('/reports?module='.$module.'&per_page=10&page=2')->assertOk()->assertViewHas('records', fn ($r) => $r->perPage() === 10 && str_contains($r->previousPageUrl(), 'module='.$module));
        }
        for ($i = 1; $i <= 12; $i++) {
            User::create(['name' => 'Paged User '.$i, 'email' => 'paged'.$i.'@example.com', 'password' => 'password123', 'role_id' => $this->owner->role_id, 'status' => 'Active']);
        }
        $this->get('/admin/users?search=Paged&status=Active&per_page=10&page=2')->assertOk()->assertViewHas('users', fn ($r) => $r->total() === 12 && $r->count() === 2 && str_contains($r->previousPageUrl(), 'search=Paged'));
    }

    public function test_billing_totals_ignore_table_search_filters_sort_and_page(): void
    {
        $baseline = $this->get('/billing')->assertOk()->viewData('billingSummary');
        $this->assertSame('39000.00', $baseline['billed']);
        foreach (['?q=Davao', '?search=NoInvoiceMatches', '?status=Paid', '?client_id='.Client::first()->id, '?from=2099-01-01', '?per_page=25&sort=due_date&direction=asc&page=2'] as $query) {
            $this->get('/billing'.$query)->assertOk()->assertViewHas('billingSummary', $baseline);
        }
        $this->get('/billing?q=NoInvoiceMatches')->assertViewHas('records', fn ($r) => $r->total() === 0)->assertSee('39,000.00')->assertSee('Search and filters below affect the table only.');
        $this->get('/billing?search=PAY-001')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=Paid')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=Overdue')->assertViewHas('records', fn ($r) => $r->total() === 1);
        $this->get('/billing?search=INV-')->assertViewHas('records', fn ($r) => $r->total() === 6);
    }

    public function test_static_billing_totals_do_not_leak_unassigned_finances(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $client = Client::create(['client_code' => 'SECRET-FINANCE', 'business_name' => 'Private Finance Client', 'business_type' => 'Corporation', 'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id]);
        $invoice = Invoice::create(['invoice_number' => 'PRIVATE-INVOICE', 'client_id' => $client->id, 'invoice_date' => today(), 'due_date' => today()->addDays(10), 'tax' => 0, 'status' => 'Open', 'created_by' => $this->owner->id]);
        $invoice->items()->create(['description' => 'Confidential', 'quantity' => 1, 'unit_price' => '777777.00']);
        $this->actingAs($bookkeeper);
        foreach (['/billing', '/billing?search=PRIVATE', '/billing?client_id='.$client->id] as $url) {
            $this->get($url)->assertOk()->assertViewHas('billingSummary', fn ($s) => $s['billed'] === '39000.00')->assertDontSee('Private Finance Client')->assertDontSee('777,777');
        }
    }

    public function test_page_size_settings_accept_ten_and_legacy_fifteen_falls_back_to_ten(): void
    {
        Setting::first()->update(['page_size' => 15]);
        $this->get('/clients')->assertViewHas('records', fn ($r) => $r->perPage() === 10);
        $this->put('/workspace', ['firm_name' => 'RBCIA', 'currency' => 'PHP', 'page_size' => 10])->assertRedirect();
        $this->assertDatabaseHas('settings',['page_size' => 10]);
    }
}
