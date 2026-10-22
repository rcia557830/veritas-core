<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleasePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_banner_follows_partial_overdue_and_settled_payments(): void
    {
        $this->seed();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $invoice = Invoice::create(['invoice_number' => 'RELEASE-PAYMENT', 'client_id' => Client::firstOrFail()->id,
            'invoice_date' => today()->subDays(10), 'due_date' => today()->addDay(), 'tax' => '0.00', 'status' => 'Open', 'created_by' => $owner->id]);
        $invoice->items()->create(['description' => 'Bookkeeping', 'quantity' => 1, 'unit_price' => '100.00']);
        $this->actingAs($owner);
        $payment = ['payment_date' => today()->toDateString(), 'amount' => '25.00', 'payment_method' => 'Cash', 'submission_token' => (string) Str::uuid()];
        $this->post(route('billing.payments.store', $invoice), $payment)->assertSessionHasNoErrors();
        $this->get(route('billing.show', $invoice))->assertOk()->assertSee('Partially Paid')->assertSee('75.00');
        $invoice->update(['due_date' => today()->subDay()]);
        $this->get(route('billing.show', $invoice))->assertOk()->assertSee('Payment overdue')->assertDontSee('Paid in full');
        $this->getJson('/notifications')->assertOk()->assertJsonFragment(['title' => 'Overdue invoice RELEASE-PAYMENT']);
        $payment['amount'] = '75.00';
        $payment['submission_token'] = (string) Str::uuid();
        $this->post(route('billing.payments.store', $invoice), $payment)->assertSessionHasNoErrors();
        $this->assertSame('Open', $invoice->fresh()->status);
        $this->get(route('billing.show', $invoice))->assertOk()->assertSee('Paid in full')->assertDontSee('Payment due:')->assertDontSee('Record client payment');
        $this->getJson('/notifications')->assertOk()->assertJsonMissing(['title' => 'Overdue invoice RELEASE-PAYMENT']);
    }

    public function test_rejected_or_unclear_statutory_evidence_never_claims_verification(): void
    {
        $this->seed();
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
        $this->actingAs($owner);
        foreach (['Rejected', 'Needs Clarification'] as $index => $status) {
            $client = Client::create(['client_code' => 'REL-'.$index, 'business_name' => 'Release client '.$index, 'business_type' => 'Corporation', 'status' => 'Active', 'created_by' => $owner->id]);
            $requirement = DocumentRequirement::create(['client_id' => $client->id, 'name' => 'COR', 'type' => 'Certificate of Registration', 'scope' => 'onboarding', 'is_required' => true, 'is_active' => true, 'created_by' => $owner->id]);
            $document = Document::create(['client_id' => $client->id, 'document_number' => 'REL-DOC-'.$index, 'title' => 'COR evidence', 'document_type' => 'Certificate of Registration', 'status' => $status, 'received_date' => today(), 'uploaded_by' => $owner->id]);
            $requirement->documents()->attach($document->id, ['linked_by' => $owner->id]);
            $this->get(route('clients.show', $client))->assertOk()->assertDontSee('Statutory documents verified.')
                ->assertSee($status === 'Rejected' ? 'required document(s) incomplete.' : 'required document(s) need clarification.');
            $this->post(route('clients.onboarding.complete', $client))->assertRedirect()->assertSessionHas('onboarding_blockers');
            $this->assertNull($client->fresh()->onboarded_at);
        }
    }
}
