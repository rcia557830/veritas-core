<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public static function writes(): array
    {
        return [['follow-up'], ['template']];
    }

    #[DataProvider('writes')]
    public function test_failed_audit_rolls_back_legacy_business_write(string $kind): void
    {
        $this->seed();
        $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
        $requirement = DocumentRequirement::create(['client_id' => Client::firstOrFail()->id, 'name' => 'SYNTHETIC audit rollback', 'type' => 'Other', 'created_by' => auth()->id()]);
        $table = $kind === 'follow-up' ? 'document_follow_ups' : 'document_requirement_templates';
        $count = DB::table($table)->count();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(fn () => throw new \RuntimeException('Synthetic audit insert failure'));
        $this->withoutExceptionHandling();
        try {
            $kind === 'follow-up'
                ? $this->post('/requirements/'.$requirement->id.'/follow-ups', ['status' => 'Open'])
                : $this->post('/requirements/templates', ['name' => 'SYNTHETIC rollback', 'type' => 'Other']);
            $this->fail('Audit failure must abort the write.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit insert failure', $error->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame($count, DB::table($table)->count());
    }
}
