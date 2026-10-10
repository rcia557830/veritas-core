<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTemplate;
use App\Models\Client;
use App\Models\Permission;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Support\AccountCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use AccountingFixtures, RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $bookkeeper;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $this->client = Client::firstOrFail();
        $this->actingAs($this->owner);
    }

    private function definition(array $extra = []): array
    {
        return array_replace(['code' => ' 001-a ', 'name' => 'SYNTHETIC Cash', 'classification' => 'Asset'], $extra);
    }

    private function template(): AccountTemplate
    {
        $template = AccountTemplate::create(['name' => 'SYNTHETIC TEST TEMPLATE', 'version' => 1, 'is_active' => true]);
        $template->items()->create($this->definition());
        $template->items()->create($this->definition(['code' => '002', 'name' => 'SYNTHETIC Equity', 'classification' => 'Equity']));

        return $template;
    }

    public function test_account_crud_filters_and_account_history_render(): void
    {
        $this->get('/accounts')->assertOk()->assertSee('Select a client');
        $this->get('/accounts?client_id='.$this->client->id)->assertOk()->assertSee('No accounts found');
        $this->get('/accounts/create?client_id='.$this->client->id)->assertOk();
        $this->post('/accounts', $this->definition(['client_id' => $this->client->id]))->assertSessionHasNoErrors()->assertRedirect();
        $account = Account::firstOrFail();
        $this->assertSame('001-a', $account->code);
        $this->get('/accounts/'.$account->id.'/edit')->assertOk();
        $this->put('/accounts/'.$account->id, $this->definition(['code' => '0001', 'name' => 'SYNTHETIC Edited']))->assertSessionHasNoErrors();
        $this->assertSame('0001', $account->fresh()->code);
        $this->get('/accounts/'.$account->id)->assertOk()->assertSee('SYNTHETIC Edited')->assertSee('Account updated from');
        $this->get('/accounts?client_id='.$this->client->id.'&q=Edited&classification=Asset&status=active')->assertOk()->assertSee('SYNTHETIC Edited');
        $this->get('/accounts?client_id='.$this->client->id.'&classification=Expense')->assertOk()->assertDontSee('SYNTHETIC Edited');
        $this->post('/accounts/'.$account->id.'/status', ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->is_active);
        $this->get('/accounts?client_id='.$this->client->id.'&status=active')->assertDontSee('SYNTHETIC Edited');
        $this->get('/accounts?client_id='.$this->client->id.'&status=inactive')->assertSee('SYNTHETIC Edited');
        $this->post('/accounts/'.$account->id.'/status', ['is_active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->is_active);
        $this->delete('/accounts/'.$account->id)->assertStatus(405);
    }

    public function test_validation_uniqueness_and_http_code_normalization(): void
    {
        $account = $this->syntheticAccount($this->client);
        $this->post('/accounts', $this->definition(['code' => ' 001-A ', 'client_id' => $this->client->id]))->assertSessionHasErrors('code');
        $other = $this->syntheticClient();
        $this->post('/accounts', $this->definition(['client_id' => $other->id]))->assertSessionHasNoErrors();
        foreach ([['code' => ''], ['code' => "\t001\t"], ['code' => str_repeat('a', 256)], ['name' => ' '], ['classification' => 'Invalid'], ['client_id' => 'x']] as $bad) {
            $this->post('/accounts', array_replace($this->definition(['client_id' => $this->client->id]), $bad))->assertSessionHasErrors(array_key_first($bad));
        }
        foreach (['001 A', '001A', '01-a', "\u{00a0}001-a\u{00a0}"] as $code) {
            $this->post('/accounts', $this->definition(['client_id' => $this->client->id, 'code' => $code]))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('accounts', ['client_id' => $this->client->id, 'code_key' => AccountCode::key($code)]);
        }
        $this->put('/accounts/'.$account->id, $this->definition(['client_id' => $other->id]))->assertSessionHasErrors('client_id');
        $this->post('/accounts/'.$account->id.'/status', ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');
        $this->get('/accounts?classification=Invalid')->assertSessionHasErrors('classification');
        $this->get('/accounts?q[]=Invalid')->assertSessionHasErrors('q');
        $this->assertSame($this->client->id, $account->fresh()->client_id);
    }

    public function test_all_five_classifications_and_literal_zero_search_are_supported(): void
    {
        foreach (ChartOfAccounts::CLASSIFICATIONS as $index => $classification) {
            $this->post('/accounts', $this->definition(['client_id' => $this->client->id, 'code' => (string) $index, 'name' => 'SYNTHETIC '.$classification, 'classification' => $classification]))->assertSessionHasNoErrors();
        }
        $this->get('/accounts?client_id='.$this->client->id.'&q=0')->assertOk()->assertSee('SYNTHETIC Asset')->assertDontSee('SYNTHETIC Liability');
        $this->owner->role->permissions()->detach(Permission::where('name', 'account.initialize')->value('id'));
        $this->post('/accounts/initialize', [])->assertForbidden();
        $this->owner->role->permissions()->detach(Permission::where('name', 'account.view')->value('id'));
        $this->get('/accounts')->assertForbidden();
    }

    public function test_bookkeeper_client_isolation_and_all_write_endpoints_are_denied(): void
    {
        $own = $this->syntheticAccount($this->client);
        $other = $this->syntheticClient();
        $foreign = $this->syntheticAccount($other, 'SECRET-CODE');
        $template = $this->template();
        $item = $template->items()->first();
        $this->actingAs($this->bookkeeper);
        $this->get('/accounts?client_id='.$this->client->id)->assertOk()->assertSee($own->code)->assertDontSee('SECRET-CODE');
        $this->get('/accounts/'.$own->id)->assertOk()->assertDontSee('Edit account')->assertDontSee('Deactivate account');
        $this->get('/accounts/'.$foreign->id)->assertForbidden();
        $this->get('/accounts?client_id='.$other->id)->assertNotFound();
        foreach (['/accounts/create?client_id='.$this->client->id, '/accounts/'.$own->id.'/edit', '/accounts/initialize?client_id='.$this->client->id, '/account-templates', '/account-templates/create', '/account-templates/'.$template->id, '/account-templates/'.$template->id.'/edit', '/account-templates/'.$template->id.'/items/create', '/account-templates/'.$template->id.'/items/'.$item->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/accounts', '/accounts/'.$own->id.'/status', '/accounts/initialize', '/account-templates', '/account-templates/'.$template->id.'/version', '/account-templates/'.$template->id.'/items'] as $url) {
            $this->post($url, $this->definition(['client_id' => $this->client->id]))->assertForbidden();
        }
        foreach (['/accounts/'.$own->id, '/account-templates/'.$template->id, '/account-templates/'.$template->id.'/items/'.$item->id] as $url) {
            $this->put($url, $this->definition())->assertForbidden();
        }
        $this->delete('/account-templates/'.$template->id.'/items/'.$item->id)->assertForbidden();
    }

    public function test_manager_permissions_are_explicit_and_revocation_takes_effect(): void
    {
        $this->actingAs($this->manager)->post('/accounts', $this->definition(['client_id' => $this->client->id]))->assertSessionHasNoErrors();
        $account = Account::firstOrFail();
        $this->put('/accounts/'.$account->id, $this->definition(['name' => 'Manager edited']))->assertSessionHasNoErrors();
        $this->post('/accounts/'.$account->id.'/status', ['is_active' => 0])->assertSessionHasNoErrors();
        $this->get('/account-templates')->assertForbidden();
        $this->post('/accounts/initialize')->assertForbidden();
        foreach (['account.update' => ['put', '/accounts/'.$account->id], 'account.deactivate' => ['post', '/accounts/'.$account->id.'/status'], 'account.create' => ['post', '/accounts']] as $permission => [$method, $url]) {
            $this->manager->role->permissions()->detach(Permission::where('name', $permission)->value('id'));
            $this->$method($url, $this->definition(['client_id' => $this->client->id, 'is_active' => 1]))->assertForbidden();
        }
        $this->owner->role->permissions()->detach(Permission::where('name', 'account-template.manage')->value('id'));
        $this->actingAs($this->owner)->get('/account-templates')->assertForbidden();
        $this->manager->role->permissions()->detach(Permission::where('name', 'client.view')->value('id'));
        $this->actingAs($this->manager)->get('/accounts')->assertForbidden();
        $this->get('/accounts/'.$account->id)->assertForbidden();
    }

    public function test_posted_account_editing_is_rejected_and_deactivation_preserves_all_business_records(): void
    {
        $account = $this->syntheticAccount($this->client);
        $entry = $this->syntheticEntry($this->client, $this->syntheticPeriod($this->client));
        $entry->items()->create(['account_name' => 'SYNTHETIC historical label', 'account_id' => $account->id, 'debit' => '0.30', 'credit' => '0.00']);
        DB::table('ledger_entries')->where('id', $entry->id)->update(['status' => 'Posted', 'posted_by' => $this->owner->id, 'posted_at' => now()]);
        $tables = ['ledger_entries', 'ledger_items', 'documents', 'compliance_records', 'invoices', 'invoice_items', 'payments'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        foreach (['code' => 'changed', 'name' => 'changed', 'classification' => 'Expense'] as $key => $value) {
            $this->put('/accounts/'.$account->id, array_replace($account->only(['code', 'name', 'classification']), [$key => $value]))->assertSessionHasErrors($key);
        }
        $this->post('/accounts/'.$account->id.'/status', ['is_active' => 0])->assertSessionHasNoErrors();
        ChartOfAccounts::initialize($this->client, $this->templateForIndependentCodes());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertSame('Asset', $account->fresh()->classification);
        $this->assertFalse($account->fresh()->is_active);
    }

    private function templateForIndependentCodes(): AccountTemplate
    {
        $template = AccountTemplate::create(['name' => 'SYNTHETIC Independent', 'version' => 1, 'is_active' => true]);
        $template->items()->create($this->definition(['code' => 'NEW']));

        return $template;
    }

    public function test_template_management_versioning_and_used_version_protection(): void
    {
        $this->get('/account-templates')->assertOk()->assertSee('No templates yet');
        $this->get('/account-templates/create')->assertOk();
        $this->post('/account-templates', ['name' => 'SYNTHETIC HTTP', 'version' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
        $template = AccountTemplate::firstOrFail();
        $root = '/account-templates/'.$template->id;
        $this->get($root.'/edit')->assertOk();
        $this->get($root.'/items/create')->assertOk();
        $this->post($root.'/items', $this->definition(['is_active' => 1]))->assertSessionHasNoErrors();
        $item = $template->items()->firstOrFail();
        $this->get($root.'/items/'.$item->id.'/edit')->assertOk();
        $this->put($root.'/items/'.$item->id, $this->definition(['name' => 'SYNTHETIC revised', 'is_active' => 1]))->assertSessionHasNoErrors();
        $this->post($root.'/items', $this->definition(['code' => ' 001-A ', 'is_active' => 1]))->assertSessionHasErrors('code');
        ChartOfAccounts::initialize($this->client, $template);
        $account = Account::firstOrFail();
        $this->put($root.'/items/'.$item->id, $this->definition(['name' => 'Forbidden', 'is_active' => 1]))->assertSessionHasErrors('template');
        $this->post($root.'/items', $this->definition(['code' => '003', 'is_active' => 1]))->assertSessionHasErrors('template');
        $this->delete($root.'/items/'.$item->id)->assertSessionHasErrors('template');
        $this->put($root, ['name' => $template->name, 'version' => 7, 'is_active' => 1])->assertSessionHasErrors('version');
        $this->put($root, ['name' => $template->name, 'version' => 1, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->post($root.'/version')->assertSessionHasNoErrors();
        $copy = AccountTemplate::where('version', 2)->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame(1, $copy->items()->count());
        $copiedItem = $copy->items()->first();
        $this->put('/account-templates/'.$copy->id.'/items/'.$copiedItem->id, $this->definition(['name' => 'SYNTHETIC v2', 'classification' => 'Expense', 'is_active' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('SYNTHETIC revised', $account->fresh()->name);
        $this->assertSame('Asset', $account->fresh()->classification);
        $this->get($root)->assertOk()->assertSee('Preserved');
        $this->delete('/account-templates/'.$copy->id.'/items/'.$copiedItem->id)->assertSessionHasNoErrors();
        $this->assertSame(0, $copy->items()->count());
    }

    public function test_initialization_is_independent_idempotent_and_conflicts_roll_back_everything(): void
    {
        $template = $this->template();
        $template->items()->create($this->definition(['code' => 'INACTIVE', 'is_active' => false]));
        $payload = ['client_id' => $this->client->id, 'template_id' => $template->id, 'confirmed' => 1];
        $this->get('/accounts/initialize?client_id='.$this->client->id.'&template_id='.$template->id)->assertOk()->assertSee('Preview accounts')->assertDontSee('INACTIVE');
        $this->post('/accounts/initialize', $payload)->assertSessionHasNoErrors()->assertSessionHas('success', '2 accounts copied; 0 previously copied accounts retained.');
        $account = $this->client->accounts()->first();
        $account->update(['code' => 'CLIENT-EDITED', 'name' => 'Independent', 'is_active' => false]);
        $auditCount = DB::table('audit_logs')->count();
        $this->post('/accounts/initialize', $payload)->assertSessionHasNoErrors()->assertSessionHas('success', '0 accounts copied; 2 previously copied accounts retained.');
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertFalse($account->fresh()->is_active);
        $this->assertSame('CLIENT-EDITED', $account->fresh()->code);
        $other = $this->syntheticClient();
        $this->syntheticAccount($other, '002');
        $this->post('/accounts/initialize', array_replace($payload, ['client_id' => $other->id]))->assertSessionHasErrors('template_id');
        $this->assertSame(1, $other->accounts()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $third = $this->syntheticClient();
        $this->post('/accounts/initialize', array_replace($payload, ['client_id' => $third->id]))->assertSessionHasNoErrors();
        $this->assertSame('001-a', $third->accounts()->first()->code);
        $this->assertSame(2, $third->accounts()->count());
    }

    public function test_invalid_templates_and_cross_template_item_requests_do_not_mutate_records(): void
    {
        $template = $this->template();
        $other = $this->templateForIndependentCodes();
        $item = $other->items()->first();
        $root = '/account-templates/'.$template->id;
        $this->put($root.'/items/'.$item->id, $this->definition(['is_active' => 1]))->assertNotFound();
        $this->delete($root.'/items/'.$item->id)->assertNotFound();
        $this->get($root.'/items/'.$item->id.'/edit')->assertNotFound();
        $this->post('/account-templates', ['name' => $template->name, 'version' => 1, 'is_active' => 1])->assertSessionHasErrors('version');
        $this->post('/account-templates', ['name' => 'x', 'version' => 0, 'is_active' => 1])->assertSessionHasErrors('version');
        $this->post($root.'/items', $this->definition(['classification' => 'Invalid', 'is_active' => 1]))->assertSessionHasErrors('classification');
        $this->post('/accounts/initialize', ['client_id' => $this->client->id, 'template_id' => $template->id])->assertSessionHasErrors('confirmed');
        $template->update(['is_active' => false]);
        $this->post('/accounts/initialize', ['client_id' => $this->client->id, 'template_id' => $template->id, 'confirmed' => 1])->assertSessionHasErrors('template_id');
        $empty = AccountTemplate::create(['name' => 'SYNTHETIC empty', 'version' => 1, 'is_active' => true]);
        $this->post('/accounts/initialize', ['client_id' => $this->client->id, 'template_id' => $empty->id, 'confirmed' => 1])->assertSessionHasErrors('template_id');
        $this->assertDatabaseCount('accounts', 0);
        $this->assertSame('NEW', $item->fresh()->code);
    }
}
