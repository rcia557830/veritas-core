<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\JournalEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\JournalFixtures;
use Tests\Support\VoucherFixtures;
use Tests\TestCase;

class AccountingSecurityTest extends TestCase
{
    use JournalFixtures, VoucherFixtures, RefreshDatabase;

    private User $bookkeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
    }

    public function test_bookkeeper_cannot_post_a_journal(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper);

        $this->actingAs($this->bookkeeper)
            ->post('/ledger/'.$entry->id.'/post')
            ->assertForbidden();

        $this->assertSame('Draft', $entry->fresh()->status);
    }

    public function test_journal_evidence_rejects_another_clients_document(): void
    {
        $clientA = Client::firstOrFail();
        $clientB = Client::where('id', '!=', $clientA->id)->firstOrFail();
        $entry = $this->structuredJournal($this->bookkeeper, 'Draft', $clientA);
        $document = Document::where('client_id', $clientB->id)->firstOrFail();

        $this->actingAs($this->bookkeeper);

        try {
            JournalEvidence::sync($entry, [$document->id], []);
            $this->fail('Cross-client journal evidence must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document_ids', $e->errors());
        }
    }

    public function test_posted_voucher_cannot_be_edited_even_by_the_owner(): void
    {
        $client = Client::firstOrFail();
        $voucher = $this->postedVoucher($client, 'JV');
        $owner = User::where('email', 'owner@veritascore.local')->firstOrFail();

        $this->actingAs($owner)
            ->get('/vouchers/'.$voucher->id.'/edit')
            ->assertForbidden();
    }

    public function test_bookkeeper_cannot_review_a_journal(): void
    {
        $entry = $this->structuredJournal($this->bookkeeper);

        $this->actingAs($this->bookkeeper)
            ->post('/ledger/'.$entry->id.'/transition', ['action' => 'review'])
            ->assertForbidden();

        $this->assertSame('Draft', $entry->fresh()->status);
    }
}
