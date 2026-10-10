<?php

use App\Support\AccountingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('version');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['name', 'version']);
        });
        Schema::create('account_template_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_template_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->string('code');
            $t->char('code_key', 64);
            $t->string('name');
            $t->enum('classification', ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['account_template_id', 'code_key'], 'template_code_unique');
        });
    }

    public function down(): void
    {
        AccountingSchema::assertUnused();
        Schema::dropIfExists('account_template_items');
        Schema::dropIfExists('account_templates');
    }
};
