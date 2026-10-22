<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\JournalEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RemoteDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_upload_download_and_integrity_use_the_configured_disk(): void
    {
        $this->seed();
        config(['filesystems.documents' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $existing = Document::firstOrFail();
        $this->actingAs($owner)->post('/documents', [
            'client_id' => $existing->client_id,
            'title' => 'Remote PDF',
            'document_type' => 'Receipt',
            'status' => 'Submitted',
            'received_date' => today()->toDateString(),
            'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $document = Document::where('title', 'Remote PDF')->firstOrFail();
        Storage::disk('s3')->assertExists($document->file_path);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSame($document->file_sha256, JournalEvidence::digest($document->file_path));
        $response = $this->get('/documents/'.$document->id.'/download')
            ->assertOk()->assertDownload('private.pdf');
        $this->assertSame(Storage::disk('s3')->get($document->file_path), $response->streamedContent());
        $this->artisan('veritas:verify-attachments', ['--disk' => 's3'])->assertSuccessful();

        // Legacy rows without a digest must compare remote streams, not local paths.
        $document->update(['file_sha256' => null]);
        $this->actingAs($owner)->post('/documents', [
            'client_id' => $document->client_id,
            'title' => 'Duplicate remote PDF',
            'document_type' => 'Receipt',
            'status' => 'Submitted',
            'received_date' => today()->toDateString(),
            'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $document->update(['file_sha256' => JournalEvidence::digest($document->file_path)]);
        Storage::disk('s3')->put($document->file_path, 'tampered');
        $this->artisan('veritas:verify-attachments', ['--disk' => 's3'])->assertFailed();

        auth()->logout();
        $this->get('/documents/'.$document->id.'/download')->assertRedirect('/login');
    }

    public function test_logo_is_served_from_private_storage_only_to_authenticated_staff(): void
    {
        $this->seed();
        config(['filesystems.logos' => 's3']);
        Storage::fake('s3')->put('logos/firm.png', 'logo bytes');
        Setting::firstOrFail()->update(['logo_path' => 'logos/firm.png']);
        $this->get('/workspace/logo')->assertRedirect('/login');
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $response = $this->actingAs($owner)->get('/workspace/logo')->assertOk();
        $this->assertSame('logo bytes', $response->streamedContent());
        $owner->update(['role_id' => Role::where('slug', 'super-admin')->value('id')]);
        $owner->unsetRelation('role');
        $response = $this->actingAs($owner)->get('/workspace/logo')->assertOk();
        $this->assertSame('logo bytes', $response->streamedContent());
    }

    public function test_unauthorized_remote_download_is_denied_before_storage_access(): void
    {
        $this->seed();
        config(['filesystems.documents' => 's3']);
        $document = Document::firstOrFail();
        $document->update(['file_path' => 'documents/private.pdf']);
        $user = User::factory()->create(['role_id' => User::where('email', 'bookkeeper@veritascore.local')->firstOrFail()->role_id, 'status' => 'Active']);
        Storage::shouldReceive('disk')->never();
        $this->actingAs($user)->get('/documents/'.$document->id.'/download')->assertForbidden();
    }
}
