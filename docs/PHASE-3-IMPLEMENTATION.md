# Veritas Core V2 - Phase 3 implementation

Date: 2026-10-12 (Asia/Manila). Branch: `feature/veritas-v2`. Starting commit: `22cde71`; working tree initially clean.

## Scope and checkpoints

Reviewed the Phase 1 audit, Phase 2 preparation/implementation, existing accounting validation and regression reports, controllers, shared record writer/input rules, policies, accounting services, schema, views, and guarded verification harness. Existing modules remain in place. No sidebar redesign, audit redesign, new demo seeder, push, deployment, or real database migration/reset was performed.

1. **Compliance and periods:** deadline filters now run before the matching count/page slice; query parameters and stable ordering are retained, and the standard 10/25/50 page-size validation applies. Requirement updates validate the effective client/period and every linked document against the locked requirement. Period choices are client scoped, and the form flags incompatible selections. A fiscal calendar page is linked from Chart of Accounts.
2. **Clients and documents:** technical-only users are excluded from client assignment validation and form choices. Existing onboarding, archive/reactivation, related-record views and assignment isolation are retained. Duplicate document linking is safe to retry. Changing a linked document's client or received date cannot invalidate its requirement. New upload hashes detect repeated same-client/type/date attachments; matching legacy files without hashes are compared in bounded batches without changing them. Link retries preserve the original linking actor. Browser string IDs are normalized when checking effective ownership. Fixed the requirement remarks textarea, which was outside the form and could not be saved.
3. **Accounting:** reused `AccountingYear`, `AccountingPeriod`, `PeriodValidation`, `AccountingTransaction`, account-maintenance policies, existing journal review/posting, all four voucher types, General Ledger and financial reports. No calculation or posting-control replacement. The new period UI supports explicit year/period dates and labels, lists journal counts and closure metadata, and requires confirmation to close. Closure holds the same client lock as posting, blocks unresolved For Review/Reviewed journals, and retains posted history. There is no web reopening, date rewriting or deletion endpoint.
4. **Billing:** invoice totals continue to use integer cents and existing line rounding. A form submission UUID plus the locked invoice prevents repeated payment submission; reused invoice payment references are rejected. Legacy requests without a token/reference reject identical date/amount/method repeats. A fresh token permits separate legitimate equal cash payments. Overpayment remains rejected; full payment yields zero outstanding balance. Draft and Cancelled remain invoice lifecycle states; Open, Partially Paid, Paid and Overdue retain existing meanings.
5. **Compliance and knowledge:** retained ten-day internal targets and authorized overrides. Retained manual targets are revalidated against changed filing dates while holding the record lock. Filed dates/references survive subsequent lifecycle updates. Completed and Filed records are excluded from the ordinary overdue list, consistent with monitoring. Existing Knowledge Base draft/edit/publication/category/search/archive permissions are exercised; content and procedures remain stored in the original module.
6. **Regression and browser:** results below. Chrome now exercises actual upload - requirement link - review - positive onboarding, calendar creation, compliance target and follow-up writes, plus the existing journal/voucher/report/billing/knowledge journeys. A guarded post-browser PHP verifier reads persisted database records and checks the uploaded file hash.

## Authorization and accounting boundaries

Period read access reuses `AccountPolicy::viewAny` and authorized client lookup. Creation requires the existing Owner/Office Manager `account.create`, `account.view` and `client.view` gates. Closure additionally requires `account.update`. Bookkeepers can view assigned-client calendars but cannot manage them. Super Admin is denied the business routes. No permission rollout or role migration was added. These are explicit maintenance actions; the UI does not choose an accounting calendar on the firm's behalf.

Existing independent review, posting grants, evidence digests, voucher identities, composite ownership keys, accounting triggers, posted immutability, invitation/reset behavior, self-service passwords and 12-character password policy remain intact. Technical administration does not gain financial access. Financial audit events for calendar changes use the separate `accounting-periods` module label to avoid colliding with journal record IDs; the Owner's existing audit screen can retrieve them.

## Migration

`2026_10_12_000003_add_workflow_submission_guards.php` adds nullable payment `submission_token` with invoice/token uniqueness, and nullable document `file_sha256` with a client/hash index. Existing rows and attachments are retained. There is no financial data transformation or historical backfill. Automatic rollback is refused to preserve submission evidence; use a reviewed forward migration if needed. This migration was exercised only on disposable test databases. Applying it to the normal installation remains a deployment step outside this run.

