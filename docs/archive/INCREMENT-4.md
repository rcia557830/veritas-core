# Increment 4 — Document Completeness and Missing-Document Detection

Increment 4 extends the Document Management module so RBCIA personnel can identify required, missing, incomplete, pending, and verified documents across clients without checking every client folder by hand. It directly addresses the document-processing problem described in Chapter 2 while remaining consistent with the Chapter 3 objectives and Scope and Limitations.

## 1. Features implemented

- **Standalone document requirements** — `DocumentRequirement` records expected documents that exist independently of any upload. Missing items are detected from configured requirements, never from placeholder uploads.
- **Requirement templates** — `DocumentRequirementTemplate` reusable definitions can be applied to a specific client to initialize explicit requirement rows.
- **Client-specific, period-aware requirements** — requirements belong to a client and may reference one of that client's accounting periods; due dates, required/optional classification, descriptions, and staff remarks are supported.
- **Activation / deactivation** — requirements can be activated or deactivated with full audit history.
- **Explicit document association** — authorized staff link an uploaded document to an expected requirement; a document satisfies at most one requirement, and a requirement may hold several candidate uploads (counted once).
- **Completeness calculation** — a reusable `DocumentCompleteness` service computes verified / total-required percentage, missing, awaiting, incomplete, and clarification counts. Zero configured required items renders **Not configured**, not 100%.
- **Client checklist** — a per-client checklist with submission and verification status, associated documents, remarks, completeness progress, and follow-up actions.
- **Consolidated monitoring** — an organization-wide "Missing Documents" page with client search, period/type/state/due-date filters, summary counts, pagination, and direct navigation to client records.
- **Internal follow-up tracking** — staff-managed follow-ups (status, responsible staff, date, remarks) per requirement. No automatic email or SMS is sent.
- **Dashboard integration** — dashboard counts (clients missing, outstanding required, awaiting, incomplete/clarification) come from the same completeness service.

## 2. Files created or modified

### Created

| File | Purpose |
| --- | --- |
| `database/migrations/2026_10_10_000008_create_document_requirements.php` | New tables (templates, requirements, links, follow-ups) |
| `app/Models/DocumentRequirement.php` | Expected-document model + relationships + client-change guard |
| `app/Models/DocumentRequirementTemplate.php` | Recurring template model |
| `app/Models/DocumentFollowUp.php` | Internal follow-up model |
| `app/Policies/RequirementPolicy.php` | Authorization (view/create/update/activate/link/follow-up/archive) |
| `app/Policies/RequirementTemplatePolicy.php` | Template management (owner/office-manager) |
| `app/Policies/FollowUpPolicy.php` | Follow-up authorization |
| `app/Services/DocumentCompleteness.php` | Completeness/state calculation service |
| `app/Http/Controllers/RequirementController.php` | Requirements CRUD, activation, linking, checklist |
| `app/Http/Controllers/RequirementTemplateController.php` | Template CRUD + apply-to-client |
| `app/Http/Controllers/FollowUpController.php` | Follow-up store/update/destroy |
| `app/Http/Controllers/MissingDocumentsController.php` | Organization-wide monitoring |
| `resources/views/requirements/index.blade.php` | Requirement management list |
| `resources/views/requirements/form.blade.php` | Requirement create/edit form |
| `resources/views/requirements/checklist.blade.php` | Per-client checklist |
| `resources/views/requirements/monitoring.blade.php` | Consolidated monitoring |
| `resources/views/requirements/templates.blade.php` | Template list/create/edit/apply |
| `resources/views/requirements/_followups.blade.php` | Follow-up list + add form |
| `tests/Feature/DocumentCompletenessTest.php` | Service-level completeness tests |
| `tests/Feature/DocumentRequirementTest.php` | HTTP feature/security/workflow tests |

### Modified

| File | Change |
| --- | --- |
| `app/Models/Document.php` | Added `requirements()` many-to-many relationship |
| `app/Models/Client.php` | Added `documentRequirements()` relationship |
| `config/rbac.php` | Added `requirement.*` permissions and role grants |
| `app/Support/Modules.php` | Mapped `DocumentRequirement` to the `requirement` permission prefix |
| `app/Services/Audit.php` | Included the `requirements` module in visible audit history |
| `app/Services/Summary.php` | Dashboard now includes completeness aggregate |
| `app/Providers/AppServiceProvider.php` | Registered the three new policies |
| `app/Support/Display.php` | Tone mapping for completeness state labels |
| `routes/web.php` | Added requirement/template/follow-up/monitoring routes |
| `resources/views/partials/sidebar.blade.php` | Added "Document Requirements" and "Missing Documents" navigation |
| `resources/views/dashboard/index.blade.php` | Added missing-document stats and quick action |
| `resources/views/clients/show.blade.php` | Added checklist progress section |


## 3. Database migrations

One additive migration (`2026_10_10_000008_create_document_requirements.php`) creates four tables:

- **`document_requirement_templates`** — `name`, `type`, `description`, `is_required`, `default_due_days`, timestamps, soft deletes.
- **`document_requirements`** — `client_id` (restrict), `template_id` (nullable, set-null), `name`, `type`, `description`, `accounting_period_id` (nullable, restrict), `is_required`, `is_active`, `due_date`, `remarks`, `created_by`, timestamps, soft deletes, and composite indexes for `(client_id, is_active, is_required)` and `(accounting_period_id, is_active)`.
- **`requirement_documents`** — `requirement_id` (cascade), `document_id` (restrict), `linked_by`, timestamps, with a unique `(requirement_id, document_id)` pair and a unique `document_id` (one upload → one requirement).
- **`document_follow_ups`** — `requirement_id` (cascade), `status`, `assigned_to` (nullable), `follow_up_date`, `remarks`, `created_by`, timestamps, soft deletes, and a `(requirement_id, status)` index.

