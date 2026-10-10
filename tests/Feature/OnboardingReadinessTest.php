<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use App\Services\DocumentCompleteness;
use App\Services\OnboardingReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OnboardingReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->client = Client::create([
            'client_code' => 'CL-ONB', 'business_name' => 'Onboarding Test Co', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id,
        ]);
    }

    private function requirement(array $extra = []): DocumentRequirement
    {
        return DocumentRequirement::create(array_merge([
            'client_id' => $this->client->id, 'name' => 'Onboarding requirement', 'type' => 'Receipt',
            'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
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

    public function test_client_without_onboarding_requirements_is_not_configured(): void
    {
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_NOT_CONFIGURED, $calc['state']);
        $this->assertTrue($calc['not_configured']);
        $this->assertNull($calc['percentage']);
    }

    public function test_periodic_requirements_do_not_count_as_onboarding(): void
    {
        $this->requirement(['scope' => 'periodic', 'name' => 'Periodic tax return']);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_NOT_CONFIGURED, $calc['state']);
        $this->assertSame(0, $calc['total']);
    }

    public function test_legacy_requirement_without_scope_defaults_to_onboarding(): void
    {
        $requirement = DocumentRequirement::create([
            'client_id' => $this->client->id, 'name' => 'Legacy item', 'type' => 'Receipt',
            'is_required' => true, 'is_active' => true, 'created_by' => $this->owner->id,
        ]);
        $this->assertSame('onboarding', $requirement->scope);
    }

    public function test_missing_onboarding_document_is_not_started(): void
    {
        $this->requirement();
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_NOT_STARTED, $calc['state']);
        $this->assertSame(1, $calc['counts']['missing']);
        $this->assertSame(0, $calc['percentage']);
    }

    public function test_submitted_but_unverified_document_is_awaiting_verification(): void
    {
        $requirement = $this->requirement(['name' => 'COR', 'type' => 'Certificate of Registration']);
        $requirement->documents()->attach($this->document('Submitted')->id, ['linked_by' => $this->owner->id]);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_AWAITING_VERIFICATION, $calc['state']);
        $this->assertSame(1, $calc['counts']['awaiting']);
        $this->assertFalse(OnboardingReadiness::canActivate($this->client)['ok']);
    }

    public function test_approved_document_marks_ready_for_activation(): void
    {
        $requirement = $this->requirement(['name' => 'COR', 'type' => 'Certificate of Registration']);
        $requirement->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_READY, $calc['state']);
        $this->assertSame(100, $calc['percentage']);
        $this->assertTrue(OnboardingReadiness::canActivate($this->client)['ok']);
    }

    public function test_clarification_request_marks_needs_clarification(): void
    {
        $requirement = $this->requirement();
        $requirement->documents()->attach($this->document('Needs Clarification')->id, ['linked_by' => $this->owner->id]);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_NEEDS_CLARIFICATION, $calc['state']);
        $this->assertSame(1, $calc['counts']['clarification']);
    }

    public function test_rejected_document_marks_in_progress_and_incomplete(): void
    {
        $requirement = $this->requirement();
        $requirement->documents()->attach($this->document('Rejected')->id, ['linked_by' => $this->owner->id]);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_IN_PROGRESS, $calc['state']);
        $this->assertSame(1, $calc['counts']['incomplete']);
    }

    public function test_mixed_states_are_in_progress(): void
    {
        $verified = $this->requirement(['name' => 'Verified item']);
        $verified->documents()->attach($this->document('Approved')->id, ['linked_by' => $this->owner->id]);
        $this->requirement(['name' => 'Missing item']);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_IN_PROGRESS, $calc['state']);
        $this->assertSame(50, $calc['percentage']);
    }

    public function test_optional_requirements_are_excluded_from_readiness(): void
    {
        $this->requirement(['is_required' => false, 'name' => 'Optional item']);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertTrue($calc['not_configured']);
        $this->assertSame(0, $calc['total']);
    }

    public function test_onboarded_client_remains_onboarded(): void
    {
        $this->client->update(['onboarded_at' => now()]);
        $calc = OnboardingReadiness::calculate($this->client);
        $this->assertSame(OnboardingReadiness::STATE_ONBOARDED, $calc['state']);
        $this->assertTrue($calc['onboarded']);
    }

    public function test_cbl_and_cor_detection(): void
    {
        $cbl = $this->requirement(['name' => 'Business Permit', 'type' => 'Business Permit']);
        $cor = $this->requirement(['name' => 'Certificate of Registration', 'type' => 'Certificate of Registration']);
        $this->assertTrue(OnboardingReadiness::isCbl($cbl));
        $this->assertTrue(OnboardingReadiness::isCor($cor));
        $this->assertFalse(OnboardingReadiness::isCbl($cor));
        $this->assertFalse(OnboardingReadiness::isCor($cbl));
    }

    public function test_verification_info_is_derived_from_approval_audit(): void
    {
        $this->actingAs($this->owner);
        $requirement = $this->requirement(['name' => 'COR', 'type' => 'Certificate of Registration']);
        $document = $this->document('Approved');
        $requirement->documents()->attach($document->id, ['linked_by' => $this->owner->id]);

        AuditLog::create([
            'user_id' => $this->owner->id, 'client_id' => $this->client->id,
            'action' => 'document.validated', 'module' => 'documents', 'record_id' => $document->id,
            'description' => 'Document validated: Approved.',
        ]);

        $info = OnboardingReadiness::verificationInfo($requirement->fresh(['documents']));
        $this->assertSame($this->owner->name, $info['reviewer']);
        $this->assertNotNull($info['verified_at']);
    }

    public function test_existing_document_completeness_service_is_unchanged(): void
    {
        // Increment 4 service still counts all active+required requirements (any scope).
        $this->requirement(['name' => 'Onboarding item']);
        $this->requirement(['name' => 'Periodic item', 'scope' => 'periodic']);
        $stats = DocumentCompleteness::forClient($this->client);
        $this->assertSame(2, $stats['total_required']);
    }
}
