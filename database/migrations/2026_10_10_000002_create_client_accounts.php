<?php

use App\Support\AccountingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('account_template_item_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->string('code');
            $t->char('code_key', 64);
            $t->string('name');
            $t->enum('classification', ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['client_id', 'code_key'], 'client_account_code_unique');
            $t->unique(['id', 'client_id'], 'account_owner_unique');
            $t->index(['client_id', 'is_active']);
        });
    }

    public function down(): void
    {
        AccountingSchema::assertUnused();
        Schema::dropIfExists('accounts');
    }
};
