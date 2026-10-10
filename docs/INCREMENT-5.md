# Increment 5 — Compliance Monitoring and Deadline Automation

Increment 5 strengthens the existing Compliance Management module with dual-deadline tracking, a configurable advance submission-deadline rule, a calculated urgency/lifecycle model, an organization-wide monitoring dashboard, a per-client compliance checklist, staff-managed follow-ups, and dual-deadline internal notifications. It directly addresses the third Statement of the Problem in the Chapters 1–3 documentation without rebuilding existing modules or redesigning the application.

---

## 1. Existing compliance system findings

Inspected before implementation:

- **Model** — `ComplianceRecord` held a single `due_date`, a `status` enum (`Pending`, `In Preparation`, `Ready for Filing`, `Filed`), `filed_date`, `reference_number`, `notes`, `assigned_to`, `created_by`, with soft deletes.
- **Controller** — `ComplianceController` was an empty subclass of the generic `ModuleController`; all CRUD flowed through `RecordWriter` / `RecordInput` / `RecordRequest`.
- **Urgency** — a single-deadline accessor returned `Filed / Overdue / Due Today / Due Soon / Upcoming`; `display_status` conflated stored status with a date-derived `Overdue`.
- **RBAC** — three roles (Owner, Office Manager, Bookkeeper) with `compliance.view/create/update/assign/file/archive`; bookkeepers were already restricted to status + notes only and could not file.
- **Notifications** — `Notify::due()` generated filing-deadline reminders (10-day window), deduplicated via `notification_events`, with `veritas:notify` scheduled `dailyAt('07:00')`.
- **Dashboard** — `Summary::dashboard()` counted filing `upcoming`/`overdue` only.
- **Follow-ups** — only `DocumentFollowUp` existed (Increment 4); none for compliance.
- **Document completeness** — `DocumentCompleteness` service existed and is reused unchanged.

Gaps confirmed: no dual-deadline concept, no submission-deadline calculation, no manual-override justification, too-coarse lifecycle, no monitoring dashboard, no compliance checklist on client profiles, no compliance follow-ups, and single-deadline notifications.

---

## 2. New features

1. **Dual deadline management** — official filing `due_date` preserved verbatim; additive `submission_deadline` stored for new/edited records and derived on read for historical records (no destructive backfill).
2. **Manual deadline overrides** — justified (`submission_deadline_override_reason`) and flagged (`submission_deadline_is_manual`); contradictory dates rejected.
3. **10-day submission rule** — reusable `DeadlineCalculator` service (calendar and business/working-day basis).
4. **Lifecycle** — extended stored statuses (`Awaiting Client Documents`, terminal `Completed`) and a separate calculated `urgency` indicator.
5. **Monitoring dashboard** — summary cards, dual-deadline table, search + agency/period/status/client/staff/deadline filters, pagination.
6. **Client checklist** — per-client compliance view with document-completeness context.
7. **Follow-ups** — `ComplianceFollowUp` model with status, assignee, date, remarks, `completed_at`, history.
8. **Notifications** — dual-deadline internal reminders plus deadline-change notifications.
---

## 3. Modified and created files

### Created

| File | Purpose |
| --- | --- |
| `database/migrations/2026_10_10_000009_extend_compliance_dual_deadlines.php` | Additive deadline columns + `compliance_follow_ups` table |
| `config/compliance.php` | Deadline policy (lead days, basis, approach windows) |
| `app/Services/DeadlineCalculator.php` | Reusable submission-deadline + urgency calculation |
| `app/Models/ComplianceFollowUp.php` | Internal compliance follow-up model |
| `app/Policies/ComplianceFollowUpPolicy.php` | Follow-up authorization |
| `resources/views/compliance/monitor.blade.php` | Organization-wide monitoring dashboard |
| `resources/views/compliance/checklist.blade.php` | Per-client compliance checklist |
| `resources/views/compliance/_followups.blade.php` | Follow-up partial |
| `tests/Unit/DeadlineCalculatorTest.php` | 15 calculation/urgency unit tests |
| `tests/Feature/ComplianceDeadlineTest.php` | Dual-deadline/override/lifecycle HTTP tests |
| `tests/Feature/ComplianceMonitoringTest.php` | Dashboard/counts/isolation/permissions tests |
| `tests/Feature/ComplianceFollowUpTest.php` | Follow-up CRUD tests |

### Modified

