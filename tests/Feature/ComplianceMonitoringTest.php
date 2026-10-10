<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\User;
use App\Services\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComplianceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
    }

    public function test_compliance_summary_counts_are_accurate(): void
    {
        $this->actingAs($this->owner);
        $summary = Summary::compliance();

        $this->assertSame(6, $summary['pending']);
        $this->assertSame(4, $summary['submission_overdue']);
        $this->assertSame(1, $summary['submission_approaching']);
        $this->assertSame(1, $summary['filing_overdue']);
        $this->assertSame(3, $summary['filing_approaching']);
        $this->assertSame(0, $summary['filed']);
        $this->assertSame(0, $summary['completed']);
    }

    public function test_filed_and_completed_are_excluded_from_pending(): void
    {
        $this->actingAs($this->owner);
        ComplianceRecord::where('requirement', 'Periodic report preparation')->first()->update(['status' => 'Filed', 'filed_date' => today(), 'reference_number' => 'ACK-1']);

        $summary = Summary::compliance();
        $this->assertSame(5, $summary['pending']);
        $this->assertSame(1, $summary['filed']);
    }

    public function test_monitoring_page_renders_for_authorized_roles(): void
    {
        foreach ([$this->owner, $this->manager, $this->bookkeeper] as $user) {
            $this->actingAs($user)->get('/compliance/monitoring')->assertOk()->assertSee('Periodic report preparation');
        }
    }

    public function test_staff_filter_is_hidden_from_bookkeeper(): void
    {
        $this->actingAs($this->bookkeeper)->get('/compliance/monitoring')->assertOk()->assertDontSee('name="assigned_to"', false);
        $this->actingAs($this->owner)->get('/compliance/monitoring')->assertOk()->assertSee('name="assigned_to"', false);
    }

    public function test_bookkeeper_only_sees_assigned_client_records(): void
    {
        $otherClient = Client::create([
            'client_code' => 'CL-SECRET', 'business_name' => 'Secret Corp', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
        ]);
        ComplianceRecord::create([
            'client_id' => $otherClient->id, 'agency' => 'SEC', 'requirement' => 'Secret Filing',
            'due_date' => today()->addDays(10), 'status' => 'Pending', 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->bookkeeper)->get('/compliance/monitoring')->assertOk()->assertDontSee('Secret Filing');
        $this->actingAs($this->owner)->get('/compliance/monitoring')->assertOk()->assertSee('Secret Filing');
    }

    public function test_client_checklist_is_isolated_and_reaches_correct_records(): void
    {
        $client = Client::first();
        $this->actingAs($this->owner)->get('/clients/'.$client->id.'/compliance')->assertOk()->assertSee('Periodic report preparation');

        $other = Client::create([
            'client_code' => 'CL-OTHER', 'business_name' => 'Other Corp', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
        ]);
        $this->actingAs($this->bookkeeper)->get('/clients/'.$other->id.'/compliance')->assertForbidden();
    }
}
