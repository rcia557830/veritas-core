<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoNoticeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoNoticeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_notices_preserve_workflows_access_and_edits_on_repeat_runs(): void
    {
        Storage::fake('local');
        config(['demo.enabled' => true]);
        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('notices', 15);
        $this->assertSame(10, Notice::where('status', 'Published')->count());
        $this->assertSame(3, Notice::where('status', 'Draft')->count());
        $this->assertSame(2, Notice::where('status', 'Archived')->count());
        $this->assertSame(5, Notice::whereNotNull('client_id')->count());
        foreach (Notice::all() as $notice) {
            $this->assertTrue($notice->creator->hasAnyRole(['owner', 'office-manager']));
            if ($notice->status === 'Draft') {
                $this->assertNull($notice->published_at);
            } else {
                $this->assertTrue($notice->published_at->gte($notice->created_at));
                $this->assertDatabaseHas('audit_logs', ['record_id' => $notice->id, 'action' => 'notice.published']);
            }
            if ($notice->client_id) {
                $this->assertNotNull($notice->client->assigned_to);
            }
        }
        foreach (['owner', 'office-manager'] as $role) {
            $this->actingAs(User::where('email', $role.'@demo.example.test')->sole());
            foreach (['Published' => 10, 'Draft' => 3, 'Archived' => ($role === 'owner' ? 2 : 3)] as $status => $count) {
                $this->get('/notices?status='.$status)->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === $count);
            }
            $this->get('/notices?q=Monthly%20Staff')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
            $this->get('/notices?q=nonexistent-title')->assertOk()->assertSee('No notices found');
            foreach (Notice::all() as $notice) {
                $response = $this->get('/notices/'.$notice->id)->assertOk()->assertSee($notice->title);
                if ($notice->client_id) {
                    $response->assertSee($notice->client->business_name);
                }
            }
            $this->post('/notices', ['title' => 'Workflow '.$role, 'body' => 'Internal review instructions.'])->assertRedirect();
            $notice = Notice::where('title', 'Workflow '.$role)->sole();
            $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
            $this->put('/notices/'.$notice->id, ['title' => $notice->title, 'body' => 'Revised instructions.'])->assertRedirect();
            $this->assertSame('Draft', $notice->fresh()->status);
            $this->assertNull($notice->fresh()->published_at);
            $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
            $this->post('/notices/'.$notice->id.'/archive')->assertRedirect();
            $this->assertSame('Archived', $notice->fresh()->status);
        }
        foreach (['bookkeeper', 'super-admin'] as $role) {
            $this->actingAs(User::where('email', $role.'@demo.example.test')->sole());
            foreach (['/notices', '/notices/create', '/notices/1', '/notices/1/edit'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/notices', [])->assertForbidden();
            $this->put('/notices/1', [])->assertForbidden();
            $this->post('/notices/1/publish')->assertForbidden();
            $this->post('/notices/1/archive')->assertForbidden();
        }
        Notice::first()->update(['title' => 'Staff revised title', 'body' => 'Staff revised body', 'status' => 'Archived']);
        Notice::findOrFail(2)->delete();
        $tables = ['notices', 'audit_logs', 'clients', 'ledger_entries', 'ledger_items', 'vouchers', 'compliance_records', 'knowledge_articles', 'documents', 'users'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $this->seed(DemoNoticeSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($before, $snapshot());
    }
}
