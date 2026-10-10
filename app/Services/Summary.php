<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

class Summary
{
    public static function billing(Builder $query): array
    {
        $totals = ['billed' => 0, 'collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'paid' => 0, 'open' => 0, 'count' => 0];
        (clone $query)->reorder()->with(['items', 'payments'])->chunkById(200, function ($invoices) use (&$totals) {
            foreach ($invoices as $i) {
                $totals['count']++;
                if (in_array($i->status, ['Draft', 'Cancelled'])) {
                    continue;
                }
                $totals['billed'] += $i->total_cents;
                $totals['collected'] += $i->paid_cents;
                $totals['outstanding'] += $i->balance_cents;
                if ($i->display_status === 'Paid') {
                    $totals['paid']++;
                } else {
                    $totals['open']++;
                }
                if ($i->display_status === 'Overdue') {
                    $totals['overdue'] += $i->balance_cents;
                }
            }
        });
        foreach (['billed', 'collected', 'outstanding', 'overdue'] as $key) {
            $totals[$key] = Money::decimal($totals[$key]);
        }

        return $totals;
    }

    /**
     * Dual-deadline compliance counts, scoped to the records the user may access.
     * Figures are computed in PHP (like DocumentCompleteness) so derived
     * submission deadlines for historical records are counted correctly.
     */
    public static function compliance(?User $user = null): array
    {
        $user ??= auth()->user();
        $totals = [
            'pending' => 0, 'submission_approaching' => 0, 'submission_overdue' => 0,
            'filing_approaching' => 0, 'filing_overdue' => 0, 'filed' => 0, 'completed' => 0,
        ];
        Access::query(ComplianceRecord::class, $user)->chunkById(200, function ($records) use (&$totals) {
            foreach ($records as $record) {
                if ($record->status === 'Filed') {
                    $totals['filed']++;
                    continue;
                }
                if ($record->status === 'Completed') {
                    $totals['completed']++;
                    continue;
                }
                $totals['pending']++;
                if (DeadlineCalculator::isSubmissionOverdue($record)) {
                    $totals['submission_overdue']++;
                } elseif (DeadlineCalculator::isSubmissionApproaching($record)) {
                    $totals['submission_approaching']++;
                }
                if (DeadlineCalculator::isFilingOverdue($record)) {
                    $totals['filing_overdue']++;
                } elseif (DeadlineCalculator::isFilingApproaching($record)) {
                    $totals['filing_approaching']++;
                }
            }
        });

        return $totals;
    }

    public static function dashboard(): array
    {
        $compliance = self::compliance();

        return [
            'clients' => Access::query(Client::class)->count(), 'active' => Access::query(Client::class)->where('status', 'Active')->count(),
            'new' => Access::query(Client::class)->where('created_at', '>=', now()->startOfMonth())->count(),
            'pending' => Access::query(Document::class)->where('status', 'Submitted')->count(),
            'review' => Access::query(Document::class)->where('status', 'Under Review')->count(),
            'clarification' => Access::query(Document::class)->where('status', 'Needs Clarification')->count(),
            'ledger' => Access::query(LedgerEntry::class)->where('status', 'For Review')->count(),
            'upcoming' => $compliance['filing_approaching'],
            'overdue' => $compliance['filing_overdue'],
            'compliance' => $compliance,
            'billing' => self::billing(Access::query(Invoice::class)),
            'requirements' => DocumentCompleteness::organization(),
        ];
    }
}
