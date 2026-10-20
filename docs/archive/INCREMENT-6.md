# Increment 6 — Client Onboarding, Registration Validation & Dashboard Improvements

Increment 6 improves the client registration and onboarding process and consolidates actionable information from the existing accounting, document, billing, and compliance modules into the staff dashboard. It reuses the Increment 1–5 architecture (private document storage, approval integrity, RBAC scoping, audit logging, soft deletion, and the dual-deadline compliance model) without rebuilding any module.

---

## 1. Existing system findings

Inspected before implementation:

- **Client record** (`clients`) already holds `client_code` (unique, auto-generated `CL-YYYY-RANDOM`), `business_name`, `business_type`, `contact_person`, `email`, `phone`, `tin`, `address`, `registration_status`, `business_license_status`, `status` (`Active`/`Inactive`/`Archived`), `created_by`, `assigned_to`, soft deletes. `client_code` is not user-editable and is protected by a unique index plus the generator.
- **CBL / COR** were already represented two ways — as client registration status fields (`registration_status` = COR, `business_license_status` = CBL; both `Pending`/`On file`) and as document types (`Certificate of Registration`, `Business Permit`) on `documents` plus Increment 4 `document_requirements`. There was **no dedicated CBL/COR table** (correct — none was needed).
- **Document verification** (`DocumentController::validateDocument` + `Document` status flow `Submitted → Under Review → Needs Clarification → Reviewed → Approved/Rejected`) with `document.approve/reject/validate` permissions and Increment 1 attachment-replacement safeguards. `DocumentCompleteness::stateOf()` already treats **`Approved` = verified**; a submitted-but-unapproved document is never verified.
- **Requirement management** (`DocumentRequirement`, templates, links, follow-ups, `DocumentCompleteness`, `MissingDocumentsController`, `RequirementController`).
- **Compliance** (`ComplianceRecord` dual-deadline, `DeadlineCalculator`, `Summary::compliance`, monitoring + checklist pages).
- **RBAC** — Owner / Office Manager / Bookkeeper with `client.*`, `document.*`, `requirement.*`, `compliance.*`, `bookkeeping.*`, `billing.*`; `Access` scopes bookkeepers to assigned clients.
- **Audit** — `AuditLog` + `Audit::record()`; generic create/update entries existed but **no field-level diff**.
- **Dashboard** — `Summary::dashboard()` already counted clients, active, documents, compliance, billing, and requirements.
- **Validation** — `RecordRequest::rules()` already validated `email` (email), `phone` (max 60), `tin` (regex `[0-9 -]{9,20}`), and required `business_name`/`business_type`/`registration_status`/`business_license_status`.

### Gaps

1. No separation of **onboarding** requirements (CBL/COR/registration) from **accounting-period** requirements; `DocumentCompleteness::forClient()` lumped all active+required requirements together, so periodic documents would wrongly drive onboarding.
2. No readiness calculation or stored activation record distinct from `status`.
3. No onboarding checklist page or activation control.
4. Weak phone/TIN validation; no registration-progress or incomplete-record indicator.
5. No field-level audit on client edits.
6. Dashboard missing onboarding metrics, accounting workflow counts, and billing overdue counts; several metric cards lacked navigation targets.
7. No tests for any of the above.

---

## 2. New functionality

1. **Onboarding requirement scope** — additive `scope` column on `document_requirements` (`onboarding` | `periodic`); existing rows backfilled non-destructively.
2. **Centralized `OnboardingReadiness` service** — derived (never stored) state shared by the client profile, checklist, list, and dashboard.
3. **Onboarding checklist** — per-client page (`GET /clients/{client}/onboarding`) showing profile info, assigned staff, CBL/COR + other onboarding requirements, submission/verification status, missing items, reviewer/date, registration progress, and the activation control.
4. **Onboarding list** — organization-wide overview (`GET /clients/onboarding`) with readiness buckets and a state filter.
5. **Client activation** — `POST /clients/{client}/onboarding/complete` with verification integrity and a justified exemption path; stored via `onboarded_at`/`onboarded_by`.
6. **Client registration improvements** — tightened phone/TIN validation, field-level audit diff, registration-progress display, incomplete-record identification.
7. **Dashboard improvements** — onboarding, accounting, and billing metric cards with actionable navigation links.

---

## 3. Onboarding business rules

Onboarding requirements are active `document_requirements` whose `scope = 'onboarding'` and `is_required = true`. Only these contribute to readiness; periodic (accounting-period) requirements are excluded.

Calculated readiness states, in precedence order:

| State | Rule |
| --- | --- |
| `onboarded` | `clients.onboarded_at` is set |
| `not_configured` | zero required onboarding requirements |
| `needs_clarification` | any required requirement is in clarification |
| `ready_for_activation` | all required requirements verified (`Approved`) |
| `awaiting_verification` | all required requirements submitted, none missing/incomplete/clarification/verified |
| `not_started` | all required requirements missing (nothing submitted) |
| `in_progress` | mixed (some submitted, some missing, or a rejected document) |

A submitted-but-unapproved CBL/COR never satisfies a verified requirement. Clients with no configured onboarding requirements are shown as **Not configured**, never as complete. The state is calculated (never stored) so the profile, checklist, list, and dashboard always agree; the stored `onboarded_at` records activation without reinterpreting the client's operational `status`.

---

## 4. CBL / COR verification process

- CBL = City/Mayor's Business License → document/requirement type **`Business Permit`**.
- COR = Certificate of Registration → document/requirement type **`Certificate of Registration`**.
- Verification reuses the existing document workflow; a requirement is verified only when its linked document is `Approved`. Submission (`Submitted`/`Under Review`/`Reviewed`), `Needs Clarification`, and `Rejected` are all non-verified.
- Reviewing staff and verification date are derived from the latest `document.validated` audit entry (description containing `Approved`) for the approved document.
- No new CBL/COR storage tables were created; Increment 1 approved-document replacement safeguards are untouched.

---

## 5. Client activation behavior

`POST /clients/{client}/onboarding/complete` (permission `client.activate`, Owner + Office Manager):

1. Lock the client row and recompute readiness.
2. `ready_for_activation` → set `onboarded_at`/`onboarded_by`, audit `onboarding.completed`.
3. `not_configured` → allowed **only** with an `exemption_reason` (authorized + audited) — honors "not every client requires CBL/COR".
4. Any other state → rejected with the outstanding mandatory requirements listed; verification cannot be bypassed.

Activation is kept distinct from:

- **Client record status** (`clients.status` — unchanged).
- **Operational access** — no accounting/billing/document access is blocked or deactivated based on onboarding; only readiness warnings are shown. Existing clients are never retroactively deactivated.

---

## 6. Database modifications

Single additive migration `database/migrations/2026_10_10_000010_add_onboarding.php`:

- `document_requirements.scope` — nullable string, indexed. Backfill: `accounting_period_id IS NULL → onboarding`, else `periodic`.
- `clients.onboarded_at` — nullable timestamp, indexed.
- `clients.onboarded_by` — nullable FK → `users` (`restrictOnDelete`).

`down()` reverses both (indexes dropped before columns for SQLite). No historical migrations were edited, no records deleted, no posted accounting data touched.

---

## 7. Dashboard integrations

`Summary::dashboard()` now also returns:

- `onboarding` — buckets from `OnboardingReadiness::organization()` (`onboarded`, `not_configured`, `not_started`, `in_progress`, `awaiting_verification`, `needs_clarification`, `ready_for_activation`, `clients_missing`, `pending`).
- `ledger_draft`, `ledger_reviewed`, `ledger_posted` — real `LedgerEntry.status` counts (`Draft`/`Reviewed`/`Posted`), alongside the existing `ledger` (`For Review`).
- `billing['overdue_count']` — added to `Summary::billing()`.

All metrics come from real permission-scoped queries (`Access::query`); no fabricated counts and no inaccurate monetary aggregation.

Dashboard navigation links added: pending onboarding / ready for activation / clients missing documents → `clients.onboarding` (filtered), journal review queue → `ledger.index?status=For Review`, reviewed awaiting posting → `ledger.index?status=Reviewed`, overdue invoices → `billing.index?status=Overdue`.

---

## 8. Security safeguards

- New `client.activate` permission granted to Owner and Office Manager only (Owner inherits all).
- `ClientPolicy::activate()` reuses `view()` (assignment-scoped) + permission check.
- Activation uses `Client::lockForUpdate()` + `Gate::authorize()`; onboarding checklist is gated by `client.view`.
- Cross-client and forged-ID access rejected (tested); bookkeepers cannot view/activate other clients' onboarding.
- Onboarding completion and client field changes are attributed to the authenticated staff user via `AuditLog` (`onboarding.completed`, `client.updated`).
- Private document storage, approval integrity, soft deletion, accounting posting controls, and compliance notification safeguards are untouched.

---

## 9. Changed files

### Created

