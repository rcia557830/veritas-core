<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Dedicated disposable connection already verified before this hook.
        $this->artisan('db:wipe', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    private function assertForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } else {
            $this->assertSame(1, (int) DB::scalar('SELECT @@foreign_key_checks'));
        }
    }

    private function assertSequences(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(90, (int) DB::table('sqlite_sequence')->where('name', 'ledger_entries')->value('seq'));
            $this->assertSame(100, (int) DB::table('sqlite_sequence')->where('name', 'ledger_items')->value('seq'));
        } else {
            $this->assertSame(91, (int) DB::selectOne("SHOW TABLE STATUS LIKE 'ledger_entries'")->Auto_increment);
            $this->assertSame(101, (int) DB::selectOne("SHOW TABLE STATUS LIKE 'ledger_items'")->Auto_increment);
        }
    }

    private function legacySchema(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! str_contains(basename($path), '2026_10_10_')));
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    private function legacyRecords(): array
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic legacy actor', 'email' => 'synthetic-legacy@example.invalid', 'password' => 'unusable-test-only']);
        $client = DB::table('clients')->insertGetId(['client_code' => 'SYNTHETIC-LEGACY', 'business_name' => 'Synthetic legacy client', 'business_type' => 'Corporation', 'created_by' => $user]);
        DB::table('ledger_entries')->insert(['id' => 42, 'client_id' => $client, 'transaction_date' => '2024-11-23', 'reference_number' => 'SYNTHETIC-OLD-001', 'description' => 'Preserve synthetic legacy text', 'status' => 'Reviewed', 'notes' => 'Not authorized for posting', 'created_by' => $user, 'reviewed_by' => $user, 'reviewed_at' => '2024-11-24 11:12:13', 'created_at' => '2024-11-23 10:11:12', 'updated_at' => '2024-11-24 11:12:13']);
        DB::table('ledger_items')->insert([
            ['id' => 73, 'ledger_entry_id' => 42, 'account_name' => 'Unmapped synthetic name A', 'debit' => '100.01', 'credit' => '0.00', 'created_at' => '2024-11-23 10:11:12'],
            ['id' => 74, 'ledger_entry_id' => 42, 'account_name' => 'Unmapped synthetic name B', 'debit' => '0.00', 'credit' => '100.01', 'created_at' => '2024-11-23 10:11:12'],
        ]);
        // Preserve sequence high-water marks even when the highest IDs were deleted.
        if (DB::getDriverName() === 'sqlite') {
            DB::table('sqlite_sequence')->where('name', 'ledger_entries')->update(['seq' => 90]);
            DB::table('sqlite_sequence')->where('name', 'ledger_items')->update(['seq' => 100]);
        } else {
            DB::statement('ALTER TABLE ledger_entries AUTO_INCREMENT = 91');
            DB::statement('ALTER TABLE ledger_items AUTO_INCREMENT = 101');
        }

        return ['entries' => DB::table('ledger_entries')->orderBy('id')->get()->toArray(), 'lines' => DB::table('ledger_items')->orderBy('id')->get()->toArray()];
    }

    public function test_fresh_install_has_foreign_keys_checks_and_no_accounting_seed_data(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach (['account_templates', 'account_template_items', 'accounts', 'accounting_years', 'accounting_periods'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertForeignKeys();
        $definition = DB::getDriverName() === 'sqlite' ? DB::scalar("SELECT sql FROM sqlite_master WHERE name = 'ledger_items'") : DB::selectOne('SHOW CREATE TABLE ledger_items')->{'Create Table'};
        $this->assertStringContainsString('line_ownership_complete', $definition);
    }

    public function test_legacy_upgrade_rollback_and_reapplication_preserve_rows_indexes_and_sequences(): void
    {
        $this->legacySchema();
        $before = $this->legacyRecords();
        $entryColumns = Schema::getColumnListing('ledger_entries');
        $lineColumns = Schema::getColumnListing('ledger_items');
        $entryIndexes = Schema::getIndexes('ledger_entries');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertEquals($before['entries'], DB::table('ledger_entries')->select($entryColumns)->orderBy('id')->get()->toArray());
        $this->assertEquals($before['lines'], DB::table('ledger_items')->select($lineColumns)->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('ledger_entries', ['id' => 42, 'status' => 'Reviewed', 'accounting_period_id' => null, 'posted_by' => null, 'posted_at' => null]);
        $this->assertSame(2, DB::table('ledger_items')->whereNull('account_id')->whereNull('client_id')->count());
        foreach ($entryIndexes as $index) {
            $this->assertContains($index, Schema::getIndexes('ledger_entries'));
        }
        $this->assertSequences();
        $this->artisan('migrate:rollback', ['--step' => 7, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('accounts'));
        $this->assertSame($entryColumns, Schema::getColumnListing('ledger_entries'));
        $this->assertSame($lineColumns, Schema::getColumnListing('ledger_items'));
        $this->assertEquals($before['entries'], DB::table('ledger_entries')->orderBy('id')->get()->toArray());
        $this->assertEquals($before['lines'], DB::table('ledger_items')->orderBy('id')->get()->toArray());
        $this->assertSequences();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertEquals($before['entries'], DB::table('ledger_entries')->select($entryColumns)->orderBy('id')->get()->toArray());
        $this->assertEquals($before['lines'], DB::table('ledger_items')->select($lineColumns)->orderBy('id')->get()->toArray());
        $this->assertForeignKeys();
    }

    public function test_rollback_refuses_to_discard_populated_accounting_structures(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        DB::table('account_templates')->insert(['name' => 'SYNTHETIC RETAIN', 'version' => 1]);
        $migration = require database_path('migrations/2026_10_10_000004_extend_ledger_accounting_references.php');
        try {
            $migration->down();
            $this->fail('Populated accounting data must block rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('rollback refused', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('ledger_items', 'account_id'));
        $this->assertDatabaseHas('account_templates', ['name' => 'SYNTHETIC RETAIN']);
    }
}
