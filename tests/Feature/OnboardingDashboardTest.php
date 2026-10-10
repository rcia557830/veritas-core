<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\OnboardingReadiness;
use App\Services\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OnboardingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    public function test_organization_counts_onboarding_buckets(): void
    {
        $this->actingAs($this->owner);

        // Seeded clients have no requirements -> all not_configured.
        $buckets = OnboardingReadiness::organization();
        $this->assertSame(6, $buckets['not_configured']);

        // One client becomes ready.
        $client = Client::first();
        $requirement = DocumentRequirement::create([
            'client_id' => $client->id, 'name' => 'COR', 'type' => 'Certificate of Registration',
            'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ]);
        $document = \App\Models\Document::create([
            'client_id' => $client->id, 'document_number' => 'DOC-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => 'COR', 'document_type' => 'Certificate of Registration', 'status' => 'Approved',
            'received_date' => today(), 'uploaded_by' => $this->owner->id,
        ]);
        $requirement->documents()->attach($document->id, ['linked_by' => $this->owner->id]);

        $buckets = OnboardingReadiness::organization();
        $this->assertSame(1, $buckets['ready_for_activation']);
        $this->assertSame(5, $buckets['not_configured']);
    }

    public function test_organization_is_permission_scoped(): void
    {
        // Client assigned to the owner is invisible to the bookkeeper.
        $ownerClient = Client::create([
            'client_code' => 'CL-OWNER', 'business_name' => 'Owner Only Co', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
        ]);
        DocumentRequirement::create([
            'client_id' => $ownerClient->id, 'name' => 'COR', 'type' => 'Certificate of Registration',
            'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ]);

        $ownerBuckets = OnboardingReadiness::organization($this->owner);
        $bookkeeperBuckets = OnboardingReadiness::organization($this->bookkeeper);

        $this->assertSame(1, $ownerBuckets['not_started']);
        $this->assertSame(0, $bookkeeperBuckets['not_started']);
    }

    public function test_dashboard_includes_onboarding_and_accounting_metrics(): void
    {
        $this->actingAs($this->owner);

        $ledgerClient = Client::first();
        LedgerEntry::create([
            'client_id' => $ledgerClient->id, 'transaction_date' => today(), 'reference_number' => 'OR-DRAFT',
            'description' => 'Draft entry', 'status' => 'Draft', 'created_by' => $this->owner->id,
        ]);
        LedgerEntry::create([
            'client_id' => $ledgerClient->id, 'transaction_date' => today(), 'reference_number' => 'OR-REVIEWED',
            'description' => 'Reviewed entry', 'status' => 'Reviewed', 'created_by' => $this->owner->id,
        ]);

        $stats = Summary::dashboard();
        $this->assertIsArray($stats['onboarding']);
        $this->assertArrayHasKey('pending', $stats['onboarding']);
        $this->assertGreaterThanOrEqual(1, $stats['ledger_draft']);
        $this->assertGreaterThanOrEqual(1, $stats['ledger_reviewed']);
        $this->assertSame(6, $stats['ledger']);
    }

    public function test_dashboard_page_renders_with_links(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pending onboarding')
            ->assertSee('Ready for activation')
            ->assertSee(route('clients.onboarding', ['state' => 'pending']), false);
    }

    public function test_onboarding_list_renders_for_all_roles(): void
    {
        foreach ([$this->owner, $this->bookkeeper] as $user) {
            $this->actingAs($user)->get(route('clients.onboarding'))->assertOk();
        }
    }

    public function test_onboarding_list_filter_by_state(): void
    {
        $this->actingAs($this->owner)->get(route('clients.onboarding', ['state' => 'not_configured']))->assertOk()->assertSee('Not configured');
    }
}
