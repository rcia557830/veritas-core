<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->string('review_digest', 64)->nullable());
        foreach ($this->guards() as $name => [$table, $event, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Posted accounting evidence is immutable'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted accounting evidence is immutable'; END IF; END");
            }
        }
    }

    private function guards(): array
    {
        $guards = [
            'posted_entry_update' => ['ledger_entries', 'UPDATE', "OLD.status = 'Posted'"],
            'posted_entry_delete' => ['ledger_entries', 'DELETE', "OLD.status = 'Posted'"],
        ];
        foreach (['ledger_items', 'journal_documents'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $ids = match ($event) {
                    'INSERT' => 'NEW.ledger_entry_id',
                    'DELETE' => 'OLD.ledger_entry_id',
                    default => 'OLD.ledger_entry_id, NEW.ledger_entry_id',
                };
                // Locking reads serialize direct child writes with posting, too.
                $lock = DB::getDriverName() === 'mysql' ? ' FOR UPDATE' : '';
                $guards['posted_'.$table.'_'.strtolower($event)] = [$table, $event, "EXISTS (SELECT id FROM ledger_entries WHERE id IN ($ids) AND status = 'Posted'$lock)"];
            }
        }

        return $guards;
    }

    public function down(): void
    {
        if (DB::table('ledger_entries')->where('status', 'Posted')->orWhereNotNull('review_digest')->exists()) {
            throw new RuntimeException('Posting rollback refused: reviewed or posted evidence must be retained.');
        }
        foreach (array_keys($this->guards()) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->dropColumn('review_digest'));
    }
};
