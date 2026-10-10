<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AccountingSchema
{
    public static function checks(string $table, array $checks): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expression)");
            }

            return;
        }
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Accounting migrations support MySQL and SQLite only.');
        }
        // Preserve the complete CREATE definition (including existing checks), indexes,
        // data and AUTOINCREMENT high-water mark when SQLite needs a table rebuild.
        $sql = DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
        $sequence = DB::table('sqlite_sequence')->where('name', $table)->value('seq');
        $temporary = '__accounting_'.$table;
        $sql = preg_replace('/^CREATE TABLE\s+(?:"[^"]+"|`[^`]+`|\w+)/i', 'CREATE TABLE "'.$temporary.'"', $sql, 1);
        $constraints = [];
        foreach ($checks as $name => $expression) {
            $constraints[] = 'CONSTRAINT "'.$name.'" CHECK ('.$expression.')';
        }
        $sql = preg_replace('/\)\s*$/', ', '.implode(', ', $constraints).')', $sql);
        DB::statement($sql);
        $columns = implode(', ', array_map(fn ($column) => '"'.$column.'"', Schema::getColumnListing($table)));
        DB::statement('INSERT INTO "'.$temporary.'" ('.$columns.') SELECT '.$columns.' FROM "'.$table.'"');
        DB::statement('DROP TABLE "'.$table.'"');
        DB::statement('ALTER TABLE "'.$temporary.'" RENAME TO "'.$table.'"');
        foreach ($indexes as $index) {
            DB::statement($index->sql);
        }
        if ($sequence !== null) {
            DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => $sequence]);
        }
    }

    public static function assertUnused(): void
    {
        foreach (['account_templates', 'account_template_items', 'accounts', 'accounting_years', 'accounting_periods'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Accounting rollback refused: new accounting data exists. Use a reviewed forward migration.');
            }
        }
        if (Schema::hasColumn('ledger_entries', 'posted_at') && DB::table('ledger_entries')->where(fn ($q) => $q->whereNotNull('posted_at')->orWhereNotNull('posted_by')->orWhereNotNull('accounting_period_id'))->exists()) {
            throw new RuntimeException('Accounting rollback refused: journal accounting metadata exists.');
        }
        if (Schema::hasColumn('ledger_items', 'account_id') && DB::table('ledger_items')->where(fn ($q) => $q->whereNotNull('account_id')->orWhereNotNull('client_id'))->exists()) {
            throw new RuntimeException('Accounting rollback refused: journal ownership references exist.');
        }
    }
}
