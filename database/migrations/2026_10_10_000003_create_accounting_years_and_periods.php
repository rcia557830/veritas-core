<?php

use App\Support\AccountingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->string('label', 100);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->timestamps();
            $t->unique(['client_id', 'starts_on'], 'year_start_unique');
            $t->unique(['id', 'client_id'], 'year_owner_unique');
            $t->index(['client_id', 'starts_on', 'ends_on'], 'year_dates_index');
        });
        AccountingSchema::checks('accounting_years', ['year_dates_valid' => 'starts_on <= ends_on']);
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedBigInteger('accounting_year_id');
            $t->string('label', 100);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->enum('status', ['Open', 'Closed'])->default('Open');
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'starts_on'], 'period_start_unique');
            $t->unique(['id', 'client_id'], 'period_owner_unique');
            $t->index(['client_id', 'status', 'starts_on', 'ends_on'], 'period_dates_index');
            $t->foreign(['accounting_year_id', 'client_id'], 'period_year_owner_fk')->references(['id', 'client_id'])->on('accounting_years')->restrictOnDelete()->restrictOnUpdate();
        });
        AccountingSchema::checks('accounting_periods', [
            'period_dates_valid' => 'starts_on <= ends_on',
            'period_closure_valid' => "(status = 'Open' AND closed_by IS NULL AND closed_at IS NULL) OR (status = 'Closed' AND closed_by IS NOT NULL AND closed_at IS NOT NULL)",
        ]);
    }

    public function down(): void
    {
        AccountingSchema::assertUnused();
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounting_years');
    }
};