| File | Purpose |
| --- | --- |
| `database/migrations/2026_10_10_000010_add_onboarding.php` | `scope` + `onboarded_at`/`onboarded_by` |
| `config/onboarding.php` | CBL/COR type mapping + scope values |
| `app/Services/OnboardingReadiness.php` | Centralized readiness/activation service |
| `resources/views/clients/onboarding.blade.php` | Onboarding overview list |
| `resources/views/clients/onboarding-checklist.blade.php` | Per-client checklist |
| `tests/Feature/OnboardingReadinessTest.php` | Readiness/CBL-COR/backward-compat tests |
| `tests/Feature/OnboardingActivationTest.php` | Activation/authorization/audit tests |
| `tests/Feature/OnboardingDashboardTest.php` | Dashboard/buckets/navigation tests |
| `tests/Feature/ClientRegistrationTest.php` | Registration validation/assignment tests |

### Modified

| File | Change |
| --- | --- |
| `app/Models/Client.php` | `onboarded_at`/`onboarded_by` fillable/cast/relationship |
| `app/Models/DocumentRequirement.php` | `scope` fillable + creating-scope default |
| `app/Http/Controllers/ClientController.php` | `onboarding`, `onboardingChecklist`, `activate` |
| `app/Http/Controllers/RequirementController.php` | `scope` validation |
| `app/Http/Requests/RecordRequest.php` | phone/TIN validation |
| `app/Policies/ClientPolicy.php` | `activate()` |
| `app/Services/RecordWriter.php` | client field-level audit diff |
| `app/Services/Summary.php` | onboarding + accounting + billing-overdue metrics |
| `app/Support/Display.php` | onboarding state badge tones |
| `config/rbac.php` | `client.activate` permission + grant |
| `routes/web.php` | onboarding routes |
| `resources/views/clients/show.blade.php` | onboarding/registration/CBL-COR summary |
| `resources/views/dashboard/index.blade.php` | onboarding/accounting/billing cards + links |
| `resources/views/partials/sidebar.blade.php` | Client Onboarding nav item |
| `resources/views/requirements/checklist.blade.php` | scope badge |
| `resources/views/requirements/form.blade.php` | scope selector |
| `tests/Feature/AccountingMigrationTest.php` | rollback step count adjusted for the new migration |

---

## 10. SQLite test results

PHPUnit 11.5.56, PHP 8.4.25, SQLite `:memory:` with foreign keys.

| Run | Result |
| --- | --- |
| Focused onboarding/registration suites (4 files) | **45 tests passed, 0 failed** |
| Full regression | **276 passed, 13 skipped (MySQL-only concurrency), 0 failed, 1984 assertions** |

Blade templates compile (`php artisan view:cache`) and all routes register (`php artisan route:list`).

---

## 11. MySQL test results

Not re-run in this session. The disposable MySQL harness (`tests/verify-accounting.ps1`) requires a disposable MySQL 8.4 server with identity verification. The additive migration uses only standard, SQLite- and MySQL-compatible schema operations (nullable column + FK + indexes), and the full SQLite regression suite (including `AccountingMigrationTest`, which exercises migration `up()`/`down()` round-trips) passes. A disposable MySQL pass is recommended as a follow-up.

---

## 12. Browser testing evidence

- All Blade templates compile and render server-side; the dashboard, onboarding list, onboarding checklist, and client profile pages return HTTP 200 in the feature tests (`OnboardingDashboardTest`, `OnboardingActivationTest`, existing `WorkspaceTest`).
- A dedicated headless-Chrome desktop/mobile interaction pass (login → Onboarding list → Client checklist → activation, plus a 390px viewport) was **not** run in this session. The existing `tests/Browser/*.mjs` harness targets the accounting modules. This mirrors the outstanding browser-verification note left in Increments 4 and 5.

---

## 13. Known limitations

- The onboarding state model and the "no CBL/COR required" exemption are prototype assumptions pending RBCIA confirmation of which documents are universally required.
- CBL/COR identification relies on document type (`Business Permit` / `Certificate of Registration`); ad-hoc naming variants are not auto-detected.
- Reviewing-staff/date for CBL/COR are derived from the document approval audit entry, not stored directly on the requirement.
- Scope is chosen manually on the requirement form (defaults: onboarding when no accounting period is set, periodic otherwise); templates apply with an onboarding default that staff can edit.
- No external notifications for onboarding; dashboard metrics are computed per request (no stale caching).
- Disposable MySQL and desktop/mobile browser passes remain follow-ups.

---

## 14. Recommendations for Increment 7

- Confirm RBCIA's CBL/COR applicability rules and replace the exemption assumption with a documented policy toggle.
- Bulk onboarding template application ("apply CBL/COR to all new clients").
- Store reviewer/verified-at directly on `document_requirements` if independent verification metadata must be first-class.
- Dedicated headless-Chrome desktop/mobile automation for onboarding, document (Increment 4), and compliance (Increment 5) pages.
- Disposable MySQL regression pass.
