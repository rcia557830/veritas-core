<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('event_class', 20)->nullable();
            $table->string('entity_type')->nullable();
            $table->json('evidence')->nullable();
        });
        // No historical rewrite: null metadata means not captured in that version.
        foreach (['audit_logs', 'security_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_append_only_'.strtolower($event);
                try {
                    if (DB::getDriverName() === 'sqlite') {
                        DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table BEGIN SELECT RAISE(ABORT, 'Audit history is append-only'); END");
                    } elseif (DB::getDriverName() === 'mysql') {
                        DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit history is append-only'");
                    } else {
                        throw new RuntimeException('Audit protection supports SQLite and MySQL only.');
                    }
                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), '1419') || str_contains($e->getMessage(), 'SUPER privilege')) {
                        // Cloud provider restricts CREATE TRIGGER (binary logging without SUPER privilege).
                        // Audit history immutability remains strictly enforced by model guards (AuditLog::booted).
                        continue;
                    }
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Audit history protection requires a reviewed forward migration.');
    }
};
