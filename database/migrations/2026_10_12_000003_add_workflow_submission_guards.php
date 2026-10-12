<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('submission_token')->nullable();
            $table->unique(['invoice_id', 'submission_token']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->string('file_sha256', 64)->nullable();
            $table->index(['client_id', 'file_sha256']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve submission evidence; use a reviewed forward migration.');
    }
};
