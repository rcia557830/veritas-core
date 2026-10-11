<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Voucher;
use App\Support\AccountingSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class DemoDeploymentTest extends TestCase
{
    use VoucherFixtures;

    public function test_triggers_are_required_by_default_and_skip_requires_demo_acknowledgement(): void
    {
        config(['demo.enabled' => false, 'demo.skip_accounting_triggers' => false]);
        $this->assertTrue(AccountingSchema::installsTriggers());
        config(['demo.skip_accounting_triggers' => true]);
        $this->expectException(\RuntimeException::class);
        AccountingSchema::installsTriggers();
    }

    public function test_fresh_demo_migrations_and_all_four_voucher_workflows_without_triggers(): void
    {
        config(['demo.enabled' => true, 'demo.skip_accounting_triggers' => true]);
        // phpunit.xml forces a dedicated in-memory SQLite database.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'trigger')->count());
        $this->seed();
        Storage::fake('local');
        $client = Client::firstOrFail();
        foreach (['JV', 'CV', 'CR', 'CD'] as $type) {
            $this->actingAs(User::where('email', 'bookkeeper@veritascore.local')->firstOrFail());
            $this->post('/vouchers', $this->voucherPayload($client, $type))->assertSessionHasNoErrors();
            $voucher = Voucher::where('type', $type)->firstOrFail();
            $url = '/ledger/'.$voucher->ledger_entry_id;
            $this->post($url.'/transition', ['action' => 'submit'])->assertSessionHasNoErrors();
            $this->post($url.'/transition', ['action' => 'review'])->assertForbidden();
            $this->actingAs(User::where('email', 'manager@veritascore.local')->firstOrFail());
            $this->post($url.'/transition', ['action' => 'review'])->assertSessionHasNoErrors();
            $this->post($url.'/post')->assertSessionHasNoErrors();
            $this->assertSame('Posted', $voucher->journal->fresh()->status);
            $this->post($url.'/post')->assertSessionHasErrors('posting');
            $this->get('/vouchers/'.$voucher->id.'/edit')->assertForbidden();
            $this->get('/vouchers/'.$voucher->id.'/print')->assertOk()->assertSee($voucher->reference);
        }
    }
}
