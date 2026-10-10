<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->unique(['id', 'client_id'], 'documents_id_client_unique'));
        Schema::create('journal_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ledger_entry_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('document_id');
            $table->string('document_number');
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('attached_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();
            $table->foreign(['ledger_entry_id', 'client_id'], 'journal_documents_entry_owner')->references(['id', 'client_id'])->on('ledger_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['document_id', 'client_id'], 'journal_documents_document_owner')->references(['id', 'client_id'])->on('documents')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['ledger_entry_id', 'detached_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('journal_documents')->exists()) {
            throw new RuntimeException('Evidence rollback refused: retained journal evidence exists.');
        }
        Schema::drop('journal_documents');
        Schema::table('documents', fn (Blueprint $table) => $table->dropUnique('documents_id_client_unique'));
    }
};
