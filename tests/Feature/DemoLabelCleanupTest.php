<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Support\DemoLabels;
use Database\Seeders\DemoLabelCleanupSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoLabelCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_is_guarded_repeatable_and_preserves_other_fields(): void
    {
        config(['demo.enabled' => true]);
        $this->seed(StaffSeeder::class);
        Setting::create(['firm_name' => 'VERITAS FICTIONAL PHASE 6 DEMO']);
        $client = Client::create(['client_code' => 'DEMO-001', 'business_type' => 'Corporation', 'created_by' => DB::table('users')->value('id'), 'business_name' => 'Amihan Paper Trading (Fictional)', 'contact_person' => 'Fictional Contact 01', 'status' => 'Active', 'notes' => 'Fictional training data; not verified business information.']);
        $users = DB::table('users')->get()->map(fn ($user) => collect((array) $user)->except('name')->all())->all();
        $this->seed(DemoLabelCleanupSeeder::class);
        $this->assertSame('Amihan Paper Trading', $client->fresh()->business_name);
        $this->assertSame('Contact 01', $client->fresh()->contact_person);
        $this->assertSame($client->notes, $client->fresh()->notes);
        $this->assertSame($client->client_code, $client->fresh()->client_code);
        $this->assertSame($users, DB::table('users')->get()->map(fn ($user) => collect((array) $user)->except('name')->all())->all());
        $before = DB::table('clients')->get()->toJson();
        $this->seed(DemoLabelCleanupSeeder::class);
        $this->assertSame($before, DB::table('clients')->get()->toJson());
        $this->assertSame('Capital contribution', DemoLabels::display('Fictional: Capital contribution'));
        $this->assertSame('Annual test results', DemoLabels::clean('Annual test results'));
        config(['demo.enabled' => false]);
        $this->assertSame('Fictional: Capital contribution', DemoLabels::display('Fictional: Capital contribution'));
        $this->expectException(\RuntimeException::class);
        $this->seed(DemoLabelCleanupSeeder::class);
    }
}