No existing historical migrations were modified, no existing document rows are deleted, and no attachments are overwritten.

## 4. Requirement and completeness calculation methodology

**Requirement state.** Each active requirement is classified into exactly one state by `DocumentCompleteness::stateOf()`:

- **Not submitted** — no linked document.
- **Verified** — any linked document is `Approved` (accepted evidence satisfies the requirement).
- **Awaiting verification** — no `Approved` document; the most recent linked document is `Submitted`, `Under Review`, or `Reviewed`.
- **Incomplete** — the most recent linked document is `Rejected`.
- **Needs clarification** — the most recent linked document is `Needs Clarification`.

When several uploads are linked, `Approved` evidence wins; otherwise the latest link (highest pivot id) determines the state. A requirement is never counted more than once regardless of how many uploads are linked to it.

**Completeness percentage** (mandatory, per client):

```
Completeness (%) = (verified required items ÷ total applicable required items) × 100
```

- Only **active** and **required** requirements are counted in the denominator.
- **Optional** requirements are shown in the checklist but excluded from the mandatory percentage.
- **Inactive** requirements are excluded entirely.
- When zero required items are configured, `percentage` is `null` and the UI shows **Not configured**.

The calculation reads live document statuses, so unlinking an upload, reopening an approved document, or replacing evidence recalculates completeness automatically (no cached state).

## 5. Client and dashboard integration

- Each client profile (`clients/show.blade.php`) shows a completeness progress bar plus the full breakdown, with an "Open checklist" link.
- The per-client checklist (`requirements/checklist.blade.php`) lists every requirement with name, type, applicable period, due date, required/optional classification, submission status, verification status, associated documents, remarks, and follow-up actions.
- `Summary::dashboard()` now returns a `requirements` aggregate produced by `DocumentCompleteness::organization()`, and the dashboard renders "Clients missing documents", "Outstanding required", "Awaiting verification", and "Incomplete / clarification" cards. No hardcoded or fabricated numbers are used.

## 6. Follow-up functionality

Follow-ups are internal records on a requirement: status (`Open`, `In Progress`, `Resolved`), optional responsible staff member, optional follow-up date, and remarks. They are listed on the checklist with full history (add/remove). No email or SMS is sent automatically — the scope requires staff-managed follow-ups only.


## 7. Security and audit safeguards

- New `requirement.*` permissions (`view`, `create`, `update`, `activate`, `link`, `follow-up`, `archive`) are granted to Owner and Office Manager; Bookkeepers receive `view` and `follow-up` only.
- All requirement/template/follow-up endpoints are gated by policies; `Access::query` scopes requirements and clients by assignment for bookkeepers.
- Linking validates same client, single-requirement-per-document, and accounting-period matching (document `received_date` must fall within the requirement's period range). Cross-client and forged-ID requests are rejected.
- Requirements with linked documents cannot change clients (model guard).
- Existing approval-integrity protections are untouched: reviewed/approved attachments still cannot be silently replaced, journal evidence and posted entries remain immutable, and private storage/download authorization is preserved.
- Every create/update/activate/link/unlink/follow-up action writes an audit log entry under the `requirements` module (visible and client-scoped in the activity feed).

## 8. SQLite and MySQL test results

**SQLite `:memory:` (PHPUnit 11.5.56, PHP 8.4.25)**

| Run | Result |
| --- | --- |
| Focused completeness + requirement suites | 23 tests, 107 assertions — **passed** |
| Full regression | 218 tests, 1796 assertions, 13 MySQL-only skipped — **passed** |

**Disposable MySQL 8.4.3** (isolated data directory, loopback high port, random synthetic database)

| Run | Result |
| --- | --- |
| Focused Voucher suite | 21 tests, 234 assertions — **passed** |
| Full regression | 218 tests, 1897 assertions — **passed** |

## 9. Browser verification

The new views reuse the existing navy-and-gold design system and Blade components. All Blade templates compile cleanly (`php artisan view:cache`) and render server-side without error; the checklist and monitoring pages return HTTP 200 with the expected requirement names, statuses, and completeness text in the feature tests.

Dedicated desktop/mobile Chrome automation for the new requirement pages was not re-run in this session — the existing `tests/Browser/*.mjs` suite targets the accounting modules. Server-rendered output and responsive markup are validated by the HTTP feature tests; a dedicated browser pass (Chrome headless login → requirements → checklist → monitoring, plus a 390px mobile viewport) is recommended as a follow-up.

## 10. Remaining limitations and recommendations for Increment 5

- Accounting-period matching for linking uses the document's `received_date` within the requirement's period range (documents have no separate period column). Increment 5 could add an explicit `accounting_period_id` to documents if stricter period attribution is required.
- Templates initialize one requirement per client on demand; there is no bulk "apply to all clients" or automatic template synchronization yet.
- Completeness counts `Approved` documents as verified; the `Reviewed` state is treated as still awaiting final approval. If "Reviewed" should count as verified, that is a one-line mapping change.
- Follow-ups are internal only; no notification integration (deliberately out of scope).
- No automatic reminder generation for requirement due dates (manual follow-ups only).