## Verification results

The clean-start SQLite baseline passed: **331 passed, 17 skipped, 2,314 assertions**, zero failures. Baseline failures: none.

| Check | Result |
| --- | --- |
| First compliance/requirement/workflow checkpoint | 36 passed, 242 assertions |
| Client/document/billing/knowledge plus independent reconciliation checkpoint | 37 passed, 445 assertions |
| Accounting/security/journal/posting/voucher/ledger/report checkpoint | 97 passed, 709 assertions |
| Full SQLite regression | 340 passed, 17 skipped, 2,458 assertions, zero failures/errors |
| Disposable MySQL regression | 357 passed, 1 skipped, 2,580 assertions, zero failures — confirmed in two independent full runs |
| Guarded Chrome workflows and database verification | Passed business journeys and persisted-data checks in the final rerun |
| Phase 2 security browser regression | Passed invitations, login, self-service password change/reset, replay denial and four-role boundaries in the final rerun |
| Changed PHP Pint / whitespace checks | Passed |

Targeted runs overlap the full regression and must not be added to its totals. SQLite skips are the 17 MySQL-only concurrency tests. Evidence: ignored `storage/app/private/phase3-sqlite.xml`; MySQL JUnit: `storage/app/private/increment32-db276da3e2e345fab9ed39ba2ab5ddcb/regression.xml`. Final verified MySQL JUnit: `storage/app/private/increment32-c5b6f0a29ae6473a928f5323c102db20/regression.xml` (business) and `storage/app/private/increment32-7c81720da49b46629bd2e2a33a8c482f/regression.xml` (security). The voucher subset passed 22 tests / 235 assertions. The MySQL skip is the in-memory-SQLite backup case.

The new `PhaseThreeWorkflowTest` covers filtered multi-page totals and unique rows at three sizes, all four deadline filters, effective requirement ownership, inclusive document date boundaries, duplicate linking/upload/payment rejection, manual deadline revalidation, retained filing evidence, period confirmation/role/client restrictions, pending-review closure, posted-history retention, closed-period submission rejection, client assignment/archive/reactivation, Knowledge Base lifecycle, document clarification/rejection/approval/follow-up/download/missing-file behavior, and partial/full billing balances.

Module evidence from the full SQLite run (overlapping coverage, not extra totals):

| Area | Passed / assertions |
| --- | --- |
| DocumentCompleteness / Integrity / Requirement / Security suites | 36 / 235 |
| ComplianceDeadline / FollowUp / Monitoring / Notification suites | 24 / 148 |
| PhaseThreeWorkflowTest, including partial/full billing and Knowledge Base lifecycle | 9 / 144 |
| WorkspaceTest, including invoice item calculations, partial payments and PDF | 13 / 188 |
| EmployeeSecurity / AuthSecurity / RBAC / AccountingSecurity / AuditSecurity | 49 / 439 |
| IndependentAccountingReconciliationTest | 1 / 9 |

All listed suites have zero failures/errors. MySQL additionally passes the invitation/accounting concurrency cases skipped by SQLite.

Execution issue: the first browser launch failed at Chrome process creation with sandbox `spawn EPERM`, before browser assertions. The disposable runner cleaned up its server. The browser-only retry with process permission passed; no security assertion was removed or weakened. Business browser screenshots and evidence: `storage/app/private/increment32-b6ebf70660154a509d52f24e8660e8eb/`. Security browser evidence: `storage/app/private/increment32-aa58cb175a544404911df4eabaa6469b/`. Final review then added HTML string-ID, original-link-attribution, legacy-attachment duplicate, duplicate-reference and tokenless-payment regressions; the focused rerun passed 31 tests / 341 assertions. Final suites are rerun on that code. The interrupted final rerun left an empty regression XML and was not counted as a pass or failure; a fresh guarded run completed it. The final business verification (full MySQL regression plus the guarded Chrome business journey and post-browser persisted-data check) and the final security verification (full MySQL regression plus the Phase 2 security browser journey) both passed with exit code 0.

## Browser coverage