| File | Change |
| --- | --- |
| `app/Models/ComplianceRecord.php` | New fillable/casts, `submission_deadline` + `submission_deadline_is_provisional` accessors, `followUps()`, `display_status` = stored status, `urgency` = calculated indicator |
| `app/Http/Controllers/ComplianceController.php` | `monitor()`, `checklist()`, follow-up actions |
| `app/Http/Controllers/ModuleController.php` | Bookkeeper status options include `Awaiting Client Documents` |
| `app/Http/Requests/RecordRequest.php` | Deadline-field validation + bookkeeper status rule |
| `app/Services/RecordInput.php` | Bookkeepers blocked from all deadline fields |
| `app/Services/RecordWriter.php` | `prepareComplianceDeadlines()` + deadline-change audit/notification |
| `app/Services/Notify.php` | Dual-deadline `due()` + `isCurrent()` + `deadlineKey()` |
| `app/Services/Summary.php` | `Summary::compliance()` dual-deadline counts |
| `app/Support/Display.php` | Tones for new statuses/urgency labels |
| `app/Support/Modules.php` | Compliance fields/columns/statuses |
| `app/Providers/AppServiceProvider.php` | Register `ComplianceFollowUpPolicy` |
| `config/rbac.php` | New `compliance.follow-up` permission + grants |
| `routes/web.php` | Monitoring, checklist, follow-up routes |
| `resources/views/records/*`, `clients/show`, `dashboard/index`, `partials/sidebar` | Dual-deadline UI + navigation |
| `tests/Feature/ComplianceNotificationTest.php` | Updated to new urgency labels (intent preserved) |
| `tests/Feature/WorkspaceTest.php` | `display_status`/`urgency` assertion updated |
| `tests/Feature/AccountingMigrationTest.php` | Rollback step count `7 → 8` for the new migration |
---

## 4. Database schema changes

Additive migration only (`2026_10_10_000009_extend_compliance_dual_deadlines.php`):

- `compliance_records.submission_deadline` — `date, nullable, indexed`
- `compliance_records.submission_deadline_is_manual` — `boolean, default false`
- `compliance_records.submission_deadline_override_reason` — `text, nullable`
- New table `compliance_follow_ups` — `id`, `compliance_record_id` (FK cascade), `status` (indexed), `assigned_to` (nullable FK), `follow_up_date` (nullable, indexed), `remarks`, `completed_at` (nullable timestamp), `created_by` (FK), timestamps, soft deletes, `(compliance_record_id, status)` index.

No historical migrations were modified; no rows deleted; no `migrate:fresh` was run on the development database. Historical rows with `NULL submission_deadline` resolve their deadline through the model accessor (derived, marked provisional) rather than a data-mutating backfill.

---

## 5. Ten-day calculation methodology

`DeadlineCalculator::submissionDeadline(Carbon $filingDeadline)`:

- **Calendar basis (default):** `filingDeadline->subDays(leadDays)`.
- **Business basis:** `filingDeadline->subWeekdays(leadDays)` — Saturdays and Sundays are skipped; **no Philippine public-holiday calendar is applied** unless a verified holiday calendar and explicit policy are supplied.
- Date interpretation uses `Asia/Manila` (the application timezone).
- Unit tests cover month boundaries, leap years, year boundaries, weekends, same-day deadlines, and overdue calculation.

---

## 6. Configurable policy assumptions

`config/compliance.php` (environment-overridable):

| Key | Default | Notes |
| --- | --- | --- |
| `submission_lead_days` | `10` | **Prototype assumption** pending RBCIA confirmation |
| `submission_lead_basis` | `calendar` | `calendar` or `business` (weekends only) |
| `filing_approach_days` | `10` | Filing "approaching" window |
| `submission_approach_days` | `10` | Submission "approaching" window |

The calendar-day default is explicitly documented as provisional; the research documentation does not establish whether RBCIA means calendar or working days.
---

## 7. Compliance lifecycle behavior

**Stored workflow statuses** (never conflated with date urgency):

`Pending`, `In Preparation` (≈ In Progress), `Awaiting Client Documents`, `Ready for Filing`, `Filed`, `Completed`.

**Calculated urgency** (derived, never stored):

`On Track`, `Submission Deadline Approaching`, `Submission Overdue`, `Filing Deadline Approaching`, `Filing Overdue` (precedence: filing overdue → submission overdue → filing approaching → submission approaching → on track).

- `Filed` and `Completed` are terminal; they suppress all approaching/overdue output.
- Uploading or verifying documents never auto-marks a requirement `Filed` or `Completed`.
- Filing requires `compliance.file` permission plus `filed_date` and `reference_number`; `Completed` is set by Owner/Office Manager for non-filing obligations.
- Bookkeepers may move status between `Pending`, `In Preparation`, `Awaiting Client Documents`, `Ready for Filing`, and update notes only.

---

## 8. Document integration

- The client checklist and monitoring pages surface `DocumentCompleteness::forClient()` context (verified / missing / awaiting).
- Document requirements and compliance records remain independent; no compliance↔document hard-links are inferred or created.
- Document verification is kept separate from regulatory filing confirmation; verified documents do not auto-complete compliance.
- No cross-client document associations are permitted (existing Increment 4 guards unchanged).

---

## 9. Notifications and scheduler behavior

