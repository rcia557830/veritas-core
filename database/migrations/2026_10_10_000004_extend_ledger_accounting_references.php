<?php

use App\Support\AccountingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private function alter(Closure $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $operation();

            return;
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite accounting schema alteration requires no active transaction.');
        }
        $sequences = DB::table('sqlite_sequence')->whereIn('name', ['ledger_entries', 'ledger_items'])->pluck('seq', 'name');
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($operation, $sequences) {
                $operation();
                foreach ($sequences as $name => $sequence) {
                    DB::table('sqlite_sequence')->where('name', $name)->update(['seq' => $sequence]);
                }
                if (DB::select('PRAGMA foreign_key_check')) {
                    throw new RuntimeException('Accounting migration would violate existing foreign keys.');
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function up(): void
    {
        $this->alter(function () {
            $originalIndexes = [];
            foreach (['ledger_entries', 'ledger_items'] as $table) {
                $originalIndexes[$table] = Schema::getIndexes($table);
            }
            Schema::table('ledger_entries', function (Blueprint $t) {
                $t->unsignedBigInteger('accounting_period_id')->nullable();
                $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $t->timestamp('posted_at')->nullable();
                $t->unique(['id', 'client_id'], 'entry_owner_unique');
                $t->index(['client_id', 'status', 'transaction_date', 'id'], 'entry_ledger_index');
                $t->foreign(['accounting_period_id', 'client_id'], 'entry_period_owner_fk')->references(['id', 'client_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
            });
            Schema::table('ledger_items', function (Blueprint $t) {
                $t->unsignedBigInteger('account_id')->nullable();
                $t->unsignedBigInteger('client_id')->nullable();
                $t->foreign(['ledger_entry_id', 'client_id'], 'line_entry_owner_fk')->references(['id', 'client_id'])->on('ledger_entries')->restrictOnDelete()->restrictOnUpdate();
                $t->foreign(['account_id', 'client_id'], 'line_account_owner_fk')->references(['id', 'client_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
                $t->index(['account_id', 'ledger_entry_id'], 'line_account_ledger_index');
            });
            AccountingSchema::checks('ledger_items', ['line_ownership_complete' => '(account_id IS NULL AND client_id IS NULL) OR (account_id IS NOT NULL AND client_id IS NOT NULL)']);
            // MySQL can discard an implicit FK index when a wider index covers it.
            // Restore original named indexes explicitly for upgrade compatibility.
            foreach ($originalIndexes as $table => $indexes) {
                $names = array_column(Schema::getIndexes($table), 'name');
                foreach ($indexes as $index) {
                    if (! $index['primary'] && ! in_array($index['name'], $names)) {
                        Schema::table($table, function (Blueprint $t) use ($index) {
                            $index['unique'] ? $t->unique($index['columns'], $index['name']) : $t->index($index['columns'], $index['name']);
                        });
                    }
                }
            }
        });
    }

    public function down(): void
    {
        AccountingSchema::assertUnused();
        $this->alter(function () {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE ledger_items DROP CHECK line_ownership_complete');
            }
            // SQLite's schema alteration rebuilds the table without the removed check.
            Schema::table('ledger_items', function (Blueprint $t) {
                $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['ledger_entry_id', 'client_id'] : 'line_entry_owner_fk');
                $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['account_id', 'client_id'] : 'line_account_owner_fk');
                $t->dropIndex('line_account_ledger_index');
                $t->dropColumn(['account_id', 'client_id']);
            });
            Schema::table('ledger_entries', function (Blueprint $t) {
                $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['accounting_period_id', 'client_id'] : 'entry_period_owner_fk');
                $t->dropForeign(['posted_by']);
                $t->dropUnique('entry_owner_unique');
                $t->dropIndex('entry_ledger_index');
                $t->dropColumn(['accounting_period_id', 'posted_by', 'posted_at']);
            });
        });
    }
};