| Journey | Evidence from completed browser run |
| --- | --- |
| Client onboarding | Created client and completed onboarding after required evidence approval |
| Document upload/review | Uploaded real synthetic PDF bytes, linked requirement, approved, verified stored attachment hash |
| Journal preparation/review/posting | Bookkeeper preparation, independent Manager review/posting, correction invalidation and immutable posted view |
| Report update | All four voucher types flow into General Ledger and four financial reports with asserted totals |
| Invoice/payment | Created and issued PHP 100.00 invoice; PHP 25.00 payment persisted; PHP 75.00 balance verified |
| Compliance | Explicit synthetic December 31 deadline, December 21 internal target, saved follow-up and filtered monitoring |
| Knowledge Base | Published and retrieved stored article content |
| Security | Technical/business route separation, invitations, reset/password change and replay rejection |

Desktop/mobile checks and JavaScript-error assertions remain enabled. Synthetic dates are test inputs, not asserted government filing deadlines. Upload is performed by authorized staff for a client; no new client-login role or portal was introduced.

## Accounting reconciliation

The existing independent fixture was rerun without changing its expected values: Trial Balance debit/credit **PHP 1,380.00 each**; Cash **1,130.00**; Receivables **180.00**; Payables **180.00**; Capital **800.00**; April revenue **300.00**, expenses **70.00**, net income **230.00**; Balance Sheet assets and liabilities/equity **1,310.00 each**. Difference: **0.00**. General Ledger opening Cash **100.00**, movements **1,120.00 debit / 90.00 credit**, closing **1,130.00**. Unposted drafts, other clients and outside-period entries remain excluded. See `FINAL-ACCOUNTING-VALIDATION.md` for the unchanged synthetic transaction table. This is synthetic arithmetic validation, not reconciliation of the firm's actual books.

## Limits and Phase 4 handoff

- Normal `veritas_core_db` was not reset, seeded, migrated or used for tests. Browser fixtures and MySQL concurrency tests use the existing identity/token/datadir guard and a separate loopback server. SQLite and MySQL runs are sequential.
- Deadline filtering uses bounded batches and retains only the current page; it still scans authorized matching candidates to preserve legacy and business-day deadline semantics. A very large deployment may need an indexed, explicitly versioned deadline representation.
- Existing documents have nullable hashes; no historical attachment rewrite or bulk backfill is performed. Content duplicate checks match the same client, document type and received date, including available legacy files without stored hashes. They do not bulk-remove duplicates or repair missing historical files; missing files still produce a real 404 on download.
- Fiscal conventions, opening balances, reopening, reversals, formal closing entries, bank clearing and statutory statement certification remain outside the supported architecture. No automatic government filing or invented official deadlines is introduced.
- Compliance records and document requirements retain their existing separate checklists; no new automatic filing integration or direct compliance-to-document relationship is invented.
- Knowledge templates/procedures continue as existing content categories and requirement/account templates; no second Knowledge Base module is created.
- SMTP delivery, production HTTPS/proxy/cookie settings, deployment grants, offsite recovery and actual client-data reconciliation remain target-environment checks. This phase does not revoke the prior production readiness limitations.
- Financial Audit Trail storage remains the existing system, not append-only or tamper-proof. See `PHASE-4-HANDOFF.md`.

## Files changed

- `app/Http/Controllers/AccountingPeriodController.php`
- `app/Http/Controllers/ComplianceController.php`
- `app/Http/Controllers/ModuleController.php`
- `app/Http/Controllers/PaymentController.php`
- `app/Http/Controllers/RequirementController.php`
- `app/Http/Requests/RecordRequest.php`
- `app/Models/Document.php`
- `app/Models/Payment.php`
- `app/Services/RecordWriter.php`
- `app/Services/Records.php`
- `database/migrations/2026_10_12_000003_add_workflow_submission_guards.php`
- `docs/PHASE-3-IMPLEMENTATION.md`
- `docs/PHASE-4-HANDOFF.md`
- `resources/views/accounting-periods/index.blade.php`
- `resources/views/accounts/index.blade.php`
- `resources/views/billing/detail.blade.php`
- `resources/views/requirements/form.blade.php`
- `routes/web.php`
- `tests/Browser/onboarding.mjs`
- `tests/Browser/verify-workflows.php`
- `tests/Feature/PhaseThreeWorkflowTest.php`
- `tests/verify-accounting.ps1`
