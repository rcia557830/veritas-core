<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
    }

    private function documentData(Document $document, array $extra = []): array
    {
        return array_merge($document->only(['client_id', 'title', 'document_type', 'status', 'notes']), ['received_date' => $document->received_date->toDateString()], $extra);
    }

    private function accountData(User $user, array $extra = []): array
    {
        return array_merge($user->only(['name', 'email', 'role_id', 'status']), $extra);
    }

    public function test_database_permissions_and_helpers_are_not_role_name_only_checks(): void
    {
        $this->assertDatabaseCount('roles', 3);
        $this->assertTrue($this->owner->hasRole('owner'));
        $this->assertTrue($this->manager->hasAnyRole(['owner', 'office-manager']));
        $this->assertTrue($this->bookkeeper->hasAnyPermission(['user.create', 'billing.payment']));
        $this->assertFalse($this->bookkeeper->hasPermission('document.approve'));
        $id = Permission::where('name', 'billing.payment')->value('id');
        $this->bookkeeper->role->permissions()->detach($id);
        $this->assertFalse($this->bookkeeper->hasPermission('billing.payment'));
        $invoice = Invoice::where('status', 'Open')->first();
        $this->actingAs($this->bookkeeper)->post('/billing/'.$invoice->id.'/payments', [])->assertForbidden();
    }

    public function test_only_owner_can_reach_admin_routes_or_change_accounts(): void
    {
        foreach ([$this->bookkeeper, $this->manager] as $user) {
            $this->actingAs($user);
            foreach (['/admin/users', '/admin/users/create', '/admin/users/'.$this->owner->id.'/edit', '/workspace', '/admin/audit-logs'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/users', [])->assertForbidden();
            $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['role_id' => $user->role_id]))->assertForbidden();
            $this->post('/admin/users/'.$this->owner->id.'/reset-password')->assertForbidden();
            $this->put('/workspace', [])->assertForbidden();
        }
        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
    }

    public function test_owner_creates_all_roles_and_cannot_demote_or_deactivate_self(): void
    {
        $this->actingAs($this->owner);
        foreach (Role::all() as $role) {
            $this->post('/admin/users', ['name' => 'New '.$role->name, 'email' => $role->slug.'@example.com', 'role_id' => $role->id, 'status' => 'Active', 'password' => 'password12345', 'password_confirmation' => 'password12345'])->assertRedirect('/admin/users');
        }
        $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['status' => 'Inactive']))->assertForbidden();
        $this->put('/admin/users/'.$this->owner->id, $this->accountData($this->owner, ['role_id' => $this->bookkeeper->role_id]))->assertForbidden();
        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.changed']);
    }

    public function test_owner_role_and_account_changes_are_audited(): void
    {
        $this->actingAs($this->owner)->put('/admin/users/'.$this->bookkeeper->id, $this->accountData($this->bookkeeper, ['role_id' => $this->manager->role_id, 'status' => 'Inactive']))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deactivated', 'record_id' => $this->bookkeeper->id]);
        $this->assertFalse($this->bookkeeper->fresh()->hasPermission('client.view'));
        $this->actingAs($this->bookkeeper->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_bookkeeper_cannot_validate_documents_through_urls_or_forged_forms(): void
    {
        $document = Document::where('status', 'Submitted')->first();
        $this->actingAs($this->bookkeeper);
        $this->get('/documents/'.$document->id.'/validate')->assertForbidden();
        foreach (['Approved', 'Rejected', 'Reviewed', 'Under Review', 'Needs Clarification'] as $status) {
            $this->post('/documents/'.$document->id.'/validate', ['status' => $status])->assertForbidden();
            $this->put('/documents/'.$document->id, $this->documentData($document, ['status' => $status]))->assertForbidden();
            $this->post('/documents', $this->documentData($document, ['status' => $status]))->assertForbidden();
        }
        $this->put('/documents/'.$document->id, $this->documentData($document, ['title' => 'Updated metadata']))->assertRedirect();
        $this->assertSame('Submitted', $document->fresh()->status);
        $this->get('/documents/'.$document->id)->assertDontSee('Validate document');
        $this->get('/documents/'.$document->id.'/edit')->assertDontSee('<option value="Approved"', false);
    }

    public function test_manager_validates_and_bookkeeper_cannot_replace_approved_documents(): void
    {
        $document = Document::where('status', 'Submitted')->first();
        $this->actingAs($this->manager)->get('/documents/'.$document->id)->assertOk()->assertSee('Validate document');
        $this->post('/documents/'.$document->id.'/validate', ['status' => 'Approved', 'notes' => 'Checked source'])->assertRedirect();
        $this->assertSame('Approved', $document->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.validated', 'record_id' => $document->id]);
        $this->actingAs($this->bookkeeper)->put('/documents/'.$document->id, $this->documentData($document, ['status' => 'Submitted']))->assertForbidden();
    }

    public function test_manager_reviews_but_cannot_create_or_edit_original_ledger_entries(): void
    {
        $entry = LedgerEntry::first();
        $this->actingAs($this->manager);
        $this->get('/ledger/create')->assertForbidden();
        $this->post('/ledger', [])->assertForbidden();
        $this->get('/ledger')->assertOk()->assertDontSee('New transaction');
        $this->get('/ledger/'.$entry->id)->assertOk()->assertSee('Approve review');
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'return', 'notes' => 'Correct source reference'])->assertRedirect();
        $this->get('/ledger/'.$entry->id.'/edit')->assertForbidden();
        $this->put('/ledger/'.$entry->id, [])->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertForbidden();
        $this->actingAs($this->bookkeeper)->get('/ledger/'.$entry->id.'/edit')->assertOk();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
        $this->assertSame('Reviewed', $entry->fresh()->status);
    }

    public function test_even_owner_cannot_approve_their_own_ledger_entry(): void
    {
        $entry = LedgerEntry::first();
        $entry->update(['created_by' => $this->owner->id]);
        $this->actingAs($this->owner)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->manager)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
    }

    public function test_bookkeeper_can_only_update_compliance_preparation_and_notes(): void
    {
        $record = ComplianceRecord::first();
        $this->actingAs($this->bookkeeper);
        $this->get('/compliance/create')->assertForbidden();
        $this->post('/compliance', [])->assertForbidden();
        $this->put('/compliance/'.$record->id, ['status' => 'In Preparation', 'notes' => 'Source documents requested'])->assertRedirect();
        foreach ([['status' => 'Filed'], ['due_date' => today()->addYear()->toDateString()], ['assigned_to' => $this->owner->id], ['requirement' => 'Changed obligation'], ['reference_number' => 'forged']] as $extra) {
            $this->put('/compliance/'.$record->id, array_merge(['status' => 'In Preparation', 'notes' => 'Note'], $extra))->assertForbidden();
        }
        $this->assertSame('In Preparation', $record->fresh()->status);
        $this->get('/compliance/'.$record->id.'/edit')->assertOk()->assertDontSee('name="due_date"', false)->assertDontSee('name="assigned_to"', false);
        $this->actingAs($this->manager)->put('/compliance/'.$record->id, array_merge($record->only(['client_id', 'agency', 'requirement', 'reporting_period']), ['status' => 'Filed', 'due_date' => $record->due_date->toDateString(), 'filed_date' => today()->toDateString(), 'reference_number' => 'ACK-100', 'assigned_to' => $this->bookkeeper->id]))->assertRedirect();
        $this->assertSame('Filed', $record->fresh()->status);
    }

    public function test_bookkeeper_articles_are_own_drafts_and_manager_can_publish(): void
    {
        $data = ['title' => 'Bookkeeper draft', 'category' => 'Accounting', 'status' => 'Draft', 'content' => '<script>bad()</script>', 'tags' => 'process'];
        $this->actingAs($this->bookkeeper)->post('/knowledge', $data)->assertRedirect();
        $article = KnowledgeArticle::where('title', $data['title'])->firstOrFail();
        $this->put('/knowledge/'.$article->id, array_merge($data, ['status' => 'Published']))->assertForbidden();
        $this->get('/knowledge/'.$article->id.'/edit')->assertOk();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password123', 'status' => 'Active', 'role_id' => $this->bookkeeper->role_id]);
        $this->actingAs($other)->get('/knowledge/'.$article->id)->assertForbidden();
        $this->get('/search?q=Bookkeeper%20draft')->assertJsonCount(0);
        $this->actingAs($this->manager)->put('/knowledge/'.$article->id, array_merge($data, ['status' => 'Published']))->assertRedirect();
        $this->actingAs($this->bookkeeper)->get('/knowledge/'.$article->id)->assertOk()->assertSee('&lt;script&gt;', false);
        $this->get('/knowledge/'.$article->id.'/edit')->assertForbidden();
    }

    public function test_notice_workflow_is_managerial_internal_and_audited(): void
    {
        $this->actingAs($this->manager)->get('/notices')->assertOk();
        $this->get('/notices/create')->assertOk();
        $this->post('/notices', ['title' => 'Missing records', 'body' => 'Please supply the receipts.', 'client_id' => Client::first()->id])->assertRedirect();
        $notice = Notice::firstOrFail();
        $this->get('/notices/'.$notice->id)->assertOk();
        $this->get('/notices/'.$notice->id.'/edit')->assertOk();
        $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
        $this->assertSame('Published', $notice->fresh()->status);
        $this->put('/notices/'.$notice->id, ['title' => 'Updated notice', 'body' => 'Updated content', 'client_id' => $notice->client_id])->assertRedirect();
        $this->assertSame('Draft', $notice->fresh()->status);
        $this->post('/notices/'.$notice->id.'/publish')->assertRedirect();
        $this->actingAs($this->bookkeeper);
        $this->get('/dashboard')->assertOk()->assertDontSee('Updated notice');
        $this->get('/clients/'.$notice->client_id)->assertOk()->assertDontSee('Updated notice');
        foreach (['/notices', '/notices/create', '/notices/'.$notice->id, '/notices/'.$notice->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/notices', [])->assertForbidden();
        $this->put('/notices/'.$notice->id, [])->assertForbidden();
        $this->post('/notices/'.$notice->id.'/publish')->assertForbidden();
        $this->post('/notices/'.$notice->id.'/archive')->assertForbidden();
        $this->actingAs($this->owner)->post('/notices/'.$notice->id.'/archive')->assertRedirect();
        $this->assertSame('Archived', $notice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notice.published']);
    }

    public function test_bookkeeper_cannot_archive_reassign_or_access_unassigned_clients(): void
    {
        $client = Client::first();
        $this->actingAs($this->bookkeeper)->post('/clients/'.$client->id.'/archive')->assertForbidden();
        $this->put('/clients/'.$client->id, ['assigned_to' => $this->owner->id])->assertForbidden();
        $this->actingAs($this->manager)->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $client->update(['assigned_to' => $this->manager->id]);
        $this->actingAs($this->bookkeeper)->get('/clients/'.$client->id)->assertForbidden();
        $this->get('/reports?module=clients')->assertDontSee($client->business_name);
        $this->actingAs($this->manager)->get('/clients/'.$client->id)->assertOk();
    }

    public function test_role_dashboards_and_permissions_render_for_all_three_roles(): void
    {
        foreach ([$this->owner, $this->bookkeeper, $this->manager] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee($user->role->name.' overview');
            foreach (['/clients', '/documents', '/ledger', '/compliance', '/billing', '/knowledge', '/reports', '/profile'] as $url) {
                $this->get($url)->assertOk();
            }
            $this->get('/reports/csv?module=ledger')->assertOk();
            if (! $user->hasRole('owner')) {
                $this->get('/dashboard')->assertDontSee('User Management')->assertDontSee('Audit Logs');
            }
            if ($user->hasRole('bookkeeper')) {
                $this->get('/dashboard')->assertDontSee('href="'.route('notices.index').'"', false);
            }
        }
    }

    public function test_revoked_module_visibility_also_removes_profile_and_search_data(): void
    {
        $doc = Document::first();
        $doc->update(['title' => 'RestrictedDocumentTitle']);
        $this->bookkeeper->role->permissions()->detach(Permission::where('name', 'document.view')->value('id'));
        $this->actingAs($this->bookkeeper);
        $this->get('/documents')->assertForbidden();
        $this->get('/documents/'.$doc->id)->assertForbidden();
        $this->get('/reports?module=documents')->assertForbidden();
        $this->get('/reports/csv?module=documents')->assertForbidden();
        $this->get('/search?q=RestrictedDocumentTitle')->assertJsonCount(0);
        $this->get('/clients/'.$doc->client_id)->assertOk()->assertDontSee('RestrictedDocumentTitle');
    }
}
