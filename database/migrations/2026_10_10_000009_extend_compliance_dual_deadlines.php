<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_records', function (Blueprint $t) {
            // Internal client document submission deadline. Nullable so historical
            // records are not backfilled: their submission deadline is derived on
            // read from the official filing deadline. A stored value represents an
            // explicitly recorded (or manually overridden) deadline.
            $t->date('submission_deadline')->nullable()->index();
            $t->boolean('submission_deadline_is_manual')->default(false);
            $t->text('submission_deadline_override_reason')->nullable();
        });

        Schema::create('compliance_follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('compliance_record_id')->constrained('compliance_records')->cascadeOnDelete();
            $t->string('status')->default('Open')->index();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('follow_up_date')->nullable()->index();
            $t->text('remarks')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['compliance_record_id', 'status'], 'compliance_followup_record_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_follow_ups');
        Schema::table('compliance_records', function (Blueprint $t) {
            // Drop the index before the indexed column so SQLite table rebuilds
            // during dropColumn do not attempt to recreate a stale index.
            $t->dropIndex(['submission_deadline']);
            $t->dropColumn(['submission_deadline', 'submission_deadline_is_manual', 'submission_deadline_override_reason']);
        });
    }
};
