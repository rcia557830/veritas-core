<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleDataSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_be_seeded_without_business_records(): void
    {
        $this->seed(StaffSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertSame(0, Client::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame('owner', User::where('email', 'owner@veritascore.local')->firstOrFail()->role->slug);
    }

    public function test_default_fixtures_remain_stable_and_existing_credentials_are_preserved(): void
    {
        $this->seed();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $owner->update(['password' => 'Existing-private-password', 'name' => 'Existing Owner', 'status' => 'Inactive']);
        $hash = $owner->fresh()->password;
        $tables = ['users', 'clients', 'documents', 'compliance_records', 'ledger_entries', 'ledger_items', 'invoices', 'invoice_items', 'payments', 'knowledge_articles'];
        $counts = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->seed();

        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(6, Client::count());
        $this->assertSame('39000.00', number_format((float) DB::table('invoice_items')->sum('unit_price'), 2, '.', ''));
        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertTrue(Hash::check('Existing-private-password', $owner->fresh()->password));
        $this->assertSame('Existing Owner', $owner->fresh()->name);
        $this->assertSame('Inactive', $owner->fresh()->status);
        $this->assertSame('owner', $owner->fresh()->role->slug);
    }

    public function test_every_development_entry_point_refuses_other_environments_even_with_demo_flags(): void
    {
        $roles = Role::count();
        config(['demo.enabled' => true]);
        try {
            foreach (['production', 'staging'] as $environment) {
                $this->app->instance('env', $environment);
                foreach ([DatabaseSeeder::class, StaffSeeder::class, SampleDataSeeder::class] as $seeder) {
                    try {
                        $this->app->make($seeder)->run();
                        $this->fail('Development seeder accepted '.$environment);
                    } catch (\RuntimeException $error) {
                        $this->assertStringContainsString('disabled outside local/testing', $error->getMessage());
                    }
                }
            }
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame($roles, Role::count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
    }
}
