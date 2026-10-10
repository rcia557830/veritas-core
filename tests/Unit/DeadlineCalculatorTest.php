<?php

namespace Tests\Unit;

use App\Models\ComplianceRecord;
use App\Services\DeadlineCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeadlineCalculatorTest extends TestCase
{
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-10-10 09:00:00', 'Asia/Manila')->startOfDay();
    }

    private function record(string $dueDate, string $status = 'Pending', ?string $submission = null): ComplianceRecord
    {
        $record = new ComplianceRecord;
        $record->status = $status;
        $record->due_date = $dueDate;
        if ($submission !== null) {
            $record->submission_deadline = $submission;
        }

        return $record;
    }

    private function weekdaysAfter(Carbon $exclusiveStart, Carbon $inclusiveEnd): int
    {
        $count = 0;
        $cursor = $exclusiveStart->copy()->addDay();
        while ($cursor->lte($inclusiveEnd)) {
            if (! $cursor->isWeekend()) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    public function test_calendar_basis_subtracts_ten_calendar_days(): void
    {
        $filing = Carbon::parse('2026-10-15', 'Asia/Manila');
        $this->assertSame('2026-10-05', DeadlineCalculator::submissionDeadline($filing, 'calendar')->toDateString());
    }

    public function test_month_boundary(): void
    {
        $this->assertSame('2026-02-23', DeadlineCalculator::submissionDeadline(Carbon::parse('2026-03-05', 'Asia/Manila'), 'calendar')->toDateString());
    }

    public function test_leap_year_boundary(): void
    {
        $this->assertSame('2024-02-20', DeadlineCalculator::submissionDeadline(Carbon::parse('2024-03-01', 'Asia/Manila'), 'calendar')->toDateString());
    }

    public function test_year_boundary(): void
    {
        $this->assertSame('2025-12-26', DeadlineCalculator::submissionDeadline(Carbon::parse('2026-01-05', 'Asia/Manila'), 'calendar')->toDateString());
    }
    public function test_business_basis_skips_weekends(): void
    {
        $filing = Carbon::parse('2026-10-15', 'Asia/Manila'); // Thursday
        $submission = DeadlineCalculator::submissionDeadline($filing, 'business');
        $this->assertFalse($submission->isWeekend());
        $this->assertSame(10, $this->weekdaysAfter($submission, $filing));
    }

    public function test_business_basis_across_multiple_weekends(): void
    {
        $filing = Carbon::parse('2026-11-10', 'Asia/Manila'); // Tuesday
        $submission = DeadlineCalculator::submissionDeadline($filing, 'business');
        $this->assertFalse($submission->isWeekend());
        $this->assertSame(10, $this->weekdaysAfter($submission, $filing));
    }

    public function test_same_day_filing_is_not_filing_overdue(): void
    {
        $record = $this->record($this->today->toDateString());
        $this->assertTrue(DeadlineCalculator::isFilingApproaching($record, $this->today));
        $this->assertFalse(DeadlineCalculator::isFilingOverdue($record, $this->today));
        // The derived submission deadline (10 days prior) is already overdue.
        $this->assertTrue(DeadlineCalculator::isSubmissionOverdue($record, $this->today));
    }

    public function test_overdue_filing_takes_precedence(): void
    {
        $record = $this->record($this->today->copy()->subDay()->toDateString());
        $this->assertSame(DeadlineCalculator::FILING_OVERDUE, DeadlineCalculator::urgency($record, $this->today));
    }

    public function test_submission_overdue_when_filing_still_future(): void
    {
        $record = $this->record($this->today->copy()->addDays(5)->toDateString());
        $this->assertSame(DeadlineCalculator::SUBMISSION_OVERDUE, DeadlineCalculator::urgency($record, $this->today));
        $this->assertTrue(DeadlineCalculator::isSubmissionOverdue($record, $this->today));
    }

    public function test_submission_approaching_when_filing_further_out(): void
    {
        $record = $this->record($this->today->copy()->addDays(15)->toDateString());
        $this->assertSame(DeadlineCalculator::SUBMISSION_APPROACHING, DeadlineCalculator::urgency($record, $this->today));
        $this->assertTrue(DeadlineCalculator::isSubmissionApproaching($record, $this->today));
    }

    public function test_filing_approaching_when_submission_is_still_future(): void
    {
        $record = $this->record($this->today->copy()->addDays(3)->toDateString(), 'Pending', $this->today->copy()->addDays(2)->toDateString());
        $this->assertFalse(DeadlineCalculator::isSubmissionOverdue($record, $this->today));
        $this->assertSame(DeadlineCalculator::FILING_APPROACHING, DeadlineCalculator::urgency($record, $this->today));
    }

    public function test_on_track_when_both_deadlines_far_out(): void
    {
        $record = $this->record($this->today->copy()->addDays(30)->toDateString());
        $this->assertSame(DeadlineCalculator::ON_TRACK, DeadlineCalculator::urgency($record, $this->today));
        $this->assertFalse(DeadlineCalculator::needsAlert($record, $this->today));
    }

    public function test_filed_and_completed_are_terminal(): void
    {
        foreach (['Filed', 'Completed'] as $status) {
            $record = $this->record($this->today->copy()->subDay()->toDateString(), $status);
            $this->assertSame($status, DeadlineCalculator::urgency($record, $this->today));
            $this->assertFalse(DeadlineCalculator::needsAlert($record, $this->today));
        }
    }

    public function test_manual_submission_override_is_preserved_by_model_accessor(): void
    {
        $record = $this->record('2026-10-15', 'Pending', '2026-10-01');
        $this->assertSame('2026-10-01', $record->submission_deadline->toDateString());
        $this->assertFalse($record->submission_deadline_is_provisional);
    }

    public function test_derived_submission_deadline_is_provisional(): void
    {
        $record = $this->record('2026-10-15');
        $this->assertSame('2026-10-05', $record->submission_deadline->toDateString());
        $this->assertTrue($record->submission_deadline_is_provisional);
    }
}
