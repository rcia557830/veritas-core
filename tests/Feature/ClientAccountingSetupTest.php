<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use Tests\TestCase;

class ClientAccountingSetupTest extends TestCase
{
    private function setupOptions(bool $canManage = true): array
    {
        return [
            'accounts' => [['id' => 1]],
            'periods' => [['id' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']],
            'setup' => [
                'years' => [['id' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
                'accounts_url' => $canManage ? '/accounts?client_id=7' : null,
                'periods_url' => $canManage ? '/accounting-periods?client_id=7' : null,
            ],
        ];
    }

    private function checklist(array $options, string $date = '2026-10-23'): string
    {
        // Render synthetic data only; no hosted records, migrations, or seeders.
        return view('ledger.setup-checklist', [
            'record' => new LedgerEntry(['transaction_date' => $date]),
            'options' => $options,
        ])->render();
    }

    public function test_complete_setup_and_inclusive_period_boundaries(): void
    {
        foreach (['2026-10-01', '2026-10-23', '2026-10-31'] as $date) {
            $html = $this->checklist($this->setupOptions(), $date);
            $this->assertStringContainsString('3 of 3 complete', $html);
            $this->assertSame(3, substr_count($html, 'data-state="complete"'));
            $this->assertStringContainsString('href="/accounting-periods?client_id=7"', $html);
        }
    }

    public function test_existing_period_outside_transaction_date_is_not_ready(): void
    {
        $html = $this->checklist($this->setupOptions(), '2026-11-01');
        $this->assertStringContainsString('No open period covers this date', $html);
        $this->assertSame(2, substr_count($html, 'data-state="complete"'));

        $html = $this->checklist($this->setupOptions(), '2027-01-01');
        $this->assertStringContainsString('No year covers this date', $html);
        $this->assertSame(1, substr_count($html, 'data-state="complete"'));
    }

    public function test_missing_setup_directs_bookkeeper_to_authorized_staff(): void
    {
        $options = $this->setupOptions(false);
        $options['accounts'] = [];
        $options['periods'] = [];
        $options['setup']['years'] = [];
        $html = $this->checklist($options);
        $this->assertSame(3, substr_count($html, 'data-state="missing"'));
        $this->assertStringContainsString('Ask an Owner or Office Manager', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_no_client_is_pending_instead_of_missing(): void
    {
        $html = $this->checklist(['accounts' => [], 'periods' => []]);
        $this->assertSame(3, substr_count($html, 'data-state="pending"'));
        $this->assertStringContainsString('Select a client', $html);
        $this->assertStringNotContainsString('href=', $html);
    }
}
