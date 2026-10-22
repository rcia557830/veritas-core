<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 10)->startOfDay());
        $this->seed();
    }

    public function test_compliance_views_share_deadline_rules_and_paginate_before_rendering(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);
        $client = Client::firstOrFail();
        $base = ['client_id' => $client->id, 'agency' => 'BIR', 'status' => 'Pending', 'created_by' => $owner->id];
        // Filing is upcoming, but submission is overdue: the record belongs in Overdue.
        for ($i = 0; $i < 12; $i++) {
            ComplianceRecord::create($base + ['requirement' => 'Workspace overdue '.$i, 'due_date' => '2026-10-15']);
        }
        ComplianceRecord::create($base + ['requirement' => 'Workspace upcoming', 'due_date' => '2026-10-25']);
        ComplianceRecord::create(array_replace($base, ['requirement' => 'Workspace filed', 'due_date' => '2026-10-01', 'status' => 'Filed', 'filed_date' => '2026-10-01', 'reference_number' => 'WS-ACK']));
        foreach (['/compliance', '/compliance/monitoring'] as $url) {
            $this->get($url.'?q=Workspace&view=overdue&page=2')->assertOk()
                ->assertViewHas('records', fn ($records) => $records->total() === 12 && $records->count() === 2 && str_contains($records->previousPageUrl(), 'view=overdue'));
            $this->get($url.'?q=Workspace&view=upcoming')->assertOk()
                ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->requirement === 'Workspace upcoming');
            $this->get($url.'?q=Workspace&view=completed')->assertOk()
                ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->requirement === 'Workspace filed');
        }
        $this->get('/compliance?q=Workspace&from=2026-10-25&to=2026-10-25')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->get('/compliance?q=Workspace&to=2026-10-01')->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1);
        $this->getJson('/compliance?view[]=overdue')->assertUnprocessable();
    }

    public function test_document_workspace_keeps_client_context_and_assignment_privacy(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $client = Client::where('assigned_to', $bookkeeper->id)->firstOrFail();
        $other = Client::create(['client_code' => 'WS-PRIVATE', 'business_name' => 'Workspace private', 'business_type' => 'Corporation', 'status' => 'Active', 'created_by' => $owner->id, 'assigned_to' => $owner->id]);
        foreach ([$client, $other] as $item) {
            DocumentRequirement::create(['client_id' => $item->id, 'name' => 'Workspace requirement '.$item->id, 'type' => 'Receipt', 'scope' => 'periodic', 'is_active' => true, 'is_required' => true, 'created_by' => $owner->id]);
        }
        $this->actingAs($bookkeeper)->get('/requirements/monitoring?state=missing&client_id='.$client->id)->assertOk()
            ->assertViewHas('records', fn ($records) => $records->every(fn ($r) => $r->client_id === $client->id))
            ->assertSee('Workspace requirement '.$client->id)->assertDontSee('Workspace requirement '.$other->id);
        $this->get('/requirements/monitoring?state=missing&client_id='.$other->id)->assertOk()
            ->assertViewHas('records', fn ($records) => $records->isEmpty())->assertDontSee('Workspace private');
        $this->get('/documents?client_id='.$client->id)->assertOk()
            ->assertSee('Next required action')->assertSee('Missing Requirements')
            ->assertSee(route('requirements.monitoring', ['state' => 'missing', 'client_id' => $client->id]))
            ->assertDontSee('>Validate document</a>', false);
        $document = Document::where('client_id', $client->id)->firstOrFail();
        $this->actingAs($owner)->get('/documents?client_id='.$client->id)->assertOk()->assertSee($document->title);
    }

    public function test_compliance_followups_are_available_on_the_record_and_still_authorized(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $record = ComplianceRecord::firstOrFail();
        $followUp = $record->followUps()->create(['status' => 'Open', 'created_by' => $owner->id]);
        $this->actingAs($owner)->get(route('compliance.show', $record))->assertOk()
            ->assertSee('id="follow-ups"', false)->assertSee(route('compliance.follow-ups.update', $followUp), false);
        $this->put(route('compliance.follow-ups.update', $followUp), ['status' => 'Resolved', 'remarks' => 'Received confirmation'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Resolved', $followUp->fresh()->status);
        $this->get('/compliance?follow_up=open')->assertOk()
            ->assertViewHas('records', fn ($records) => ! $records->contains('id', $record->id));
    }

    public function test_client_document_overview_renders_metrics_and_not_configured_state(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);

        // Client without requirements should show "Not configured", not 100%
        $emptyClient = Client::create([
            'client_code' => 'WS-EMPTY',
            'business_name' => 'Empty Requirements Client',
            'business_type' => 'Corporation',
            'status' => 'Active',
            'created_by' => $owner->id,
            'assigned_to' => $owner->id,
        ]);
        $this->get('/documents?client_id='.$emptyClient->id)->assertOk()
            ->assertSee('Client document completeness overview')
            ->assertSee('Not configured')
            ->assertSee('No active required documents configured for this client.')
            ->assertDontSee('100% Complete');

        // Client with requirements should display metric categories
        $client = Client::firstOrFail();
        $this->get('/documents?client_id='.$client->id)->assertOk()
            ->assertSee('Client document completeness overview')
            ->assertSee('Required')
            ->assertSee('Submitted')
            ->assertSee('Verified')
            ->assertSee('Awaiting')
            ->assertSee('Missing');
    }

    public function test_missing_documents_monitoring_displays_follow_up_column(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);

        $client = Client::firstOrFail();
        $requirement = DocumentRequirement::firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Monthly Sales Receipts'],
            ['type' => 'Receipt', 'scope' => 'periodic', 'is_active' => true, 'is_required' => true, 'created_by' => $owner->id]
        );
        $followUp = $requirement->followUps()->create([
            'status' => 'In Progress',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'follow_up_date' => now()->toDateString(),
            'remarks' => 'Requested physical copy',
        ]);

        $this->get('/requirements/monitoring?client_id='.$client->id)->assertOk()
            ->assertSee('Follow-up')
            ->assertSee('In Progress')
            ->assertSee($owner->name);
    }
}
