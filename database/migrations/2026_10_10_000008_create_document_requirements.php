<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requirement_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name')->index();
            $t->string('type')->index();
            $t->text('description')->nullable();
            $t->boolean('is_required')->default(true);
            $t->unsignedSmallInteger('default_due_days')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('document_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('document_requirement_templates')->nullOnDelete();
            $t->string('name')->index();
            $t->string('type')->index();
            $t->text('description')->nullable();
            $t->foreignId('accounting_period_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_required')->default(true)->index();
            $t->boolean('is_active')->default(true)->index();
            $t->date('due_date')->nullable()->index();
            $t->text('remarks')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['client_id', 'is_active', 'is_required'], 'req_client_active_required_index');
            $t->index(['accounting_period_id', 'is_active'], 'req_period_active_index');
        });

        Schema::create('requirement_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $t->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['requirement_id', 'document_id'], 'req_doc_unique');
            $t->unique('document_id', 'req_doc_document_unique');
        });

        Schema::create('document_follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $t->string('status')->default('Open')->index();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('follow_up_date')->nullable()->index();
            $t->text('remarks')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['requirement_id', 'status'], 'followup_requirement_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_follow_ups');
        Schema::dropIfExists('requirement_documents');
        Schema::dropIfExists('document_requirements');
        Schema::dropIfExists('document_requirement_templates');
    }
};
