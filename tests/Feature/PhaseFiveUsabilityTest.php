<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Permission;
use App\Models\User;
use App\Services\Access;
use App\Support\Money;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SuperAdminFixtures;
use Tests\TestCase;

class PhaseFiveUsabilityTest extends TestCase
{
    use RefreshDatabase, SuperAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_every_visible_navigation_link_is_accessible_for_each_role(): void
    {
        foreach ([User::where('email', 'owner@veritascore.local')->firstOrFail(), User::where('email', 'manager@veritascore.local')->firstOrFail(), User::where('email', 'bookkeeper@veritascore.local')->firstOrFail(), $this->superAdmin()] as $user) {
            $response = $this->actingAs($user)->get('/dashboard')->assertOk();
            $html = new \DOMDocument;
            @$html->loadHTML($response->getContent());
            $xpath = new \DOMXPath($html);
            $groups = $xpath->query('//*[@id="mainNav"]/*[@data-nav-group]');
            $this->assertSame($user->hasRole('super-admin') ? 2 : 6, $groups->length);
            foreach ($groups as $group) {
                $this->assertTrue($group->nodeName === 'a' || $xpath->query('.//a', $group)->length > 0);
            }
            foreach ($xpath->query('//*[@id="mainNav"]//a') as $link) {
                $this->get($link->getAttribute('href'))->assertOk();
            }
            // Local workspace views remain reachable after removing sidebar submenus.
            foreach (Navigation::groups() as $group) {
                foreach ($group['links'] as $link) {
                    $this->get($link['url'])->assertOk();
                }
            }
            if ($user->hasRole('super-admin')) {
                $response->assertDontSee('data-nav-group="accounting"', false)->assertDontSee('Active Clients');
                $this->get('/financial-audit')->assertForbidden();
            } else {
                $response->assertDontSee('data-nav-group="administration"', false);
                $this->get('/admin/roles')->assertForbidden();
                $this->get('/financial-audit')->assertOk();
            }
        }
    }

    public function test_dashboard_cards_match_their_filtered_records_and_scoped_balances(): void
    {
        foreach (['owner', 'manager', 'bookkeeper'] as $name) {
            $user = User::where('email', $name.'@veritascore.local')->firstOrFail();
            $response = $this->actingAs($user)->get('/dashboard')->assertOk();
            $cards = $response->viewData('cards');
            $this->assertCount(6, $cards);
            foreach ($cards as $card) {
                $records = $this->get($card['url'])->assertOk()->viewData('records');
                if ($card['label'] === 'Outstanding Billing') {
                    $total = $records->getCollection()->sum(fn ($invoice) => $invoice->balance_cents);
                    $this->assertSame(Money::format(Money::decimal($total)), $card['value']);
                } else {
                    $this->assertSame($records->total(), $card['value'], $card['label']);
                }
            }
        }
    }

    public function test_dashboard_and_attention_queue_exclude_unassigned_clients(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $client = Client::create(['client_code' => 'PHASE5-PRIVATE', 'business_name' => 'Private Phase Five Client', 'business_type' => 'Corporation', 'status' => 'Active', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
        Document::create(['client_id' => $client->id, 'document_number' => 'PHASE5-DOC', 'title' => 'Private attention document', 'document_type' => 'Other', 'status' => 'Submitted', 'received_date' => today(), 'uploaded_by' => $owner->id]);
        $this->actingAs($bookkeeper)->get('/documents?queue=attention')->assertOk()->assertDontSee('Private attention document');
        $this->get('/dashboard')->assertViewHas('cards', fn ($cards) => $cards[0]['value'] === Access::query(Client::class)->where('status', 'Active')->count())->assertDontSee('Private Phase Five Client');
    }

    public function test_removed_permissions_hide_empty_groups_and_matching_cards(): void
    {
        $user = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
        $user->role->permissions()->detach(Permission::whereIn('name', ['knowledge.view', 'document.view', 'requirement.view'])->pluck('id'));
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()
            ->assertDontSee('data-nav-group="knowledge"', false)
            ->assertDontSee('data-nav-group="documents"', false)
            ->assertDontSee('Documents Needing Attention');
    }

    public function test_terminal_compliance_is_absent_from_pending_dashboard_table(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        ComplianceRecord::query()->update(['status' => 'Completed']);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertViewHas('deadlines', fn ($records) => $records->isEmpty())->assertSee('No open deadlines');
    }

    public function test_failed_form_preserves_values_and_accessible_errors(): void
    {
        $this->actingAs(User::where('email', 'owner@veritascore.local')->firstOrFail());
        $this->from('/clients/create')->post('/clients', ['business_name' => 'Retained client entry', 'email' => 'invalid-email'])->assertSessionHasErrors('email')->assertRedirect('/clients/create');
        $this->get('/clients/create')->assertOk()->assertSee('value="Retained client entry"', false)->assertSee('aria-describedby="error_email"', false)->assertSee('data-error-summary', false);
    }

    public function test_follow_up_queues_select_open_work_and_keep_filters_when_paginating(): void
    {
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);
        $client = Client::firstOrFail();
        for ($i = 0; $i < 12; $i++) {
            $requirement = DocumentRequirement::create(['client_id' => $client->id, 'name' => 'Phase5 requirement '.$i, 'type' => 'Other', 'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $owner->id]);
            $requirement->followUps()->create(['status' => $i === 11 ? 'Resolved' : 'Open', 'created_by' => $owner->id]);
        }
        $this->get('/requirements/monitoring?follow_up=open&per_page=10&page=2')->assertOk()->assertViewHas('records', function ($records) {
            $this->assertSame(11, $records->total());
            $this->assertSame(1, $records->count());
            $this->assertStringContainsString('follow_up=open', $records->previousPageUrl());

            return true;
        });
        $record = ComplianceRecord::firstOrFail();
        $followUp = $record->followUps()->create(['status' => 'In Progress', 'created_by' => $owner->id]);
        $this->get('/compliance/monitoring?follow_up=open')->assertOk()->assertViewHas('records', fn ($records) => $records->contains('id', $record->id))->assertSee(route('compliance.show', $record).'#follow-ups', false);
        $followUp->update(['status' => 'Resolved']);
        $this->get('/compliance/monitoring?follow_up=open')->assertOk()->assertViewHas('records', fn ($records) => ! $records->contains('id', $record->id));
        $this->getJson('/requirements/monitoring?follow_up=invalid')->assertUnprocessable();
        $this->getJson('/documents?queue[]=attention')->assertUnprocessable();
    }

    public function test_technical_dashboard_counts_are_real_and_roles_are_read_only(): void
    {
        $this->actingAs($this->superAdmin())->get('/dashboard')->assertOk()->assertViewHas('cards', function ($cards) {
            $this->assertCount(4, $cards);
            $this->assertSame(User::where('status', 'Active')->count(), $cards[0]['value']);

            return true;
        });
        $this->get('/admin/roles')->assertOk()->assertSee('Roles and Permissions')->assertSee('bookkeeping.view')->assertDontSee('<form method="post" action="'.route('admin.roles.index').'"', false);
    }
}
