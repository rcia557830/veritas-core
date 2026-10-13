<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\JournalFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class RoleDashboardTest extends TestCase
{
    use JournalFixtures, RefreshDatabase, SuperAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function employee(string $name): User
    {
        return User::where('email', $name.'@veritascore.local')->firstOrFail();
    }

    public function test_roles_have_distinct_cards_tables_actions_and_authorized_links(): void
    {
        $expected = [
            'owner' => ['owner', 'Outstanding Billing', 'Upcoming and overdue filings', 'Documents to validate'],
            'manager' => ['office-manager', 'Documents Awaiting Validation', 'Documents to validate', 'Your journal preparation'],
            'bookkeeper' => ['bookkeeper', 'Journals Needing Correction', 'Your journal preparation', 'Documents to validate'],
        ];
        foreach ($expected as $name => [$role, $card, $table, $absent]) {
            $response = $this->actingAs($this->employee($name))->get('/dashboard')->assertOk()
                ->assertSee('data-dashboard-role="'.$role.'"', false)->assertSee($card)->assertSee($table)->assertDontSee($absent);
            $response->assertViewHas('cards', fn ($cards) => count($cards) === 6);
            if ($name !== 'owner') {
                $response->assertDontSee('Outstanding Billing');
            }
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@data-dashboard-role]//a') as $link) {
                $this->followingRedirects()->get($link->getAttribute('href'))->assertOk();
            }
        }
    }

    public function test_bookkeeper_preparation_contains_only_own_journals_for_assigned_clients(): void
    {
        $bookkeeper = $this->employee('bookkeeper');
        $owner = $this->employee('owner');
        $client = Client::where('assigned_to', $bookkeeper->id)->firstOrFail();
        $mine = $this->structuredJournal($bookkeeper, 'Needs Correction', $client);
        $other = $this->structuredJournal($owner, 'Draft', $client);
        $private = Client::create(['client_code' => 'DASH-PRIVATE', 'business_name' => 'Private dashboard client', 'business_type' => 'Corporation', 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'status' => 'Active']);
        $unassigned = $this->structuredJournal($bookkeeper, 'Draft', $private);
        $response = $this->actingAs($bookkeeper)->get('/dashboard')->assertOk()->assertDontSee('Private dashboard client');
        $this->assertEquals([$mine->id], $response->viewData('journals')->modelKeys());
        $cards = collect($response->viewData('cards'))->keyBy('label');
        $this->assertSame(1, $cards['Journals Needing Correction']['value']);
        $this->assertSame(0, $cards['Draft Journals']['value']);
        $this->get('/ledger?work=mine&client_id='.$private->id)->assertOk()->assertViewHas('records', fn ($r) => $r->isEmpty());
        $this->get('/ledger/'.$unassigned->id)->assertForbidden();
        $this->get('/ledger/'.$other->id.'/edit')->assertForbidden();
        $this->post('/ledger/'.$mine->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->post('/ledger/'.$mine->id.'/post')->assertForbidden();
    }

    public function test_manager_review_and_posting_queues_exclude_self_and_enforce_permissions(): void
    {
        $manager = $this->employee('manager');
        $own = $this->structuredJournal($manager, 'For Review');
        $other = $this->structuredJournal($this->employee('bookkeeper'), 'For Review');
        $reviewed = $this->structuredJournal($this->employee('bookkeeper'), 'Reviewed');
        $this->actingAs($manager)->get('/ledger?work=reviewable')->assertOk()->assertViewHas('records', fn ($records) => $records->contains('id', $other->id) && ! $records->contains('id', $own->id) && ! $records->contains('id', $reviewed->id));
        $response = $this->get('/dashboard')->assertOk();
        $expected = LedgerEntry::where('status', 'For Review')->where('created_by', '!=', $manager->id)->count();
        $this->assertSame($expected, collect($response->viewData('cards'))->firstWhere('label', 'Pending Journal Reviews')['value']);
        $this->get('/ledger?work=postable')->assertOk()->assertViewHas('records', fn ($records) => $records->contains('id', $reviewed->id));
        $this->post('/ledger/'.$own->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->get('/ledger/create')->assertForbidden();
        $this->get('/ledger/'.$other->id.'/edit')->assertForbidden();
        $this->actingAs($this->employee('bookkeeper'))->get('/ledger?work=reviewable')->assertForbidden();
        $this->get('/ledger?work=postable')->assertForbidden();
        $this->get('/reports?module=ledger&work=reviewable')->assertForbidden();
    }

    public function test_revocation_removes_cards_tables_actions_and_denies_backend_access(): void
    {
        $manager = $this->employee('manager');
        $manager->role->permissions()->detach(Permission::whereIn('name', ['document.validate', 'bookkeeping.review', 'bookkeeping.post'])->pluck('id'));
        $this->actingAs($manager->fresh())->get('/dashboard')->assertOk()->assertDontSee('Documents Awaiting Validation')->assertDontSee('Documents to validate')->assertDontSee('Pending Journal Reviews')->assertDontSee('Journal Review Queue')->assertDontSee('Reviewed awaiting posting');
        $this->get('/ledger?work=reviewable')->assertForbidden();
        $this->get('/ledger?work=postable')->assertForbidden();
        $this->get('/documents/'.Document::firstOrFail()->id.'/validate')->assertForbidden();
        $bookkeeper = $this->employee('bookkeeper');
        $bookkeeper->role->permissions()->detach();
        $this->actingAs($bookkeeper->fresh())->get('/dashboard')->assertOk()->assertViewHas('cards', [])->assertViewHas('tasks', [])->assertViewHas('shortcuts', [])->assertDontSee('Your journal preparation')->assertDontSee('data-nav-group="clients"', false);
        $this->get('/ledger')->assertForbidden();
        $this->get('/clients')->assertForbidden();
    }

    public function test_super_admin_never_queries_business_tables_even_with_accidental_business_grants(): void
    {
        $admin = $this->superAdmin();
        $admin->role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['client.view', 'billing.view', 'bookkeeping.view', 'account.view'])->pluck('id'));
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk()->assertSee('Technical administration')->assertDontSee('data-nav-group="accounting"', false);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+["`]?\b(clients|documents|ledger_entries|invoices|payments|compliance_records)\b/i', $sql);
        }
        foreach (['/clients', '/documents', '/ledger', '/billing', '/compliance', '/financial-reports', '/financial-audit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs($this->employee('owner'));
        foreach (['/admin/users', '/admin/roles', '/workspace', '/admin/security-logs', '/admin/activity-logs'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}
