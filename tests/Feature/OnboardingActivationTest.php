<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OnboardingActivationTest extends TestCase
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

    private function client(array $extra = []): Client
    {
        return Client::create(array_merge([
            'client_code' => 'CL-'.strtoupper(bin2hex(random_bytes(4))),
            'business_name' => 'Activation Co', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->bookkeeper->id,
        ], $extra));
    }

    private function readyClient(): Client
    {
        $client = $this->client();
        $requirement = DocumentRequirement::create([
            'client_id' => $client->id, 'name' => 'COR', 'type' => 'Certificate of Registration',
            'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ]);
        $document = Document::create([
            'client_id' => $client->id, 'document_number' => 'DOC-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => 'COR document', 'document_type' => 'Certificate of Registration', 'status' => 'Approved',
            'received_date' => today(), 'uploaded_by' => $this->owner->id,
        ]);
        $requirement->documents()->attach($document->id, ['linked_by' => $this->owner->id]);

        return $client;
    }

    public function test_owner_can_activate_ready_client(): void
    {
        $client = $this->readyClient();
        $this->actingAs($this->owner)->post(route('clients.onboarding.complete', $client))->assertRedirect();
        $this->assertNotNull($client->fresh()->onboarded_at);
        $this->assertSame($this->owner->id, $client->fresh()->onboarded_by);
    }

    public function test_manager_can_activate_ready_client(): void
    {
        $client = $this->readyClient();
        $this->actingAs($this->manager)->post(route('clients.onboarding.complete', $client))->assertRedirect();
        $this->assertNotNull($client->fresh()->onboarded_at);
    }

    public function test_bookkeeper_cannot_activate_client(): void
    {
        $client = $this->readyClient();
        $this->actingAs($this->bookkeeper)->post(route('clients.onboarding.complete', $client))->assertForbidden();
        $this->assertNull($client->fresh()->onboarded_at);
    }

    public function test_activation_is_blocked_when_requirements_unverified(): void
    {
        $client = $this->client();
        DocumentRequirement::create([
            'client_id' => $client->id, 'name' => 'COR', 'type' => 'Certificate of Registration',
            'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->post(route('clients.onboarding.complete', $client))
            ->assertRedirect()->assertSessionHas('onboarding_blockers');

        $this->assertNull($client->fresh()->onboarded_at);
        $this->assertSame('Active', $client->fresh()->status);
    }

    public function test_exemption_requires_justification(): void
    {
        $client = $this->client(); // no onboarding requirements -> not_configured

        $this->actingAs($this->owner)->post(route('clients.onboarding.complete', $client))
            ->assertRedirect()->assertSessionHasErrors('exemption_reason');

        $this->assertNull($client->fresh()->onboarded_at);
    }

    public function test_exemption_with_justification_completes_onboarding(): void
    {
        $client = $this->client();

        $this->actingAs($this->owner)->post(route('clients.onboarding.complete', $client), [
            'exemption_reason' => 'Client is a walk-in one-time engagement; no CBL/COR applicable.',
        ])->assertRedirect();

        $this->assertNotNull($client->fresh()->onboarded_at);
    }

    public function test_bookkeeper_cannot_view_other_clients_onboarding(): void
    {
        $other = Client::create([
            'client_code' => 'CL-SECRET', 'business_name' => 'Secret Corp', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
        ]);

        $this->actingAs($this->bookkeeper)->get(route('clients.onboarding.checklist', $other))->assertForbidden();
        $this->actingAs($this->owner)->get(route('clients.onboarding.checklist', $other))->assertOk();
    }

    public function test_activation_is_audited(): void
    {
        $client = $this->readyClient();
        $this->actingAs($this->owner)->post(route('clients.onboarding.complete', $client));

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'clients',
            'action' => 'onboarding.completed',
            'record_id' => $client->id,
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_client_edit_records_field_level_audit(): void
    {
        $client = $this->client();
        $this->actingAs($this->owner)->put(route('clients.update', $client), [
            'business_name' => 'Renamed Co', 'business_type' => 'Corporation',
            'contact_person' => 'New Contact', 'email' => 'new@example.com', 'phone' => '09171234567',
            'tin' => '123456789000', 'address' => 'New Address',
            'registration_status' => 'On file', 'business_license_status' => 'On file',
            'status' => 'Active', 'notes' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'clients', 'action' => 'client.updated', 'record_id' => $client->id,
        ]);
    }
}
