<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\JournalFixtures;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->staff = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    private function clientData(array $extra = []): array
    {
        return array_merge(['business_name' => 'Test Trading', 'business_type' => 'Corporation', 'email' => 'owner@example.com', 'registration_status' => 'On file', 'business_license_status' => 'On file', 'status' => 'Active'], $extra);
    }

    private function ledgerData(string $credit = '100.00'): array
    {
        return $this->journalPayload(Client::first(), $credit);
    }

    private function invoiceData(): array
    {
        return ['client_id' => Client::first()->id, 'invoice_date' => today()->toDateString(), 'due_date' => today()->addDays(10)->toDateString(), 'tax' => '24.06', 'items' => [['description' => 'Accounting review', 'quantity' => '2.00', 'unit_price' => '100.25']]];
    }

    public function test_guests_are_redirected_and_login_logout_work(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->admin->update(['status' => 'Inactive']);
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password123'])->assertSessionHasErrors('email');
    }

    public function test_all_pages_forms_and_details_render(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->admin);
        foreach (['/dashboard', '/clients', '/documents', '/ledger', '/compliance', '/billing', '/knowledge', '/workspace', '/reports', '/admin/users', '/admin/audit-logs', '/profile', '/admin/users/create', '/admin/users/'.$this->staff->id.'/edit'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['clients' => Client::class, 'documents' => Document::class, 'ledger' => LedgerEntry::class, 'compliance' => ComplianceRecord::class, 'billing' => Invoice::class, 'knowledge' => KnowledgeArticle::class] as $module => $model) {
            $record = $model::first();
            $this->get('/'.$module.'/create')->assertOk();
            $this->get('/'.$module.'/'.$record->id)->assertOk();
            $this->get('/reports?module='.$module)->assertOk();
            if (! in_array($module, ['ledger', 'billing'])) {
                $this->get('/'.$module.'/'.$record->id.'/edit')->assertOk();
            }
            $this->get('/'.$module.'/create', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('name="_token"', false);
        }
        $this->withExceptionHandling();
        $this->get('/missing-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_staff_cannot_bypass_admin_or_assigned_client_boundaries(): void
    {
        $private = Client::create($this->clientData(['client_code' => 'PRIVATE', 'business_name' => 'PrivateClientSecret', 'created_by' => $this->admin->id, 'assigned_to' => $this->admin->id]));
        $document = Document::create(['client_id' => $private->id, 'document_number' => 'PRIVATE-DOC', 'title' => 'PrivateDocumentSecret', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today(), 'uploaded_by' => $this->admin->id]);
        $this->actingAs($this->staff);
        foreach (['/workspace', '/admin/users', '/admin/users/create', '/admin/audit-logs', '/clients/'.$private->id, '/documents/'.$document->id, '/documents/'.$document->id.'/download'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/clients')->assertDontSee('PrivateClientSecret');
        $this->get('/search?q=Secret')->assertOk()->assertJsonCount(0);
        $this->get('/reports?module=clients')->assertDontSee('PrivateClientSecret');
        $this->put('/clients/'.$private->id, $this->clientData())->assertForbidden();
        $this->post('/documents', ['client_id' => $private->id, 'title' => 'Blocked', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today()->toDateString()])->assertNotFound();
        $this->post('/clients/'.$private->id.'/archive')->assertForbidden();
        $this->post('/admin/users', [])->assertForbidden();
    }

    public function test_staff_client_crud_preserves_assignment_and_admin_archives(): void
    {
        $this->actingAs($this->staff)->post('/clients', $this->clientData(['assigned_to' => $this->admin->id, 'status' => 'Archived']))->assertForbidden();
        $this->post('/clients', $this->clientData())->assertRedirect();
        $client = Client::where('business_name', 'Test Trading')->firstOrFail();
        $this->assertEquals($this->staff->id, $client->assigned_to);
        $this->assertSame('Active', $client->status);
        $this->put('/clients/'.$client->id, $this->clientData(['business_name' => 'Updated Trading']))->assertRedirect();
        $this->assertSame('Updated Trading', $client->fresh()->business_name);
        $this->delete('/clients/'.$client->id)->assertForbidden();
        $this->actingAs($this->admin)->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->assertSame('Archived', $client->fresh()->status);
        $this->post('/clients/'.$client->id.'/archive')->assertRedirect();
        $this->assertSame('Active', $client->fresh()->status);
        $this->post('/clients', $this->clientData(['email' => 'not-an-email', 'tin' => 'letters']))->assertSessionHasErrors(['email', 'tin']);
    }

    public function test_documents_upload_download_validation_and_soft_delete(): void
    {
        Storage::fake('local');
        $this->actingAs($this->staff);
        $data = ['client_id' => Client::first()->id, 'title' => 'Uploaded receipt', 'document_type' => 'Receipt', 'status' => 'Submitted', 'received_date' => today()->toDateString()];
        $this->post('/documents', $data + ['file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')])->assertRedirect();
        $document = Document::where('title', 'Uploaded receipt')->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        $this->get('/documents/'.$document->id.'/download')->assertDownload('receipt.pdf');
        $this->actingAs($this->admin)->put('/documents/'.$document->id, array_merge($data, ['status' => 'Needs Clarification', 'notes' => 'Please provide a clearer copy.']))->assertRedirect();
        $this->assertSame('Needs Clarification', $document->fresh()->status);
        $this->actingAs($this->staff)->post('/documents', $data + ['file' => UploadedFile::fake()->create('bad.exe', 1, 'application/octet-stream')])->assertSessionHasErrors('file');
        $this->delete('/documents/'.$document->id)->assertForbidden();
        $this->actingAs($this->admin)->delete('/documents/'.$document->id)->assertRedirect();
        $this->assertSoftDeleted($document);
    }

    public function test_ledger_balance_and_independent_review(): void
    {
        $this->actingAs($this->staff)->post('/ledger', $this->ledgerData('99.99'))->assertRedirect();
        $entry = LedgerEntry::where('reference_number', 'TEST-LEDGER')->firstOrFail();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertSessionHasErrors('items');
        $this->put('/ledger/'.$entry->id, $this->ledgerData())->assertRedirect();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->assertSame('For Review', $entry->fresh()->status);
        $this->put('/ledger/'.$entry->id, $this->ledgerData())->assertForbidden();
        $this->delete('/ledger/'.$entry->id)->assertForbidden();
        $this->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertForbidden();
        $this->actingAs($this->admin)->post('/ledger/'.$entry->id.'/transition', ['action' => 'return', 'notes' => 'Check source'])->assertRedirect();
        $this->actingAs($this->staff)->post('/ledger/'.$entry->id.'/transition', ['action' => 'submit'])->assertRedirect();
        $this->actingAs($this->admin)->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])->assertRedirect();
        $this->assertSame('Reviewed', $entry->fresh()->status);
        $this->assertEquals($this->admin->id, $entry->fresh()->reviewed_by);
    }

    public function test_invoice_calculation_partial_payments_and_pdf(): void
    {
        $this->actingAs($this->staff)->post('/billing', $this->invoiceData())->assertRedirect();
        $invoice = Invoice::latest('id')->first();
        $this->assertSame('224.56', $invoice->total_amount);
        $this->get('/billing/'.$invoice->id.'/edit')->assertOk();
        $this->post('/billing/'.$invoice->id.'/transition', ['action' => 'issue'])->assertRedirect();
        $this->put('/billing/'.$invoice->id, $this->invoiceData())->assertForbidden();
        $pay = ['payment_date' => today()->toDateString(), 'amount' => '100.00', 'payment_method' => 'Bank Transfer'];
        $this->post('/billing/'.$invoice->id.'/payments', $pay)->assertRedirect();
        $this->assertSame('124.56', $invoice->fresh()->balance);
        $this->assertSame('Partially Paid', $invoice->fresh()->display_status);
        $this->post('/billing/'.$invoice->id.'/payments', array_merge($pay, ['amount' => '125.00']))->assertSessionHasErrors('amount');
        $this->post('/billing/'.$invoice->id.'/payments', array_merge($pay, ['amount' => '124.56']))->assertRedirect();
        $this->assertSame('Paid', $invoice->fresh()->display_status);
        $this->actingAs($this->admin)->post('/billing/'.$invoice->id.'/transition', ['action' => 'cancel'])->assertSessionHasErrors('invoice');
        $this->get('/billing/'.$invoice->id.'/print')->assertOk()->assertSee('224.56');
        $pdf = $this->get('/billing/'.$invoice->id.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/billing?status=Paid')->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_compliance_filing_requires_date_and_reference(): void
    {
        $record = ComplianceRecord::first();
        $data = $record->only(['client_id', 'agency', 'requirement', 'reporting_period', 'status', 'assigned_to']);
        $data['due_date'] = today()->subDay()->toDateString();
        $this->actingAs($this->admin)->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Filed']))->assertSessionHasErrors(['filed_date', 'reference_number']);
        $this->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Filed', 'filed_date' => today()->toDateString(), 'reference_number' => 'ACK-2026']))->assertRedirect();
        $this->assertSame('Filed', $record->fresh()->display_status);
        $this->put('/compliance/'.$record->id, array_merge($data, ['status' => 'Pending']))->assertRedirect();
        $this->assertSame('Pending', $record->fresh()->display_status);
        $this->assertSame('Filing Overdue', $record->fresh()->urgency);
    }

    public function test_knowledge_authorization_and_escaped_content(): void
    {
        $data = ['title' => 'Knowledge test', 'category' => 'Accounting', 'status' => 'Published', 'tags' => 'audit,review', 'content' => '<script>alert(1)</script>'];
        $this->actingAs($this->staff)->post('/knowledge', $data)->assertForbidden();
        $this->actingAs($this->admin)->post('/knowledge', $data)->assertRedirect();
        $article = KnowledgeArticle::where('title', 'Knowledge test')->firstOrFail();
        $this->actingAs($this->staff)->get('/knowledge/'.$article->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $article->update(['status' => 'Draft']);
        $this->get('/knowledge/'.$article->id)->assertForbidden();
    }

    public function test_notifications_are_private_and_idempotent(): void
    {
        $this->actingAs($this->staff);
        $first = $this->get('/notifications')->assertOk();
        $count = $this->staff->notifications()->count();
        $this->assertGreaterThan(0, $count);
        $this->get('/notifications')->assertOk();
        $this->assertSame($count, $this->staff->notifications()->count());
        $id = $first->json('items.0.id');
        $this->actingAs($this->admin)->post('/notifications/'.$id.'/read')->assertNotFound();
        $this->actingAs($this->staff)->post('/notifications/'.$id.'/read')->assertOk();
        $this->post('/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->staff->unreadNotifications()->count());
    }

    public function test_reports_filters_search_and_csv(): void
    {
        $this->actingAs($this->admin);
        $this->get('/clients?q=Davao&sort=business_name&direction=asc')->assertOk()->assertSee('Davao Prime Trading')->assertDontSee('Lanang Caf? Group');
        $this->get('/search?q=Davao')->assertOk()->assertJsonFragment(['title' => 'Davao Prime Trading']);
        $csv = $this->get('/reports/csv?module=clients')->assertOk();
        $this->assertStringContainsString('Davao Prime Trading', $csv->streamedContent());
        foreach (['Open', 'Overdue', 'Partially Paid', 'Paid', 'Draft', 'Cancelled'] as $status) {
            $this->get('/reports?module=billing&status='.urlencode($status))->assertOk();
        }
    }

    public function test_password_reset_uses_tokens_and_profile_requires_password(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $this->staff->email])->assertRedirect();
        Notification::assertSentTo($this->staff, ResetPassword::class);
        $token = Password::createToken($this->staff);
        $this->post('/reset-password', ['email' => $this->staff->email, 'token' => $token, 'password' => 'newPassword456', 'password_confirmation' => 'newPassword456'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('newPassword456', $this->staff->fresh()->password));
        $this->actingAs($this->staff->fresh())->patch('/profile', ['name' => 'New Name', 'email' => $this->staff->email, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->patch('/profile', ['name' => 'New Name', 'email' => $this->staff->email, 'current_password' => 'newPassword456'])->assertRedirect();
        $this->assertSame('New Name', $this->staff->fresh()->name);
    }

    public function test_account_management_and_workspace_settings(): void
    {
        $this->actingAs($this->admin);
        $data = ['name' => 'Employee Two', 'email' => 'two@example.com', 'role_id' => $this->staff->role_id, 'status' => 'Active', 'password' => 'safePassword123', 'password_confirmation' => 'safePassword123'];
        $this->post('/admin/users', $data)->assertRedirect();
        $user = User::where('email', 'two@example.com')->firstOrFail();
        $this->put('/admin/users/'.$user->id, array_merge($data, ['status' => 'Inactive']))->assertRedirect();
        $this->assertSame('Inactive', $user->fresh()->status);
        $this->put('/admin/users/'.$this->admin->id, array_merge($data, ['email' => $this->admin->email, 'role_id' => $this->admin->role_id, 'status' => 'Inactive']))->assertForbidden();
        $this->put('/workspace', ['firm_name' => 'RBCIA Accounting Firm', 'firm_email' => 'firm@example.com', 'currency' => 'PHP', 'page_size' => 25, 'notifications_enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('settings', ['page_size' => 25]);
        $this->assertGreaterThan(0, AuditLog::count());
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }
}
