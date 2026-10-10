<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $bookkeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Manila'));
        $this->seed();
        $this->owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'business_name' => 'Registration Test Co', 'business_type' => 'Corporation',
            'contact_person' => 'Test Contact', 'email' => 'reg@example.com', 'phone' => '09171234567',
            'tin' => '123456789', 'address' => 'Test Address',
            'registration_status' => 'Pending', 'business_license_status' => 'Pending',
            'status' => 'Active', 'notes' => '',
        ], $extra);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['phone' => 'invalid phone']))
            ->assertSessionHasErrors('phone');
    }

    public function test_invalid_tin_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['tin' => '123']))
            ->assertSessionHasErrors('tin');
    }

    public function test_valid_tin_and_phone_are_accepted(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload([
            'tin' => '123-456-789-000', 'phone' => '+63 917 123 4567',
        ]))->assertRedirect();

        $this->assertDatabaseHas('clients', ['business_name' => 'Registration Test Co', 'tin' => '123-456-789-000']);
    }

    public function test_client_codes_are_generated_and_unique(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['business_name' => 'First Co']))->assertRedirect();
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['business_name' => 'Second Co']))->assertRedirect();

        $codes = Client::pluck('client_code')->unique();
        $this->assertSame(Client::count(), $codes->count());
    }

    public function test_duplicate_client_code_is_rejected_at_database_level(): void
    {
        Client::create([
            'client_code' => 'CL-DUPLICATE', 'business_name' => 'Duplicate Co', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Client::create([
            'client_code' => 'CL-DUPLICATE', 'business_name' => 'Duplicate Co 2', 'business_type' => 'Corporation',
            'status' => 'Active', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_owner_can_assign_responsible_staff(): void
    {
        $this->actingAs($this->owner)->post(route('clients.store'), $this->payload(['assigned_to' => $this->bookkeeper->id]))->assertRedirect();
        $this->assertDatabaseHas('clients', ['business_name' => 'Registration Test Co', 'assigned_to' => $this->bookkeeper->id]);
    }

    public function test_bookkeeper_is_self_assigned_on_create(): void
    {
        $this->actingAs($this->bookkeeper)->post(route('clients.store'), $this->payload())->assertRedirect();
        $this->assertDatabaseHas('clients', ['business_name' => 'Registration Test Co', 'assigned_to' => $this->bookkeeper->id]);
    }

    public function test_bookkeeper_cannot_assign_another_staff(): void
    {
        $this->actingAs($this->bookkeeper)->post(route('clients.store'), $this->payload(['assigned_to' => $this->owner->id]))->assertForbidden();
    }
}
