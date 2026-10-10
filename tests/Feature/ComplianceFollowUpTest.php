<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceFollowUp;
use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->client = Client::first();
        $this->client->update(['assigned_to' => $this->bookkeeper->id]);
    }

    private function record(array $extra = []): ComplianceRecord
    {
        return ComplianceRecord::create(array_merge([
            'client_id' => $this->client->id, 'agency' => 'BIR', 'requirement' => 'VAT return',
            'due_date' => today()->addDays(20), 'status' => 'Pending', 'created_by' => $this->owner->id,
        ], $extra));
    }

    public function test_staff_can_create_and_complete_follow_up(): void
    {
        $record = $this->record();
        $this->actingAs($this->owner)
            ->post('/compliance/'.$record->id.'/follow-ups', [
                'status' => 'Open', 'assigned_to' => $this->bookkeeper->id,
                'follow_up_date' => today()->toDateString(), 'remarks' => 'Request receipts',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $followUp = ComplianceFollowUp::firstOrFail();
        $this->assertSame('Open', $followUp->status);
        $this->assertNull($followUp->completed_at);

        $this->actingAs($this->bookkeeper)
            ->put('/compliance-follow-ups/'.$followUp->id, [
                'status' => 'Resolved', 'follow_up_date' => today()->toDateString(), 'remarks' => 'Received',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Resolved', $followUp->fresh()->status);
        $this->assertNotNull($followUp->fresh()->completed_at);
    }

    public function test_bookkeeper_cannot_manage_follow_up_for_unassigned_client(): void
    {
        $other = Client::create([
            'client_code' => 'CL-FU', 'business_name' => 'Unassigned Corp', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
        ]);
        $record = $this->record(['client_id' => $other->id]);

        $this->actingAs($this->bookkeeper)
            ->post('/compliance/'.$record->id.'/follow-ups', ['status' => 'Open', 'remarks' => 'nope'])
            ->assertForbidden();
    }

    public function test_follow_up_assignment_must_be_client_staff(): void
    {
        $record = $this->record();
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'password123',
            'role_id' => $this->bookkeeper->role_id, 'status' => 'Active',
        ]);

        $this->actingAs($this->owner)
            ->post('/compliance/'.$record->id.'/follow-ups', [
                'status' => 'Open', 'assigned_to' => $stranger->id, 'remarks' => 'bad',
            ])->assertSessionHasErrors('assigned_to');
    }

    public function test_follow_up_can_be_removed(): void
    {
        $record = $this->record();
        $this->actingAs($this->owner)->post('/compliance/'.$record->id.'/follow-ups', ['status' => 'Open', 'remarks' => 'x'])->assertRedirect();
        $followUp = ComplianceFollowUp::firstOrFail();

        $this->actingAs($this->owner)->delete('/compliance-follow-ups/'.$followUp->id)->assertRedirect();
        $this->assertSoftDeleted('compliance_follow_ups', ['id' => $followUp->id]);
    }
}
