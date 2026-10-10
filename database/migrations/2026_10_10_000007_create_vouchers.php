<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('ledger_entry_id')->unique();
            $t->enum('type', ['JV', 'CV', 'CR', 'CD']);
            $t->unsignedBigInteger('sequence');
            $t->string('reference', 50);
            $t->uuid('creation_token');
            $t->string('creation_digest', 64);
            $t->string('party')->nullable();
            $t->unsignedBigInteger('cash_account_id')->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->string('check_number')->nullable();
            $t->string('check_key', 64)->nullable();
            $t->date('check_date')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'type', 'sequence']);
            $t->unique(['client_id', 'reference']);
            $t->unique(['client_id', 'creation_token']);
            $t->unique(['client_id', 'cash_account_id', 'check_key']);
            $t->foreign(['ledger_entry_id', 'client_id'])->references(['id', 'client_id'])->on('ledger_entries')->restrictOnDelete();
            $t->foreign(['cash_account_id', 'client_id'])->references(['id', 'client_id'])->on('accounts')->restrictOnDelete();
        });
        foreach ($this->guards() as $name => [$table, $event, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Voucher identity and reviewed or posted records are protected'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Voucher identity and reviewed or posted records are protected'; END IF; END");
            }
        }
    }

    private function guards(): array
    {
        $mysql = DB::getDriverName() === 'mysql';
        $different = fn ($field) => $mysql ? "NOT (OLD.$field <=> NEW.$field)" : "OLD.$field IS NOT NEW.$field";
        $identity = implode(' OR ', array_map($different, ['id', 'client_id', 'ledger_entry_id', 'type', 'sequence', 'reference', 'creation_token', 'creation_digest']));
        $lock = $mysql ? ' FOR UPDATE' : '';

        return [
            'voucher_insert_guard' => ['vouchers', 'INSERT', "EXISTS (SELECT id FROM ledger_entries WHERE id = NEW.ledger_entry_id AND status <> 'Draft'$lock)"],
            'voucher_update_guard' => ['vouchers', 'UPDATE', "($identity) OR EXISTS (SELECT id FROM ledger_entries WHERE id = OLD.ledger_entry_id AND status NOT IN ('Draft', 'Needs Correction')$lock)"],
            'voucher_delete_guard' => ['vouchers', 'DELETE', '1 = 1'],
            'voucher_journal_guard' => ['ledger_entries', 'UPDATE', 'EXISTS (SELECT id FROM vouchers WHERE ledger_entry_id = OLD.id) AND (NEW.deleted_at IS NOT NULL OR '.$different('reference_number').')'],
        ];
    }

    public function down(): void
    {
        if (DB::table('vouchers')->exists()) {
            throw new RuntimeException('Voucher rollback refused: issued references must be retained.');
        }
        foreach (array_keys($this->guards()) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
        Schema::dropIfExists('vouchers');
    }
};
