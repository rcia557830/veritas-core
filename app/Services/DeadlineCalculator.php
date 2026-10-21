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
    public const ON_TRACK = 'On Schedule';

    public const SUBMISSION_APPROACHING = 'Submission Due Soon';

    public const SUBMISSION_OVERDUE = 'Submission Overdue';

    public const FILING_APPROACHING = 'Filing Due Soon';

    public const FILING_OVERDUE = 'Filing Overdue';

    public const SUBMISSION_TODAY = 'Submission Due Today';

    public const FILING_TODAY = 'Filing Due Today';

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
        return ($today ?? Carbon::now(config('app.timezone')))->copy()->setTimezone(config('app.timezone'))->startOfDay();
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
        return self::warnings($record, $today)['filing']['tone'] === 'danger';
    }

    public static function isSubmissionOverdue(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        return self::warnings($record, $today)['submission']['tone'] === 'danger';
    }

    public static function isFilingApproaching(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        return self::warnings($record, $today)['filing']['tone'] === 'warning';
    }

    public static function isSubmissionApproaching(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        return self::warnings($record, $today)['submission']['tone'] === 'warning';
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
            return $record->due_date->toDateString() === self::today($today)->toDateString() ? self::FILING_TODAY : self::FILING_APPROACHING;
        }
        if (self::isSubmissionApproaching($record, $today)) {
            return $record->submission_deadline->toDateString() === self::today($today)->toDateString() ? self::SUBMISSION_TODAY : self::SUBMISSION_APPROACHING;
        }

        return self::ON_TRACK;
    }

    /** Ready for Filing is the existing workflow signal that submission is complete. */
    public static function submissionComplete(ComplianceRecord $record): bool
    {
        return self::isTerminal($record) || $record->status === 'Ready for Filing';
    }

    /** Independent presentation states; these never change the workflow status. */
    public static function warnings(ComplianceRecord $record, ?Carbon $today = null): array
    {
        $today = self::today($today);
        $warnings = [];
        foreach (['submission' => 'Submission', 'filing' => 'Filing'] as $action => $label) {
            $complete = $action === 'submission' ? self::submissionComplete($record) : self::isTerminal($record);
            $deadline = $action === 'submission' ? $record->submission_deadline : $record->due_date;
            $window = $action === 'submission' ? self::submissionApproachDays() : self::filingApproachDays();
            $date = $deadline?->toDateString();
            $text = $complete ? 'Completed' : self::ON_TRACK;
            $tone = 'success';
            if (! $complete && $date !== null) {
                if ($date < $today->toDateString()) {
                    $text = $label.' Overdue';
                    $tone = 'danger';
                } elseif ($date <= $today->copy()->addDays($window)->toDateString()) {
                    $text = $label.($date === $today->toDateString() ? ' Due Today' : ' Due Soon');
                    $tone = 'warning';
                }
            }
            $warnings[$action] = ['label' => $text, 'tone' => $tone];
        }

        return $warnings;
    }

    public static function tone(ComplianceRecord $record): string
    {
        $tones = array_column(self::warnings($record), 'tone');

        return in_array('danger', $tones, true) ? 'danger' : (in_array('warning', $tones, true) ? 'warning' : 'success');
    }

    /**
     * Whether the record currently warrants an internal reminder notification.
     */
    public static function needsAlert(ComplianceRecord $record, ?Carbon $today = null): bool
    {
        return in_array(self::urgency($record, $today), [
            self::SUBMISSION_APPROACHING, self::SUBMISSION_TODAY, self::SUBMISSION_OVERDUE,
            self::FILING_APPROACHING, self::FILING_TODAY, self::FILING_OVERDUE,
        ], true);
    }
}
