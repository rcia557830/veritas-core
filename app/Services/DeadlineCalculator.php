<?php

namespace App\Services;

use App\Models\ComplianceRecord;
use Illuminate\Support\Carbon;

/**
 * Reusable deadline calculation for the compliance module.
 *
 * Business rule: internal client document submission deadline is the official
 * filing deadline minus a configurable lead time. The lead time defaults to
 * 10 calendar days as a documented prototype assumption (see config/compliance.php).
 */
class DeadlineCalculator
{
    public const ON_TRACK = 'On Track';

    public const SUBMISSION_APPROACHING = 'Submission Deadline Approaching';

    public const SUBMISSION_OVERDUE = 'Submission Overdue';

    public const FILING_APPROACHING = 'Filing Deadline Approaching';

    public const FILING_OVERDUE = 'Filing Overdue';

    public const TERMINAL = ['Filed', 'Completed'];

    public static function leadDays(): int
    {
        return max(0, (int) config('compliance.submission_lead_days', 10));
    }

    public static function basis(): string
    {
        return config('compliance.submission_lead_basis', 'calendar') === 'business' ? 'business' : 'calendar';
    }

    public static function filingApproachDays(): int
    {
        return max(0, (int) config('compliance.filing_approach_days', 10));
    }

    public static function submissionApproachDays(): int
    {
        return max(0, (int) config('compliance.submission_approach_days', 10));
    }

    public static function today(?Carbon $today = null): Carbon
    {
        return ($today ?? Carbon::today('Asia/Manila'))->copy()->startOfDay();
    }

    /**
     * Client submission deadline derived from the official filing deadline.
     * Calendar basis subtracts lead days; business basis skips weekends.
     */
    public static function submissionDeadline(Carbon $filingDeadline, ?string $basis = null): Carbon
    {
        $basis ??= self::basis();
        $days = self::leadDays();

        return $basis === 'business'
            ? $filingDeadline->copy()->startOfDay()->subWeekdays($days)
            : $filingDeadline->copy()->startOfDay()->subDays($days);
    }

    public static function isTerminal(ComplianceRecord $record): bool
    {
        return in_array($record->status, self::TERMINAL, true);
    }

    public static function isFilingOverdue(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        if (self::isTerminal($record)) {
            return false;
        }
        $filing = $record->due_date?->copy()->startOfDay();

        return $filing && $filing->lt(self::today($today));
    }

    public static function isSubmissionOverdue(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        if (self::isTerminal($record)) {
            return false;
        }
        $submission = $record->submission_deadline;

        return $submission && $submission->lt(self::today($today));
    }

    public static function isFilingApproaching(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        if (self::isTerminal($record)) {
            return false;
        }
        $filing = $record->due_date?->copy()->startOfDay();
        $today = self::today($today);

        return $filing && $filing->gte($today) && $filing->lte($today->copy()->addDays(self::filingApproachDays()));
    }

    public static function isSubmissionApproaching(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        if (self::isTerminal($record)) {
            return false;
        }
        $submission = $record->submission_deadline;
        $today = self::today($today);

        return $submission && $submission->gte($today) && $submission->lte($today->copy()->addDays(self::submissionApproachDays()));
    }

    /**
     * Calculated deadline urgency, ordered by most pressing action.
     * Never stored; derived from the two deadlines relative to today.
     */
    public static function urgency(ComplianceRecord $record, ?Carbon $today = null): string
    {
        if ($record->status === 'Filed') {
            return 'Filed';
        }
        if ($record->status === 'Completed') {
            return 'Completed';
        }
        if (self::isFilingOverdue($record, $today)) {
            return self::FILING_OVERDUE;
        }
        if (self::isSubmissionOverdue($record, $today)) {
            return self::SUBMISSION_OVERDUE;
        }
        if (self::isFilingApproaching($record, $today)) {
            return self::FILING_APPROACHING;
        }
        if (self::isSubmissionApproaching($record, $today)) {
            return self::SUBMISSION_APPROACHING;
        }

        return self::ON_TRACK;
    }

    /**
     * Whether the record currently warrants an internal reminder notification.
     */
    public static function needsAlert(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        return in_array(self::urgency($record, $today), [
            self::SUBMISSION_APPROACHING, self::SUBMISSION_OVERDUE,
            self::FILING_APPROACHING, self::FILING_OVERDUE,
        ], true);
    }
}