- `Notify::due()` now emits **one active reminder per record**, titled `"{urgency}: {requirement}"`, whose urgency covers all four alert categories (submission approaching/overdue, filing approaching/overdue).
- Deduplication keys include both `submission_deadline` and `due_date` snapshots plus urgency, so rescheduling replaces stale alerts instead of stacking duplicates.
- `Notify::isCurrent()` hides alerts for `Filed`/`Completed` records and stale date snapshots.
- Deadline changes write an audit entry and trigger a `Compliance deadline changed:` notification.
- `veritas:notify` remains idempotent (`withoutOverlapping()` + `notification_events` deduplication). **The Laravel scheduler must be running in production** (`php artisan schedule:run` every minute) for reminders to be generated automatically; no claim of continuous automation is made beyond that configuration.
- No external email, SMS, or client messaging is sent.
---

## 10. Security and audit controls

- Owner, Office Manager, and Bookkeeper roles and all existing `compliance.*` permissions are preserved; a new `compliance.follow-up` permission is granted to all three roles (bookkeeper scoped to assigned clients).
- `Access::query` scoping is unchanged — bookkeepers only see their assigned clients; monitoring and checklist endpoints reuse it.
- `RecordInput`/`RecordWriter` enforce: bookkeepers cannot alter `due_date`, `submission_deadline`, override reason, `filed_date`, `reference_number`, or `assigned_to`; filing requires `compliance.file`; assignment requires the assignee be the client's bookkeeper or an Owner/Office Manager; submission deadlines must be on or before the filing deadline and overrides require a reason.
- All deadline changes are attributed to the authenticated user via `compliance.deadline-changed` audit entries; follow-ups write `follow-up.*` audit entries.
- Tests assert cross-client access, forged assignments, unauthorized filing, and cross-user notification access are rejected.

---

## 11. SQLite test results

PHPUnit 11.5.56, PHP 8.4.25, SQLite `:memory:` with foreign keys:

| Run | Result |
| --- | --- |
| `DeadlineCalculatorTest` (focused unit) | 15 passed, 28 assertions |
| Compliance feature suites (`ComplianceDeadlineTest`, `ComplianceMonitoringTest`, `ComplianceFollowUpTest`, `ComplianceNotificationTest`) | 24 passed |
| Full regression | **238 passed, 13 skipped (MySQL-only concurrency), 0 failed, 1893 assertions** |

Blade templates compile cleanly (`php artisan view:cache`), and all routes register.

---

## 12. MySQL test results

Disposable MySQL 8.4.3 (isolated data directory, loopback high port, random `veritas_stage21_*` database, guarded identity verification via `tests/verify-accounting.ps1 -SkipBrowser`):

| Run | Result |
| --- | --- |
| Focused Voucher suite | 21 passed, 234 assertions |
| Full regression | **251 passed, 0 skipped, 0 failed, 1994 assertions** |

The 13 SQLite-skipped concurrency tests pass on MySQL. The disposable server was shut down and its artifacts retained under `storage/app/private/increment32-<token>/`.
---

## 13. Desktop/mobile verification results

- All Blade templates compile (`php artisan view:cache` succeeded).
- HTTP feature tests render the new pages server-side and assert expected content (`/compliance/monitoring` and `/clients/{client}/compliance` return HTTP 200 with requirement names, summary counts, and urgency/status badges for Owner, Office Manager, and Bookkeeper).
- Responsive markup reuses the existing Bootstrap 5 grid (`table-responsive`, `row g-3`, `col-md-*`) consistent with the rest of the workspace.
- **A dedicated headless-Chrome desktop/mobile interaction pass for the new compliance pages was not run in this session.** The existing `tests/Browser/*.mjs` harness targets the accounting modules. A Chrome pass (login → Compliance Monitoring → Client Checklist → follow-ups, plus a 390px mobile viewport) is recommended as a follow-up, mirroring the note left for Increment 4's document pages.

---

## 14. Remaining limitations

- The 10-calendar-day rule is a provisional prototype assumption pending RBCIA confirmation of calendar vs. working days and any holiday-calendar policy.
- Philippine public holidays are not excluded from working-day calculations (no verified holiday calendar was supplied).
- A single urgency indicator is shown per record; the four independent deadline categories are still counted separately in `Summary::compliance()` and on the monitoring dashboard.
- Submission-deadline materialization on first edit of a historical record does not count as a "deadline change" (effective dates are compared), so no spurious notifications/audit entries are created.
- No external notifications; reminders are internal workspace notifications only and require the production scheduler.

---

## 15. Recommendations for Increment 6

- Add a verified Philippine holiday calendar and a policy toggle for holiday-aware working-day calculations.
- Add explicit compliance↔document-requirement links (as a safe additive pivot) if the business requires document completeness to drive filing readiness.
- Bulk template application ("apply to all clients") for recurring government obligations.
- Dedicated headless-Chrome desktop/mobile browser automation for compliance (and Increment 4 document) pages.
- Configurable per-agency lead times rather than a single global 10-day default.





