<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $bookkeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    private function secondBookkeeper(): User
    {
        $role = Role::where('slug', 'bookkeeper')->firstOrFail();

        return User::create([
            'name' => 'Second Bookkeeper',
            'email' => 'bookkeeper2@veritascore.local',
            'password' => 'password123',
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    private function documentData(Client $client, array $extra = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'title' => 'Synthetic security document',
            'document_type' => 'Receipt',
            'status' => 'Submitted',
            'received_date' => today()->toDateString(),
        ], $extra);
    }

    public function test_bookkeeper_cannot_download_another_bookkeepers_client_document(): void
    {
        $document = Document::firstOrFail();
        Storage::disk('local')->put('documents/private.pdf', 'CONFIDENTIAL');
        $document->update(['file_path' => 'documents/private.pdf', 'original_file_name' => 'private.pdf']);

        $this->actingAs($this->secondBookkeeper())
            ->get('/documents/'.$document->id.'/download')
            ->assertForbidden();
    }

    public function test_download_missing_attachment_returns_not_found(): void
    {
        $document = Document::firstOrFail();
        $document->update(['file_path' => null]);

        $this->actingAs($this->bookkeeper)
            ->get('/documents/'.$document->id.'/download')
            ->assertNotFound();
    }

    public function test_executable_uploads_are_rejected(): void
    {
        $client = Client::firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->post('/documents', $this->documentData($client, [
                'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            ]))
            ->assertSessionHasErrors('file');
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        $client = Client::firstOrFail();

        // 21 MiB exceeds the 20 MiB (20480 KB) document limit.
        $this->actingAs($this->bookkeeper)
            ->post('/documents', $this->documentData($client, [
                'file' => UploadedFile::fake()->create('oversized.pdf', 21 * 1024, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('file');
    }

    public function test_file_path_cannot_be_forged_through_the_upload_request(): void
    {
        $client = Client::firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->post('/documents', $this->documentData($client, [
                'title' => 'Forged path document',
                'file_path' => '../../.env',
            ]))
            ->assertRedirect();

        $document = Document::where('title', 'Forged path document')->firstOrFail();
        $this->assertNull($document->file_path);
    }

    public function test_stored_path_is_generated_and_never_the_client_filename(): void
    {
        $client = Client::firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->post('/documents', $this->documentData($client, [
                'title' => 'Traversal filename document',
                'file' => UploadedFile::fake()->create('../../evil.pdf', 10, 'application/pdf'),
            ]))
            ->assertRedirect();

        $document = Document::where('title', 'Traversal filename document')->firstOrFail();
        $this->assertNotNull($document->file_path);
        $this->assertStringStartsWith('documents/', $document->file_path);
        $this->assertStringNotContainsString('..', $document->file_path);
    }
}
