<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use App\Services\DocumentCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->client = Client::create([
            'client_code' => 'CL-COMP', 'business_name' => 'Completeness Test Co', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id,
        ]);
    }

    private function requirement(array $extra = []): DocumentRequirement
    {
        return DocumentRequirement::create(array_merge([
            'client_id' => $this->client->id, 'name' => 'Test requirement', 'type' => 'Receipt',
            'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ], $extra));
    }

    private function document(string $status = 'Submitted', array $extra = []): Document
    {
        return Document::create(array_merge([
            'client_id' => $this->client->id, 'document_number' => 'DOC-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => 'Sample document', 'document_type' => 'Receipt', 'status' => $status,
            'received_date' => today(), 'uploaded_by' => $this->owner->id,
        ], $extra));
    }

    public function test_zero_configured_required_items_are_not_configured(): void
    {
        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertTrue($stats['not_configured']);
        $this->assertNull($stats['percentage']);
        $this->assertSame(0, $stats['total_required']);
    }

    public function test_missing_requirement_is_counted_without_upload(): void
    {
        $this->requirement();
        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertFalse($stats['not_configured']);
        $this->assertSame(1, $stats['total_required']);
        $this->assertSame(1, $stats['missing']);
        $this->assertSame(0, $stats['verified']);
        $this->assertSame(0, $stats['percentage']);
    }

    public function test_approved_document_marks_requirement_verified(): void
    {
        $requirement = $this->requirement();
        $requirement->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);
        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertSame(1, $stats['verified']);
        $this->assertSame(0, $stats['missing']);
        $this->assertSame(100, $stats['percentage']);
    }

    public function test_awaiting_incomplete_and_clarification_states(): void
    {
        $awaiting = $this->requirement(['name' => 'Awaiting']);
        $awaiting->documents()->attach($this->document('Under Review')->id, ['linked_by' => $this->owner->id]);

        $incomplete = $this->requirement(['name' => 'Incomplete']);
        $incomplete->documents()->attach($this->document('Rejected')->id, ['linked_by' => $this->owner->id]);

        $clarification = $this->requirement(['name' => 'Clarification']);
        $clarification->documents()->attach($this->document('Needs Clarification')->id, ['linked_by' => $this->owner->id]);

        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertSame(3, $stats['total_required']);
        $this->assertSame(1, $stats['awaiting']);
        $this->assertSame(1, $stats['incomplete']);
        $this->assertSame(1, $stats['clarification']);
        $this->assertSame(0, $stats['verified']);
        $this->assertSame(0, $stats['missing']);
    }

    public function test_optional_requirements_do_not_reduce_completeness(): void
    {
        $this->requirement(['is_required' => false, 'name' => 'Optional']);
        $required = $this->requirement(['name' => 'Required']);
        $required->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);

        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertSame(1, $stats['total_required']);
        $this->assertSame(1, $stats['verified']);
        $this->assertSame(100, $stats['percentage']);
    }

    public function test_inactive_requirements_are_excluded(): void
    {
        $this->requirement(['is_active' => false, 'name' => 'Inactive']);
        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertTrue($stats['not_configured']);
        $this->assertSame(0, $stats['total_required']);
    }

    public function test_multiple_uploads_satisfy_one_requirement_once(): void
    {
        $requirement = $this->requirement();
        $requirement->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);
        $requirement->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);

        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertSame(1, $stats['verified']);
        $this->assertSame(100, $stats['percentage']);
    }

    public function test_approved_evidence_wins_over_later_rejected_link(): void
    {
        $requirement = $this->requirement();
        $approved = $this->document('Approved');
        $requirement->documents()->attach($approved->id, ['linked_by' => $this->owner->id]);
        $requirement->documents()->attach($this->document('Rejected')->id, ['linked_by' => $this->owner->id]);

        $this->assertSame(DocumentCompleteness::STATE_VERIFIED, DocumentCompleteness::stateOf($requirement->fresh(['documents'])));
        $this->assertSame($approved->id, DocumentCompleteness::representativeDocument($requirement->fresh(['documents']))->id);
    }
}
