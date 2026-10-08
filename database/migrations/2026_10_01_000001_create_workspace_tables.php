<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status')->default('Active')->index();
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('client_code')->unique();
            $t->string('business_name')->index();
            $t->string('business_type')->index();
            $t->string('contact_person')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('tin', 30)->nullable();
            $t->text('address')->nullable();
            $t->string('registration_status')->default('Pending');
            $t->string('business_license_status')->default('Pending');
            $t->string('status')->default('Active')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->string('document_number')->unique();
            $t->string('title')->index();
            $t->string('document_type')->index();
            $t->string('status')->default('Submitted')->index();
            $t->date('received_date')->index();
            $t->date('due_date')->nullable()->index();
            $t->string('file_path')->nullable();
            $t->string('original_file_name')->nullable();
            $t->string('mime_type')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->date('transaction_date')->index();
            $t->string('reference_number')->nullable()->index();
            $t->string('description');
            $t->string('status')->default('Draft')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('ledger_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ledger_entry_id')->constrained()->cascadeOnDelete();
            $t->string('account_name');
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('compliance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->string('agency')->index();
            $t->string('requirement');
            $t->string('reporting_period')->nullable();
            $t->date('due_date')->index();
            $t->string('status')->default('Pending')->index();
            $t->date('filed_date')->nullable();
            $t->string('reference_number')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_number')->unique();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->date('invoice_date')->index();
            $t->date('due_date')->index();
            $t->decimal('tax', 15, 2)->default(0);
            $t->string('status')->default('Draft')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('quantity', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->date('payment_date')->index();
            $t->decimal('amount', 15, 2);
            $t->string('payment_method');
            $t->string('reference_number')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('knowledge_articles', function (Blueprint $t) {
            $t->id();
            $t->string('title')->index();
            $t->string('slug')->unique();
            $t->string('category')->index();
            $t->longText('content');
            $t->json('tags')->nullable();
            $t->string('status')->default('Draft')->index();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('firm_name')->default('RBCIA Accounting Firm');
            $t->text('firm_address')->nullable();
            $t->string('firm_email')->nullable();
            $t->string('contact_number', 60)->nullable();
            $t->string('logo_path')->nullable();
            $t->string('currency', 3)->default('PHP');
            $t->unsignedSmallInteger('page_size')->default(15);
            $t->boolean('notifications_enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notification_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event_key', 190);
            $t->unique(['user_id', 'event_key']);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action')->index();
            $t->string('module')->index();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->text('description');
            $t->ipAddress('ip_address')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'notification_events', 'notifications', 'settings', 'knowledge_articles', 'payments', 'invoice_items', 'invoices', 'compliance_records', 'ledger_items', 'ledger_entries', 'documents', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['status', 'last_login_at']);
        });
        Schema::dropIfExists('roles');
    }
};
