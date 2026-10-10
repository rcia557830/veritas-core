<?php

namespace Tests\Feature;

use App\Models\ComplianceRecord;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComplianceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ComplianceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->record = ComplianceRecord::firstOrFail();
        $this->record->update(['due_date' => today()->subDay(), 'status' => 'Pending']);
        $this->actingAs($this->owner);
    }

    private function activeItems(): array
    {
        $response = $this->get('/notifications')->assertOk();
        $this->assertSame(count(array_filter($response->json('items'), fn ($item) => ! $item['read'])), $response->json('unread'));

        return array_values(array_filter($response->json('items'), fn ($item) => $item['url'] === route('compliance.show', $this->record)));
    }

    private function updateRequirement(array $extra): void
    {
        $data = $this->record->fresh()->only(['client_id', 'agency', 'requirement', 'reporting_period', 'status', 'assigned_to']);
        $data['due_date'] = $this->record->fresh()->due_date->toDateString();
        $this->put('/compliance/'.$this->record->id, array_merge($data, $extra))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_filing_hides_deadline_alert_but_retains_history_and_activity(): void
    {
        $items = $this->activeItems();
        $this->assertCount(1, $items);
        $id = $items[0]['id'];
        $this->assertStringStartsWith('Overdue:', $items[0]['title']);
        $this->updateRequirement(['status' => 'Filed', 'filed_date' => today()->toDateString(), 'reference_number' => 'ACK-TEST']);
        $items = $this->activeItems();
        $this->assertNotContains($id, array_column($items, 'id'));
        $this->assertCount(1, $items);
        $this->assertStringStartsWith('Compliance assignment:', $items[0]['title']);
        $historical = $this->owner->notifications()->findOrFail($id);
        $this->assertNull($historical->read_at);
        $this->assertSame('Overdue', $historical->data['urgency']);
    }

    public function test_rescheduling_replaces_alert_even_when_both_deadlines_are_overdue(): void
    {
        $original = $this->activeItems()[0]['id'];
        $this->updateRequirement(['due_date' => today()->subDays(2)->toDateString()]);
        $items = $this->activeItems();
        $deadlines = array_values(array_filter($items, fn ($i) => str_starts_with($i['title'], 'Overdue:')));
        $this->assertCount(1, $deadlines);
        $this->assertNotSame($original, $deadlines[0]['id']);
        $current = $this->owner->notifications()->findOrFail($deadlines[0]['id']);
        $this->assertSame(today()->subDays(2)->toDateString(), $current->data['due_date']);
        $this->assertNotNull($this->owner->notifications()->find($original));

        $this->updateRequirement(['due_date' => today()->addDays(30)->toDateString()]);
        foreach ($this->activeItems() as $item) {
            $this->assertStringStartsWith('Compliance assignment:', $item['title']);
        }
        $this->assertNotNull($this->owner->notifications()->find($current->id));
    }

    public function test_only_current_urgency_is_displayed_across_manila_date_boundaries(): void
    {
        $this->record->update(['due_date' => '2026-10-11']);
        $soon = $this->activeItems();
        $this->assertCount(1, $soon);
        $this->assertStringStartsWith('Due Soon:', $soon[0]['title']);
        $this->travelTo(Carbon::parse('2026-10-11 00:00:00', 'Asia/Manila'));
        $todayItems = $this->activeItems();
        $this->assertCount(1, $todayItems);
        $this->assertStringStartsWith('Due Today:', $todayItems[0]['title']);
        $this->travelTo(Carbon::parse('2026-10-12 00:00:00', 'Asia/Manila'));
        $overdue = $this->activeItems();
        $this->assertCount(1, $overdue);
        $this->assertStringStartsWith('Overdue:', $overdue[0]['title']);
        foreach ([$soon[0]['id'], $todayItems[0]['id'], $overdue[0]['id']] as $id) {
            $this->assertNotNull($this->owner->notifications()->find($id));
        }
    }

    public function test_legacy_deadline_alerts_are_retained_and_replaced_without_duplicate_active_alerts(): void
    {
        Notify::send($this->owner, $this->record, 'compliance', 'Overdue: '.$this->record->requirement, 'due:'.$this->record->id.':'.$this->record->due_date->toDateString().':Overdue');
        $legacy = $this->owner->notifications()->firstOrFail();
        $this->assertArrayNotHasKey('due_date', $legacy->data);
        $items = $this->activeItems();
        $this->assertCount(1, $items);
        $this->assertNotSame($legacy->id, $items[0]['id']);
        $count = $this->owner->notifications()->count();
        $this->artisan('veritas:notify')->assertExitCode(0);
        $this->artisan('veritas:notify')->assertExitCode(0);
        $this->assertSame($items, $this->activeItems());
        $this->assertSame($count, $this->owner->notifications()->count());
        $this->assertNotNull($this->owner->notifications()->find($legacy->id));
    }

    public function test_resolved_and_rescheduled_legacy_alerts_stay_hidden_with_notifications_disabled(): void
    {
        Notify::send($this->owner, $this->record, 'compliance', 'Overdue: '.$this->record->requirement, 'legacy-deadline');
        $legacy = $this->owner->notifications()->firstOrFail();
        Setting::firstOrFail()->update(['notifications_enabled' => false]);
        foreach (['Filed', 'Completed'] as $status) {
            $this->record->update(['status' => $status]);
            $this->assertSame([], $this->activeItems());
        }
        $this->record->update(['status' => 'Pending', 'due_date' => today()->addDays(30)]);
        $this->assertSame([], $this->activeItems());
        $this->assertNotNull($this->owner->notifications()->find($legacy->id));
    }

    public function test_completed_records_generate_no_alerts_and_existing_alerts_remain_private(): void
    {
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->actingAs($bookkeeper);
        $id = $this->activeItems()[0]['id'];
        $this->record->client->update(['assigned_to' => $this->owner->id]);
        $this->assertSame([], $this->activeItems());
        $this->assertNotNull($bookkeeper->notifications()->find($id));
        $this->actingAs($this->owner)->post('/notifications/'.$id.'/read')->assertNotFound();

        $current = $this->activeItems()[0]['id'];
        $this->record->update(['status' => 'Completed']);
        $count = $this->owner->notifications()->count();
        $this->assertSame([], $this->activeItems());
        $this->assertSame($count, $this->owner->notifications()->count());
        $this->assertNotNull($this->owner->notifications()->find($current));
    }
}
