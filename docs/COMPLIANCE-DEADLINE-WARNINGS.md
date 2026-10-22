# Compliance deadline warnings

Implemented October 21, 2026.

Submission and filing now have independent, compact badges on listing cards, detail pages, the client checklist, monitoring, dashboard deadline rows, and active deadline notifications. Agency tags and general workflow badges retain their styling. Card borders reflect the highest deadline severity.

| Meaning / labels | Accent | Background | Text |
| --- | --- | --- | --- |
| On Schedule / Completed | #16A34A | #DCFCE7 | #166534 |
| Submission Due Soon / Submission Due Today / Filing Due Soon / Filing Due Today | #EAB308 | #FEF9C3 | #854D0E |
| Submission Overdue / Filing Overdue | #DC2626 | #FEE2E2 | #991B1B |

## Rules

- Compare calendar dates in `config('app.timezone')`. Today is yellow, not overdue. Warning windows remain separately configurable (10 days by default), inclusive of their last day.
- Existing stored/manual submission deadlines still take precedence. Derived deadlines retain the configured calendar/business-day lead calculation. Rescheduling and manual-override validation are unchanged.
- `Ready for Filing` is used as the existing workflow indication that submission is complete. Its filing warning remains independent. Uploading documents does not change workflow status or complete the requirement.
- `Filed` and `Completed` continue to stop deadline alerts; their deadline badges read Completed, while their workflow statuses remain distinct.
- Soft-deleted requirements remain excluded by existing queries. The module has no Cancelled, Inactive, or Rescheduled status; no new statuses or database fields were introduced.
- Existing notification authorization, deduplication, history retention, and stale-alert filtering remain in place. Notification badges are calculated from the current accessible record.

## Changed files

- `app/Services/DeadlineCalculator.php`: warning labels, independent states, configured timezone, submission completion handling.
- `app/Models/ComplianceRecord.php`: configured timezone for stored submission dates.
- `app/Http/Controllers/NotificationController.php`: current independent warning data.
- `resources/views/components/compliance-warnings.blade.php`: shared warning component.
- `resources/views/components/stat.blade.php`: opt-in deadline palette for compliance statistics.
- `resources/views/records/index.blade.php`, `resources/views/records/show.blade.php`: compliance cards and details.
- `resources/views/compliance/checklist.blade.php`, `resources/views/compliance/monitor.blade.php`: checklist, monitoring and statistics.
- `resources/views/dashboard/index.blade.php`: deadline row badges.
- `public/styles.css`, `public/app.js`: scoped compact styling and notification rendering.
- `tests/Unit/DeadlineCalculatorTest.php`: date/window boundaries, timezone, terminal states and independent completion.
- `tests/Feature/ComplianceDeadlineTest.php`, `tests/Feature/ComplianceNotificationTest.php`: page rendering, current notification warnings and deleted-record exclusion.
- `tests/Browser/badges-labels.mjs`: preserve general badge assertions while allowing compact deadline badges.
- `tests/Browser/compliance-warnings.mjs`: isolated responsive production-CSS check without database access.

## Validation

- Targeted Laravel suites: **75 tests passed, 728 assertions** (`DeadlineCalculatorTest|Compliance|PhaseThreeWorkflowTest|WorkspaceTest|RoleDashboardTest`). Existing rescheduling, manual overrides, permissions and notification lifecycle tests passed.
- Headless Edge CSS check: **passed at 1440, 768, 390 and 320 pixels**. All eight labels have exact soft/text colors, fit on one line, and remain unclipped; general workflow badges retain their previous width.
- PHP formatting applied with Pint; JavaScript syntax and Git whitespace checks passed.

The responsive browser check isolates production CSS in representative cards; it is not a full authenticated browser workflow run. Actual Laravel pages were checked through HTTP feature tests. No migrations, live record changes, or full-suite run were performed.
