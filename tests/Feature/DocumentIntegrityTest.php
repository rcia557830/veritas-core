<?php

namespace Tests\Feature;

use App\Http\Requests\RecordRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Permission;
use App\Models\User;
use App\Services\RecordWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->document = Document::firstOrFail();
        Storage::disk('local')->put('documents/original.pdf', 'Original reviewed content');
        $this->document->update(['status' => 'Approved', 'file_path' => 'documents/original.pdf', 'original_file_name' => 'original.pdf', 'mime_type' => 'application/pdf']);
    }

    private function data(array $extra = []): array
    {
        return array_merge($this->document->fresh()->only(['client_id', 'title', 'document_type', 'status', 'notes']), [
            'received_date' => $this->document->received_date->toDateString(),
        ], $extra);
    }

    private function replacement(): UploadedFile
    {
        return UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf');
    }

    private function assertOriginalUnchanged(string $status): void
    {
        $this->assertSame($status, $this->document->fresh()->status);
        $this->assertSame('documents/original.pdf', $this->document->fresh()->file_path);
        $this->assertSame('Original reviewed content', Storage::disk('local')->get('documents/original.pdf'));
        $this->assertSame(['documents/original.pdf'], Storage::disk('local')->allFiles('documents'));
    }

    public function test_reviewed_and_approved_uploads_are_blocked_even_for_managers_and_owners(): void
    {
        foreach (['Reviewed', 'Approved'] as $status) {
            $this->document->update(['status' => $status]);
            foreach ([$this->manager, $this->owner] as $user) {
                $this->actingAs($user)->put('/documents/'.$this->document->id, $this->data(['file' => $this->replacement()]))->assertSessionHasErrors('file');
                $this->assertOriginalUnchanged($status);
                $this->get('/documents/'.$this->document->id.'/edit')->assertOk()->assertSee('The attachment is locked')->assertDontSee('name="file"', false);
            }
        }
    }

    public function test_reopening_cannot_be_combined_with_replacement_or_bypass_validation_permission(): void
    {
        $this->actingAs($this->manager);
        foreach (['Submitted', 'Under Review', 'Needs Clarification', 'Rejected'] as $status) {
            $this->put('/documents/'.$this->document->id, $this->data(['status' => $status, 'file' => $this->replacement()]))->assertSessionHasErrors('status');
            $this->assertOriginalUnchanged('Approved');
        }
        $this->manager->role->permissions()->detach(Permission::where('name', 'document.validate')->value('id'));
        $this->post('/documents/'.$this->document->id.'/validate', ['status' => 'Needs Clarification'])->assertForbidden();
        $this->put('/documents/'.$this->document->id, $this->data(['status' => 'Submitted']))->assertForbidden();
        $this->assertOriginalUnchanged('Approved');
    }

    public function test_bookkeeper_cannot_reopen_or_replace_a_finalized_document(): void
    {
        $this->actingAs($this->bookkeeper)->post('/documents/'.$this->document->id.'/validate', ['status' => 'Needs Clarification'])->assertForbidden();
        $this->put('/documents/'.$this->document->id, $this->data(['file' => $this->replacement()]))->assertForbidden();
        $this->assertOriginalUnchanged('Approved');
    }

    public function test_authorized_reopening_replacement_and_reverification_preserve_original_and_audit(): void
    {
        $this->actingAs($this->manager)->post('/documents/'.$this->document->id.'/validate', ['status' => 'Needs Clarification', 'notes' => 'Replace illegible copy'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.reopened', 'record_id' => $this->document->id, 'user_id' => $this->manager->id]);
        $this->actingAs($this->bookkeeper)->put('/documents/'.$this->document->id, $this->data(['file' => $this->replacement()]))->assertRedirect()->assertSessionHasNoErrors();
        $replacementPath = $this->document->fresh()->file_path;
        $this->assertNotSame('documents/original.pdf', $replacementPath);
        $this->assertSame('Needs Clarification', $this->document->fresh()->status);
        $this->assertSame('Original reviewed content', Storage::disk('local')->get('documents/original.pdf'));
        Storage::disk('local')->assertExists($replacementPath);
        $audit = AuditLog::where('action', 'document.attachment-replaced')->where('record_id', $this->document->id)->firstOrFail();
        $this->assertStringContainsString('documents/original.pdf', $audit->description);
        $this->assertStringContainsString($replacementPath, $audit->description);
        $this->post('/documents/'.$this->document->id.'/validate', ['status' => 'Approved'])->assertForbidden();
        $this->actingAs($this->manager)->post('/documents/'.$this->document->id.'/validate', ['status' => 'Approved', 'notes' => 'Replacement verified'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Approved', $this->document->fresh()->status);
        $this->assertSame($replacementPath, $this->document->fresh()->file_path);
        $this->get('/documents/'.$this->document->id.'/download')->assertDownload('replacement.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.validated', 'record_id' => $this->document->id, 'user_id' => $this->manager->id]);
    }

    public function test_replacement_cannot_be_verified_in_the_upload_request(): void
    {
        $this->document->update(['status' => 'Under Review']);
        $this->actingAs($this->manager);
        foreach (['Reviewed', 'Approved'] as $status) {
            $this->put('/documents/'.$this->document->id, $this->data(['status' => $status, 'file' => $this->replacement()]))->assertSessionHasErrors('file');
            $this->assertOriginalUnchanged('Under Review');
        }
    }

    public function test_writer_rechecks_locked_status_when_route_bound_record_is_stale(): void
    {
        $this->document->update(['status' => 'Under Review']);
        $data = $this->data();
        Document::whereKey($this->document->id)->update(['status' => 'Approved']);
        $data['status'] = 'Approved';
        $request = Mockery::mock(RecordRequest::class);
        $request->shouldReceive('validated')->andReturn($data);
        $request->shouldReceive('user')->andReturn($this->manager);
        $request->shouldReceive('hasFile')->with('file')->andReturn(true);
        $request->shouldNotReceive('file');
        $this->actingAs($this->manager);
        try {
            app(RecordWriter::class)->save('documents', $request, $this->document);
            $this->fail('A finalized attachment must be protected after the row is locked.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file', $error->errors());
        }
        $this->assertOriginalUnchanged('Approved');
    }
}
