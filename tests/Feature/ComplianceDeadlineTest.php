<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceDeadlineTest extends TestCase
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

    private function data(array $extra = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'agency' => 'BIR',
            'requirement' => 'Monthly VAT return',
            'reporting_period' => '2026-09',
            'due_date' => today()->addDays(20)->toDateString(),
            'status' => 'Pending',
        ], $extra);
    }

    public function test_creating_record_auto_computes_submission_deadline(): void
    {
        $this->actingAs($this->owner)->post('/compliance', $this->data())->assertRedirect()->assertSessionHasNoErrors();

        $record = ComplianceRecord::where('requirement', 'Monthly VAT return')->firstOrFail();
        $this->assertSame(today()->addDays(10)->toDateString(), $record->submission_deadline->toDateString());
        $this->assertFalse((bool) $record->submission_deadline_is_manual);
    }

    public function test_manual_override_requires_justification(): void
    {
        $this->actingAs($this->owner);
        $this->post('/compliance', $this->data(['submission_deadline' => today()->addDays(15)->toDateString()]))
            ->assertSessionHasErrors('submission_deadline_override_reason');

        $this->post('/compliance', $this->data([
            'submission_deadline' => today()->addDays(15)->toDateString(),
            'submission_deadline_override_reason' => 'Client requested a later internal date.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $record = ComplianceRecord::where('requirement', 'Monthly VAT return')->firstOrFail();
        $this->assertSame(today()->addDays(15)->toDateString(), $record->submission_deadline->toDateString());
        $this->assertTrue((bool) $record->submission_deadline_is_manual);
    }

    public function test_submission_deadline_after_filing_deadline_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/compliance', $this->data(['submission_deadline' => today()->addDays(25)->toDateString()]))
            ->assertSessionHasErrors('submission_deadline');
    }

    public function test_changing_filing_deadline_recomputes_submission_deadline(): void
    {
        $this->actingAs($this->owner)->post('/compliance', $this->data())->assertRedirect();
        $record = ComplianceRecord::where('requirement', 'Monthly VAT return')->firstOrFail();

        $this->put('/compliance/'.$record->id, $this->data([
            'status' => 'In Preparation',
            'due_date' => today()->addDays(30)->toDateString(),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(today()->addDays(20)->toDateString(), $record->fresh()->submission_deadline->toDateString());
    }
    public function test_manual_override_is_preserved_when_filing_deadline_changes(): void
    {
        $this->actingAs($this->owner)->post('/compliance', $this->data([
            'submission_deadline' => today()->addDays(5)->toDateString(),
            'submission_deadline_override_reason' => 'Client must submit early.',
        ]))->assertRedirect();
        $record = ComplianceRecord::where('requirement', 'Monthly VAT return')->firstOrFail();

        $this->put('/compliance/'.$record->id, $this->data([
            'status' => 'Pending',
            'due_date' => today()->addDays(30)->toDateString(),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(today()->addDays(5)->toDateString(), $record->fresh()->submission_deadline->toDateString());
        $this->assertTrue((bool) $record->fresh()->submission_deadline_is_manual);
    }

    public function test_historical_record_with_null_submission_deadline_derives_provisionally(): void
    {
        $record = ComplianceRecord::create([
            'client_id' => $this->client->id,
            'agency' => 'SEC',
            'requirement' => 'Historical GIS',
            'due_date' => today()->addDays(20),
            'status' => 'Pending',
            'created_by' => $this->owner->id,
        ]);

        $this->assertNull($record->getRawOriginal('submission_deadline'));
        $this->assertSame(today()->addDays(10)->toDateString(), $record->submission_deadline->toDateString());
        $this->assertTrue($record->submission_deadline_is_provisional);
    }

    public function test_bookkeeper_cannot_change_deadlines(): void
    {
        $record = ComplianceRecord::create([
            'client_id' => $this->client->id,
            'agency' => 'BIR',
            'requirement' => 'Bookkeeper restricted',
            'due_date' => today()->addDays(20),
            'status' => 'Pending',
            'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->bookkeeper)
            ->put('/compliance/'.$record->id, [
                'status' => 'Pending',
                'notes' => 'ok',
                'due_date' => today()->addDays(25)->toDateString(),
            ])->assertForbidden();
    }

    public function test_bookkeeper_can_set_awaiting_client_documents_status(): void
    {
        $record = ComplianceRecord::create([
            'client_id' => $this->client->id,
            'agency' => 'BIR',
            'requirement' => 'Awaiting docs',
            'due_date' => today()->addDays(20),
            'status' => 'Pending',
            'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->bookkeeper)
            ->put('/compliance/'.$record->id, ['status' => 'Awaiting Client Documents', 'notes' => 'Waiting on receipts'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Awaiting Client Documents', $record->fresh()->status);
    }
}
