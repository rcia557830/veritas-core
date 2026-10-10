<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classify document requirements into onboarding (client registration /
        // activation documents such as CBL and COR) versus periodic (documents
        // tied to an accounting period). Additive and non-destructive: existing
        // period-linked requirements become "periodic"; everything else becomes
        // "onboarding". Historical verification statuses are not reinterpreted.
        Schema::table('document_requirements', function (Blueprint $t) {
            $t->string('scope')->nullable()->index();
        });
        DB::table('document_requirements')->whereNull('accounting_period_id')->update(['scope' => 'onboarding']);
        DB::table('document_requirements')->whereNotNull('accounting_period_id')->update(['scope' => 'periodic']);

        // Onboarding completion is recorded independently from client `status`
        // (Active/Inactive/Archived) so activation can be attributed and audited
        // without retroactively deactivating any existing client.
        Schema::table('clients', function (Blueprint $t) {
            $t->timestamp('onboarded_at')->nullable()->index();
            $t->foreignId('onboarded_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropConstrainedForeignId('onboarded_by');
            $t->dropIndex(['onboarded_at']);
            $t->dropColumn(['onboarded_at']);
        });
        Schema::table('document_requirements', function (Blueprint $t) {
            $t->dropIndex(['scope']);
            $t->dropColumn('scope');
        });
    }
};
