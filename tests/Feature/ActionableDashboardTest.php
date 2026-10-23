<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\JournalWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JournalFixtures;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class ActionableDashboardTest extends TestCase
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

    public function test_owner_dashboard_highlights_four_key_actionable_queues_with_direct_links(): void
    {
        $owner = $this->employee('owner');
        $bookkeeper = $this->employee('bookkeeper');
        $client = Client::firstOrFail();

        // 1. Create a journal awaiting review
        $entry = $this->structuredJournal($bookkeeper, 'Draft', $client);
        $this->actingAs($bookkeeper);
        JournalWriter::transition($entry, 'submit', 'For owner review');

        // 2. Ensure an unpaid invoice exists
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TEST-UNPAID',
            'status' => 'Sent',
            'invoice_date' => today()->subDays(15),
            'due_date' => today()->subDays(10),
            'created_by' => $owner->id,
        ]);
        $invoice->items()->create([
            'description' => 'Accounting Services',
            'quantity' => 1,
            'unit_price' => 15000.00,
        ]);

        $response = $this->actingAs($owner)->get('/dashboard');
        $response->assertOk();

        // Check Action Items panel
        $response->assertSee('Priority Action Items');

        // 1. Journals awaiting review highlight & direct links
        $response->assertSee('Journals Awaiting Review');
        $response->assertSee(route('ledger.index', ['work' => 'reviewable']));
        $response->assertSee('Review entry');

        // 2. Missing documents highlight & direct link
        $response->assertSee('Missing Statutory Documents');
        $response->assertSee(route('requirements.monitoring', ['state' => 'missing']));

        // 3. Upcoming deadlines highlight & direct link
        $response->assertSee('Upcoming Compliance Deadlines');
        $response->assertSee(route('compliance.monitoring', ['deadline' => 'filing_approaching']));

        // 4. Unpaid invoices highlight & direct link
        $response->assertSee('Unpaid Invoices');
        $response->assertSee(route('billing.index', ['queue' => 'outstanding']));
        $response->assertSee('INV-TEST-UNPAID');

        // Verify direct links in the dashboard main queue tables
        $response->assertSee('Journals awaiting review');
        $response->assertSee('Review entry');
        $response->assertSee('Unpaid client invoices');
        $response->assertSee('View invoice');
    }

    public function test_office_manager_dashboard_highlights_reviewable_journals_and_missing_documents(): void
    {
        $manager = $this->employee('manager');
        $bookkeeper = $this->employee('bookkeeper');
        $client = Client::firstOrFail();

        // Create a journal awaiting review
        $entry = $this->structuredJournal($bookkeeper, 'Draft', $client);
        $this->actingAs($bookkeeper);
        JournalWriter::transition($entry, 'submit', 'For manager review');

        $response = $this->actingAs($manager)->get('/dashboard');
        $response->assertOk();

        // Manager sees Priority Action Items
        $response->assertSee('Priority Action Items');
        $response->assertSee('Journals Awaiting Review');
        $response->assertSee(route('ledger.index', ['work' => 'reviewable']));
        $response->assertSee('Review entry');

        // Missing documents and upcoming deadlines
        $response->assertSee('Missing Statutory Documents');
        $response->assertSee(route('requirements.monitoring', ['state' => 'missing']));
        $response->assertSee('Upcoming Compliance Deadlines');
        $response->assertSee(route('compliance.monitoring', ['deadline' => 'filing_approaching']));

        // Manager does not have invoice links on dashboard
        $response->assertDontSee('Unpaid Invoices');
    }

    public function test_bookkeeper_dashboard_action_items_are_client_scoped_and_safe(): void
    {
        $bookkeeper = $this->employee('bookkeeper');

        $response = $this->actingAs($bookkeeper)->get('/dashboard');
        $response->assertOk();

        // Bookkeeper sees Priority Action Items
        $response->assertSee('Priority Action Items');

        // Bookkeeper has direct links for missing documents and upcoming deadlines
        $response->assertSee('Missing Statutory Documents');
        $response->assertSee(route('requirements.monitoring', ['state' => 'missing']));
        $response->assertSee('Upcoming Compliance Deadlines');
        $response->assertSee(route('compliance.monitoring', ['deadline' => 'filing_approaching']));

        // Bookkeeper must NEVER receive reviewable or billing links
        $response->assertDontSee('work=reviewable');
        $response->assertDontSee('Unpaid Invoices');
    }
}
