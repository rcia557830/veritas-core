# Veritas Core V2 — final validation and release readiness

Audit date: 22 October 2026, Asia/Manila. Scope: independently validate the inherited V2 implementation, repair demonstrated defects, and prepare release/defense evidence. No commit, push, deployment, hosted migration, seed, account change, storage migration or paid service was performed.

**Release decision: NO-GO for operational replacement of GLS on the current hosting configuration.** Local workflow capability is distinct from safe hosted operation. Uploaded files are configured on ephemeral storage; the secure hosted database preflight fails; DEV trigger restrictions conflict with migrations; a current hosted backup/restore rehearsal and fresh Docker build are unverified. These are not minor cosmetic conditions. No production architecture or permission change was made to bypass them.

This report contains full independent browser and regression evidence finalized on 22 October 2026. All automated and browser suites have completed verification.

## 1. Git state and preservation

At entry, `feature/veritas-v2` was at `72c0fff`, equal to the local `origin/feature/veritas-v2` reference; no staged files or local commits ahead of that reference. This is a local reference comparison, not a fresh remote fetch or deployed-SHA verification. The required status, branch, ten-commit log, tracked/staged diffs, whitespace and ahead-of-origin checks were run before edits. There were 34 modified tracked files and 52 modified/untracked paths altogether. Existing changes, including deployment scripts and V2 reports, were retained.

An ignored SHA-256 entry manifest, `storage/app/private/phase5-entry-manifest.json`, separates inherited work from this audit's edits. No reset, clean, checkout, merge or history rewrite was used. Uncommitted V2 work must not be described as deployed. Final changed-file inventory appears in section 14.

## 2. Verified implementation and defects

The existing Laravel architecture remains intact. Documents combines intake, review, missing requirements, client context and follow-ups. Accounting keeps journals, vouchers, ledger, reports, accounts and periods together without removing independent review. Compliance has shared deadline views and inline follow-ups. Supporting modules retain client onboarding, itemized billing, payment history, knowledge publication/search, four role dashboards, employee invitations and audit records.

| Finding | Severity | Repair / evidence |
|---|---|---|
| Invoice banner tested stored `status` for Paid/Overdue, although those states are derived and stored status stays Open | Medium | Banner now uses `display_status`. Regression creates partial payment, overdue balance and settlement through the payment endpoint; verifies correct banner and disappearance of payment action. |
| Client hub claimed statutory documents were verified when the only linked evidence was Rejected or Needs Clarification | Medium | Explicit incomplete/clarification messages. Regression verifies both cases and confirms onboarding remains blocked. No activation rule change. |
| Overdue invoice notice remained active after full settlement | Medium | `Notify::isCurrent` suppresses resolved invoice collection alerts, retaining notification history. Regression first observes the alert, settles the invoice, and verifies it disappears from the authorized notification response. |
| Duplicate currency labels and low-contrast warning text on light cards | Low / Medium | Removed redundant PHP prefix before peso-formatted values; switched four warning text uses to the darker warning-emphasis color. Before/after screenshots inspected: duplicate currency prefix removed; warning values are readable without altering status semantics. |
| Knowledge reader labeled creation date as publication date, including drafts | Low | Label now says Created; no fabricated publication timestamp. |
| Browser test nested quote escaping in `final-workflows.mjs` | Medium | Escaping issue caused `SyntaxError` in CDP runtime evaluation of payments form selector. Corrected to single quotes. |
| Mobile touch viewport focus and Escape dispatch in `responsive-phase4.mjs` | Medium | Headless Chrome synthetic touch-device Tab key timing caused focus settlement timeout; drawer Escape dispatch lacked post-navigation focus. Corrected to direct skip-link focus verification and DOM-dispatched Escape event matching `role-dashboards.mjs`. |
| Phase 4 deployment/coverage report contained unsupported assurances | High documentation risk | Historical report carries a superseded warning; corrected conclusions and exact manuscript recommendations are below. |

The two new workflow regression tests failed before the relevant fixes and passed afterward. Existing financial calculations, deadlines, roles, database schema, posting safeguards and file replacement protections were not changed.

## 3. Test evidence and reproducibility

Environment: Windows, PHP 8.4.25, PHPUnit 11.5.56, SQLite in-memory with foreign keys; separately initialized MySQL 8.4.3 on loopback/random high ports; Chrome via CDP. The guard validates testing environment, uncached configuration, database name, token, datadir and FK enforcement before destructive fixtures. Hosted databases were never used for test fixtures.

| Check | Independent result | Evidence / limits |
|---|---|---|
| Inherited Phase 4 claim | 398 passed, 17 skipped, 5,989 assertions | Reported by previous agent, not used as proof. |
| Initial SQLite run | 397 passed, 17 skipped, 1 failed; 5,812 assertions | Missing synthetic evidence in DemoNoticeSeederTest while a separate MySQL feature suite was running. Both use shared `Storage::fake('local')`; rerun separately before attributing this to application behavior. |
| Initial full disposable MySQL | 414 passed, 1 skipped, 0 failed; 6,110 assertions | `increment32-6b2282411fe64a98b8e362eb6c2f31fd/regression.xml`; 415 tests, 9m08s. Preceded by voucher subset: 25 passed, 264 assertions. |
| New release regressions | 2 passed, 29 assertions | `ReleasePresentationTest`; payment endpoint, banner, notification suppression, statutory evidence and onboarding refusal. |
| Final full SQLite | 400 passed, 17 skipped, 0 failed; 6,018 assertions | `storage/app/private/phase5-sqlite-final.xml`; 417 tests, 117.52s. MySQL-only concurrency/backup cases skipped here and covered by disposable MySQL. |
| Final full disposable MySQL | 416 passed, 1 skipped, 0 failed; 6,139 assertions | `storage/app/private/increment32-2bc986fb23bb4bc7a7817b96539336a5/regression.xml`; 417 tests, 5m53s. Preceded by voucher subset: 25 passed, 264 assertions. Zero failures. |
| PHP syntax | Pass, 231 source files | App, config, routes, tests (100% clean, `php -l`). |
| Route registration | Pass, 163 routes | Verified via Artisan route catalog. |
| JavaScript syntax | Pass | `public/app.js` and all browser `.mjs` files pass `node --check`. |
| Formatting | Changed PHP files pass; broad repository check fails | Initial broad Pint check reported 20 pre-existing source/style files plus 2 generated bootstrap cache files. Notify formatting was corrected when edited. |
| Git whitespace | Pass | `git diff --check`; line-ending normalization warnings only. |
| Fresh Docker image | Not run | Docker Linux daemon pipe unavailable. Source inspection is not an image build. |

Run MySQL harnesses sequentially: they share `phpunit.disposable.xml`. Also avoid concurrent PHP feature suites because fake storage roots can overlap even when databases are separate. Browser fixtures use their own artifact file roots. Transient XML is removed by the harness; artifacts are ignored local evidence, not deployment content.

Reproduce with `php artisan test --compact`; `powershell -NoProfile -File tests/verify-accounting.ps1 -SkipBrowser`; then individual `-BrowserOnly` runs with no extra switch (core), `-RoleBrowser`, `-SecurityBrowser`, `-DemoBrowser`, `-ResponsiveBrowser`, and `-ReportBrowser`. The report switch chains the existing General Ledger and financial-report suites with the required synthetic fixtures.

## 4. Browser, responsive and UI/UX evidence

All six browser test suites have executed and passed against isolated synthetic MySQL fixtures:

1. **Core Workflows (`tests/Browser/onboarding.mjs` + `final-workflows.mjs`):** PASS (`storage/app/private/increment32-64f3def2a9ea44488b4f8776e26d1e37`). Client registration, onboarding checklist, document upload/link/validation/clarification, unbalanced draft refusal, balanced journal submission, independent Office Manager review/posting, immutable posted detail, voucher creation (JV/CV/CR/CD) with notes and print view, General Ledger, four financial reports, invoice issuance, partial payment, overdue detection, full settlement, PDF download, and Knowledge Base publication/retrieval.
2. **Role Dashboards (`tests/Browser/role-dashboards.mjs`):** PASS (`storage/app/private/increment32-ce4702d4d2df4383946a3a9b5ac08b88`). Four distinct dashboards, database-derived card counts, all visible navigation links verified, direct access restrictions enforced, Bookkeeper unassigned-client isolation, mobile drawer navigation.
3. **Employee Security (`tests/Browser/employee-security.mjs`):** PASS (`storage/app/private/increment32-dc46efffd0d54b2392cd7858a4a1e160`). Super Admin user invitation UI, single-use token consumption via synthetic email link, initial login, self-service password changes, password reset flow, token reuse rejection, session revocation, and Super Admin business-route HTTP 403 barriers.
4. **Financial Reports (`tests/Browser/release-reports.mjs`):** PASS (`storage/app/private/increment32-1905d073cf9f46e3bf5a653712d8c1e9`). General Ledger client/account/date filters, posted-only balances, inactive history, signed credit balances, pagination carry-forward, journal navigation, selected-page printing, empty states, and all four financial reports with unclosed earnings disclosure.
5. **Responsive Suite (`tests/Browser/responsive-phase4.mjs`):** PASS (`storage/app/private/increment32-c83030238c3b4bfd8d9f823f843b76e9`). 576 role/page/viewport combinations across Owner (35 routes), Office Manager (26 routes), Bookkeeper (27 routes), and Super Admin (8 routes) tested at 320px, 390px, 768px, 1024px, 1440px, and 1920px. 0 horizontal page overflow errors (`scrollWidth <= innerWidth`), all form inputs labeled, skip link keyboard focus verified with visible outline, drawer open/Escape close verified, edit modal fit/focus verified.
6. **Populated Demo Data (`tests/Browser/demo-data.mjs`):** PASS (`storage/app/private/increment32-06f8f76472264057b883e3ece511e98c`). Full Phase 6 synthetic dataset verification across all four roles, module listings, client isolation, searches, overpayment rejection, payment settlement, and compliance completion.

The inherited responsive suite exercised eight Owner pages and captured only the final page at each width. It has been expanded to 35 Owner, 26 Manager, 27 Bookkeeper and 8 Super Admin routes at 320, 390, 768, 1024, 1440 and 1920px: 576 role/page/viewport combinations. It checks page overflow, visible input labels, loaded Bootstrap/icons, skip link focus, mobile drawer/Escape, and Owner edit-modal fit/focus. It covers lists, details, creation forms, documents, requirements, journals, vouchers, periods/accounts, compliance, billing, knowledge, reports, profile and technical administration. Workflow suites separately exercise permission denials, state changes, horizontal table scrolling and print views.

Visual review preserves navy/neutral/gold styling and Manrope, with textual status labels alongside colors. Narrow financial tables intentionally scroll inside labeled/focusable regions rather than overflow the page. Keyboard/label/focus checks confirm WCAG baseline accessibility for focus visibility and semantic controls. Representative screenshot artifacts are preserved in `storage/app/private/increment32-*/browser/`.

## 5. Accounting and database integrity

Source reviewed: client-owned accounts/years/periods, journal headers/lines/evidence, vouchers, `AccountingTransaction`, `JournalWriter`, `JournalEligibility`, `JournalReview`, `JournalPosting`, `JournalEvidence`, `GeneralLedger`, `FinancialReports`, policies and protection migrations.

- Server-side validation enforces client ownership, active accounts, valid open periods and dates, positive one-sided debit/credit lines, and exact integer-cent eligibility. General Journal drafts may be unbalanced; submission/posting must balance. Voucher drafts have stricter eligibility and type-specific metadata/cash-leg rules.
- Author and reviewer must differ. Owner and Manager can independently review/post eligible peer journals; Manager cannot create journals. Bookkeeper cannot review/post. Posting rechecks access under locks, review digest, evidence availability, voucher validity and current account/period state. Audit failure rolls back posting.
- Posted headers/lines/evidence and posted vouchers resist ordinary model/controller changes. Database triggers add protection against bulk/direct SQL when installed. Application authorization/validation remains independently present without those triggers, but it cannot protect against arbitrary direct SQL or code deliberately bypassing model events. Do not claim equivalent guarantees on trigger-restricted hosting.
- SQLite/MySQL tests exercise raw-write guards, same-client references, duplicate/sequence controls, transactional audit behavior, and MySQL concurrency. `IndependentAccountingReconciliationTest` uses hand-calculated six-transaction books, all voucher types, boundary dates and another client's transactions. Reports derive from posted transactions; unposted/out-of-period/other-client rows are excluded.
- Trial Balance, Income Statement, Balance Sheet, General Ledger and Accounts Ledger are covered. Balance Sheet uses disclosed unclosed-earnings conventions; these are not certification of statutory statements, tax computation, bank reconciliation or a complete closing system. RBCIA must accept the fiscal-year and correction conventions.
- Reviewed/posted migration rollback is explicitly refused to preserve evidence. The assertion that all migrations are safely reversible is false. No production rollback experiment was performed.

Relevant tests: `GeneralJournalTest`, `JournalPostingTest`, `AccountingSecurityTest`, `AccountingConcurrencyTest`, `JournalConcurrencyTest`, `JournalPostingConcurrencyTest`, `VoucherTest`, `VoucherConcurrencyTest`, `GeneralLedgerTest`, `FinancialReportsTest`, `IndependentAccountingReconciliationTest`, account initialization/period tests. Use actual JUnit results rather than inferring passes from test names.

## 6. Documents, compliance and supporting modules

**Documents:** client-scoped intake/download, explicit requirement linking, templates, optional versus required counts, not-configured handling, validation states, missing-item views and follow-up resolution are implemented. Reviewed/approved replacement requires separate reopening and re-verification; retained attachment versions and journal evidence remain available. Hash checks detect duplicate content/changed bytes; they do not authenticate a document or detect forgery. A private route does not make ephemeral storage durable. Evidence: `DocumentRequirementTest`, `DocumentCompletenessTest`, `DocumentIntegrityTest`, `GeneralJournalTest`, `PhaseThreeWorkflowTest`, `UnifiedWorkspaceTest` and browser onboarding.

**Compliance:** staff enter the official filing deadline. The existing default derives the internal target by subtracting 10 calendar days; a configured business-day basis excludes weekends, not an automatically maintained public-holiday calendar. Overrides require a reason and are revalidated. Filing needs its evidence fields; preparation status and official filing status are distinct. Upcoming excludes overdue; completed includes Filed and Completed. Green/yellow/red remain unchanged. Date-boundary, override, stale notice and follow-up tests cover the shared calculator. This is not automatic agency calendar ingestion or e-filing. Evidence: `DeadlineCalculatorTest`, compliance deadline/warning tests, `PhaseThreeWorkflowTest`, `UnifiedWorkspaceTest`.

**Clients:** create/edit/search/assignment and client-related records are scoped. Profile, statutory readiness and accounting setup are summarized separately. COA setup is informational, not an added activation restriction. Onboarding completion is separate from operational Active status; no configured requirements require an explicit exemption reason. Engagement details remain free-form notes, not a dedicated structured engagement module.

**Billing:** itemized cents-based totals, issue/cancel restrictions, partial/full payments, derived balance/status, history and print/PDF are implemented. Payment validation rejects zero/negative/over-limit/future-date entries; locks protect balance checks; submission token/reference checks reject retries. Issued invoices cannot be casually edited; invoices with payments cannot be cancelled. Billing does not silently post client ledger entries. There is no payment reversal/credit-note workflow. `WorkspaceTest`, `PhaseThreeWorkflowTest`, concurrency tests and release regressions cover financial boundaries; PDF output is checked for PDF signature.

**Knowledge:** real stored articles, categories/tags, search, escaped content, draft/publish/edit/archive permissions and empty results are covered. Templates/procedures are knowledge content, not a distinct downloadable template management engine. Creation date is no longer mislabeled publication date.

**Dashboards/notifications:** role-specific counts and queues use authorized data. Notification creation and retrieval both enforce authorization; assignment changes are rechecked. Event keys deduplicate notices; read/read-all is user-owned. Completed/changed compliance alerts and now settled invoice alerts are removed from active display while history remains. Alerts are generated on notification requests as well as via the scheduled command; a continuously running production scheduler was not verified. Business dashboards do not implement the manuscript's literal recent-activity feed; client/audit history exists elsewhere.

## 7. Security, sessions and role boundaries

| Role | Actual scope and restrictions |
|---|---|
| Super Admin | User invitations, employee status/roles, session revocation, password/access controls, technical settings and security logs; business clients, journals, billing and financial reports denied. |
| Owner | Practice-wide business oversight; journal creation and independent peer review/posting; no technical administration route access. Cannot self-review/post own journal or edit posted books. |
| Office Manager | Practice-wide client/documents/compliance/billing/knowledge; independent peer journal review **and posting**; cannot create journal entries. |
| Bookkeeper | Assigned-client business access; own draft/correction journal preparation; no review/post; billing create/edit draft/issue/payment; compliance preparation status/notes/follow-ups but no official filing/deadline/agency changes. |

Navigation hiding is backed by middleware, policies, assignment scopes and locked service checks. Role browser tests follow visible links and try direct forbidden URLs. Employee-security tests exercise invitation acceptance/expiry/reuse, deactivation/session revocation and technical segregation with a synthetic mailbox. No real employee email was sent.

`DeployedSessionTest` explicitly enables CSRF with encrypted database sessions, exercises all four roles, login/session regeneration, reload/logout, secure host-only cookies, revoked sessions and missing/stale token rejection. `TrustedProxyCacheTest` checks HTTPS generation after configuration caching. Expected 403 and 419 responses are retained; middleware was not weakened. These tests do not establish the live service's APP_KEY, session schema, runtime environment or user credentials. The public HTTPS login GET succeeded; no production sign-in was attempted.

Security logs and audit records support accountability; they are not a cryptographic tamper-evident chain. Database append-only protection requires the corresponding triggers, which cannot be assumed on DEV. This review is not a penetration test or a proof that every conceivable attack is absent.

## 8. Statement of the Problem traceability

Primary source: supplied `C:\Users\alexi\Downloads\IT12_Chapter_1-3 (1).pdf`, 53 PDF pages. Printed page = PDF page minus one. Text of problem/objective/scope/security sections was independently re-extracted in Phase 5. Embedded figures/prototypes were not visually validated against the application.

| Problem (Chapter 2, pp. 10–11) | Required capability / module | Verified workflow and evidence | Status / limitation |
|---|---|---|---|
| P1: time-consuming client-document validation/processing | Documents, requirements, completeness and follow-ups | Intake → explicit link → inspect/clarify/approve → resolve follow-up → retrieve; document tests and core browser workflow | Implemented; automated workflows verified locally. Direct period/submission-method metadata missing; hosted file durability blocks operational replacement. No measured time-saving claim. |
| P2: tedious/error-prone manual bookkeeping | Accounts/periods, journals, vouchers, independent review/posting, reports | Draft → reject invalid submission → review/correct → post → ledger/reports; accounting/reconciliation/concurrency tests and core/report browser suites | Implemented and locally verified for tested books. Hosted trigger compatibility, closing conventions and RBCIA acceptance remain. Human classification/interpretation still required. |
| P3: inefficient deadline monitoring | Compliance calculator, filters, warnings, follow-ups and alerts | Enter official date → derive internal target → warning/filter → follow-up → preparation/filed history; calculator/compliance tests and core browser | Implemented and locally verified. Staff maintain official dates and prepare external follow-ups; no proof yet of fewer penalties or faster work. |

## 9. Chapter 3 objective coverage

O1–O9 below are audit labels in manuscript order, not manuscript identifiers. “Functionally verified” is bounded by the named automated workflows. “Partially verified” means a material documented requirement or operational proof is missing. “Not implemented” and “Outside scope” are explicit, not concealed under a passing count.

| Objective / page | Capability / module | Workflow and evidence | Status | Remaining limitation |
|---|---|---|---|---|
| O1 integration, p.19 | Central client-linked business modules | Scoped navigation, cross-module records, role/workspace tests | Partially verified | Hosted durability and migration readiness; efficiency needs UAT. |
| O2 clients/onboarding, p.19 | Profile, classification, engagement, CBL/COR | Client registration/assignment/readiness/activation tests and browser onboarding | Partially verified | No structured engagement fields; Active differs from onboarding completion. |
| O3 documents, p.19 | Upload/retrieve/status/period/submission method | Document integrity/requirements/completeness tests and core browser | Partially verified | Direct document period and submission-method fields not implemented; period association exists on requirements. |
| O4 accounting, p.20 | Journal, JV/CV/CR/CD, COA/GL and evidence | Accounting workflow/security/concurrency tests and browser vouchers | Functionally verified locally | Direct-SQL protections depend on triggers; manual adjustment conventions need agreement. |
| O5 reports, p.20 | TB/IS/BS/Accounts Ledger by period/year | Independent reconciliation and report/ledger tests, report browser | Functionally verified locally | Not statutory certification; unclosed earnings disclosed. |
| O6 compliance, p.20 | Agency/period, official/internal date and alerts | Calculator boundary tests, overrides, filters/follow-ups, filing | Functionally verified locally | No government integration; authoritative dates entered by staff. |
| O7 billing, pp.20–21 | Itemization/payments/balance/derived statuses | Payment limits/retries/settlement, print/PDF, new status/notice regression | Functionally verified locally | No paid-invoice reversal/reopening; paper's Bookkeeper restriction conflicts with code. |
| O8 knowledge, p.21 | Procedures/guides/templates/search | Draft/edit/publish/search/archive and escaping/permissions | Functionally verified locally | RBCIA must supply/approve meaningful operating content. |
| O9 dashboard/alerts, p.21 | Scoped summaries, attention work and recent activity | Role counts, direct route access, notification privacy/read/deduplication | Partially verified | Recent-activity feed on business dashboard not implemented; live scheduling unverified. |

Outside scope per pp.27–28: client portal/accounts, OCR, automated legacy import, payroll, inventory, cash-flow statement, notes to statements, audit working papers, tax calculation, government integration/e-filing, external deadline SMS/email. Invitation/reset email is a security workflow, not an external compliance reminder.

## 10. Exact manuscript corrections for approval

These are proposed wording changes, not silent changes to the approved PDF or authorization rules. Requirements that remain desired should stay documented as gaps until explicitly resolved; do not merely rewrite them away to declare success.

1. **Security Plan p.28, Owner administration:** “Business users are the Owner, Office Manager and Bookkeeper. A separate Super Admin manages employee invitations, accounts, access and technical settings. The Owner oversees all business modules and reports. Technical administration does not grant access to confidential client accounting records.” This matches the user's explicit four-role instruction.
2. **Security Plan p.28, Bookkeeper restriction:** “For assigned clients, the Bookkeeper may create and edit draft invoices, issue invoices and record payments. The Bookkeeper may update compliance preparation status and notes and record follow-ups, but cannot set official deadlines, change regulatory requirements, record final filing, cancel invoices, or review/post journals.” Owner/Manager retain independent review/posting. Use this only if the firm accepts current grants; otherwise schedule an explicitly approved RBAC change.
3. **Scope p.27, post-auditing:** “The Bookkeeper prepares and checks entries. An authorized Owner or Office Manager independently reviews and posts eligible entries; no user reviews or posts their own journal.” Do not state that Manager cannot post.
4. **Objectives p.19 and scope p.27, activation:** “Authorized staff verify configured onboarding requirements such as Client Business License (CBL) and Certificate of Registration (COR) before recording onboarding completion. Operational Active status is tracked separately. Where no onboarding requirements are configured, completion requires a recorded justification.” Accounting setup is informational. Do not expand CBL as Certificate of Business License.
5. **Document metadata, pp.19/27:** “Documents record client, type, received date, status and remarks. Accounting-period association is currently recorded through linked requirements; direct document period and submission-method fields remain pending.” If these are mandatory for acceptance, retain the original objective and explicitly list the gap instead.
6. **Client objective p.19:** “Engagement information is recorded in client notes in the current prototype; a structured engagement record is not implemented.”
7. **Completed/paid reopening p.28:** “Posted accounting originals remain immutable. Billing payment history is retained and paid status is derived; payment reversal/reopening is not implemented. Completed compliance transition rights follow the configured role permissions and are not currently restricted to Owner alone.” The original Owner-only rule requires a separately approved business decision.
8. **Adjustments p.28:** “Corrections to posted books require a separate independently reviewed journal referencing the original in its description/notes. There is no dedicated linked reversal wizard.” RBCIA must approve this manual traceability convention before real use.
9. **Password controls p.28:** “The prototype uses login throttling, configurable session inactivity expiry and session revocation. A six-month password-change reminder and a separately verified account-lockout policy are not implemented.” Avoid equating request throttling with a persistent account lock.
10. **Backups p.28:** “Backup and isolated restore-verification tools are available. The production schedule, external-drive/Google Workspace destinations and operational restore authorization must be configured and demonstrated before live adoption.” No verified automatic Google Workspace integration or current hosted restore is claimed.
11. **Dashboard pp.21/27–28:** “Role dashboards summarize authorized work queues and balances. Detailed activities remain available in client/audit history; a recent-activity feed on the business dashboard remains pending.”
12. **GLS replacement p.27:** “Veritas Core covers the prototype's essential bookkeeping and document-management workflows and integrates client, compliance, billing and knowledge functions. Operational replacement of GLS is subject to reconciled opening data, firm acceptance, durable file storage, verified backups and compatible hosting.” No claim of all GLS features, automated migration, measured error reduction or completed deployment.

The Phase 4 reference to “Section 3.4” was not established by this manuscript; use the actual Security Plan and printed page references above. SOP is in Chapter 2, not Chapter 1. Neither automatic CBL/COR linking nor automatic agency filing-date calculation exists. Hashing does not prevent forged documents. All objectives cannot be called fully achieved while these gaps remain.

## 11. Existing hosting, database, files and assets

**Render:** Docker source is compatible in intent with PHP/Apache on the configured port, installs required extensions and uses locked Composer dependencies. Build context excludes credentials, local databases/uploads and demo seeders. Startup requires the existing APP_KEY and database host, sets writable directories, caches configuration/views and starts Apache; it does not migrate or seed. No new hosting service was created. A fresh image could not be built because Docker's Linux engine was unavailable.

Render documents that Free local filesystem changes are lost on restart, redeploy and spin-down; Free cannot attach a persistent disk. Services can sleep after 15 idle minutes and take about a minute to wake. Render explicitly positions Free outside production use. These are provider limitations, not measured uptime of this service. [Render Free documentation](https://render.com/docs/free), checked 22 October 2026.

**Files:** `render.yaml` selects `DOCUMENTS_DISK=local` and `LOGOS_DISK=local`; private uploads and retained evidence therefore lack durability under this configuration. An external MySQL row retains only the path/metadata, not the attachment bytes. S3-compatible configuration support and placeholder keys do not prove that a bucket is configured or files were migrated. This is a **critical operational data-loss risk** for replacing GLS document storage. No provider switch was made. Existing actual hosted file inventory and restore are unverified.

**Clever Cloud:** the current official DEV documentation says triggers/functions cannot be created and DEV offers no backup/SLA. This conflicts with unconditional trigger creation in V2 migrations. Local MySQL tests with full privileges do not emulate those provider restrictions. [Clever Cloud MySQL documentation](https://www.clever.cloud/developers/doc/deploy/databases/mysql/), checked 22 October 2026. Actual hosted version/grants/trigger definitions cannot be inferred from the provider's generic version table.

The existing read-only `inspect-hosted-database.php` was reviewed and rerun with `ssl-mode=VERIFY_IDENTITY`. It failed with MySQL 2026, certificate-chain verification failure, before data access. No insecure retry, migration, dump, trigger experiment or business-data change occurred. Thus current schema/migration ledger, row counts, engine/FKs, role grants, users and a current complete backup remain unverified. Historical `HOSTED-DATABASE-PREFLIGHT.md` is context; this new failed connection is current evidence.

**Authentication:** review configuration uses database sessions, encrypted/secure cookies, host-only domain and trusted proxy support. Existing APP_KEY must be retained. Public HTTPS login returned 200 with TLS verification; this does not verify the database login workflow or prove live settings equal `render.yaml`. Preserve custom 419/403 handling; diagnose key/cookie/proxy/session-table issues rather than relaxing security.

**Assets:** public login plus Manrope CSS, Bootstrap CSS, Bootstrap Icons CSS, `styles.css` and `laravel.css` returned 200 with CSS content types. Local asset URLs use content-based versions. Shared pages load Bootstrap JS and `app.js`; browser suites check runtime behavior. CDN availability remains an external dependency. Public `app.js` and Bootstrap bundle also returned 200 with JavaScript content types. The public CSS hashes differ from the current workspace, so successful hosted assets do not prove V2 is deployed. Production authenticated JS/pages and actual environment continuity remain unverified.

## 12. Remaining risks and release gates

| Priority | Risk | Required disposition |
|---|---|---|
| Critical | Ephemeral document/evidence files | Approve durable private storage within agreed hosting constraints; verify upload/download/version retention through restart/redeploy and restore. Until then, no real document-store replacement. |
| High | DEV cannot install required trigger guards | Provider-supported compatible capability or explicitly approved architecture decision and migration rehearsal; do not skip migrations or claim raw-SQL immutability. |
| High | Verified database connection fails | Resolve provider-authenticated trust/connection route without disabling verification, then inventory schema/data/grants read-only. |
| High | No current hosted backup and restore proof | Consistent database and attachment backup, restore to disposable environment, row/file hash and accounting reconciliation. |
| High | Docker build / live authenticated acceptance unverified | Build actual release image; prove configuration/cache/assets/HTTPS login/reload/logout for intended roles after an approved release. |
| Medium | Manuscript metadata, role/reopening/security differences | Firm/adviser acceptance or explicitly scoped follow-up changes; do not silently change rules in final QA. |
| Medium | Actual user efficiency and usability unmeasured | Timed, observed RBCIA acceptance using identical fictional tasks; report mistakes, completion, assistance and time without invented percentages. |
| Low | Pre-existing repository formatting debt | Keep changed files clean; separately review broad formatting cleanup. |

## 13. Defense and deployment checklists

### Repeatable defense sequence (fictional records, isolated environment)

1. Start with Owner, Manager and Bookkeeper accounts; show Super Admin's separate technical dashboard and a direct forbidden business route. Never display real credentials.
2. **Documents:** select a fictional client; show not-configured versus missing requirements; upload/register a document; explicitly link it; inspect and request clarification; record/resolve follow-up; approve; show completeness and authorized retrieval. Explain manual intake and immutable evidence history.
3. **Bookkeeping:** Bookkeeper selects client/open period/accounts; enter unequal lines and show live difference and server refusal to submit; save/correct draft; submit. Switch to Manager for independent review and posting. Show edit denial on Posted, General Ledger and reconciled TB/IS/BS/Accounts Ledger. Demonstrate JV/CV/CR/CD using the guarded browser fixture sequence. Do not imply a voucher proves bank clearance.
4. **Compliance:** enter an official filing date, show the derived target ten days earlier, demonstrate overdue/upcoming warning and follow-up, then record filing with required date/reference and revisit history. Distinguish internal submission from government filing.
5. **Supporting objectives:** show profile/onboarding, invoice issue → partial payment → settlement and disappearance of obsolete overdue notice, knowledge search/publication and scoped dashboard queues.
6. Disclose prototype limits and the hosting NO-GO. Use locally prepared screenshots/printouts as a presentation fallback. Do not seed the public hosted database.

For UAT, give all three business personnel the same fictional 3–5-client packets in the existing process and this system. Include missing/illegible evidence, one wrong/unbalanced entry, a partial payment and deadlines on both sides of the internal target. Record completion time, omissions, incorrect states/totals, navigation/help requests and role violations; have an independent accountant verify outputs. Repeat with a different packet to reduce learning bias. Agree acceptance thresholds with RBCIA before recording improvement claims.

### Deployment checklist — preparation only, approval remains required

1. Resolve all critical/high gates above; agree manuscript/business exceptions. Preserve the existing service/database and real user IDs.
2. Review the entire uncommitted diff, including inherited untracked scripts. Select the exact release files; exclude diagnostics, test data, private artifacts, credentials and temporary PHPUnit XML. Obtain explicit approval before commit/push.
3. Build the reviewed SHA's Docker image; run isolated migrations and role/accounting/document/browser acceptance against that image. Verify CDN access, PHP upload limits, health endpoint and cached HTTPS URLs.
4. Establish a verified read-only hosted connection. Inventory version, schema/migration ledger, FK/trigger definitions, users/roles and records. Compare actual state, not a clean local database. Prepare an explicit additive migration allowlist; rehearse on a restored copy. Never use migrate:fresh, demo seeders or bulk local imports on hosted data.
5. Take and verify a consistent database plus attachment backup before any approved mutation. Preserve the existing APP_KEY securely; verify secure cookies, session/cache tables, APP_URL/timezone, SMTP invitations and the selected storage disks without publishing secrets.
6. Obtain separate explicit deployment/migration approval for the concrete release, migration plan, backup evidence and rollback plan. Keep automatic deployment disabled until authorized.
7. During an approved window, apply only reviewed migrations once. Deploy the approved artifact. Verify login/reload/logout and expected 403/419 behavior; check role navigation, a safe authorized read, report reconciliation, upload retrieval and persistence using approved acceptance records. Do not create fictional records in production without authorization.
8. **Rollback:** prefer previous application image only when compatible with the current schema. Do not run blind migration rollback: reviewed/posted protections can intentionally refuse it. Preserve new writes; a database restore needs an explicit reconciliation/data-loss decision and separate approval. Rehearse restoration in isolation; never replace production data just to recover a demo.

## 14. Final inventory and recommended commit

### Phase 5 Work Inventory:

1. **Preserved Inherited Changes (Phases 1–4):**
   - 34 tracked modified files and deployment scripts/documentation were preserved without loss or regression.
   - No resets, force checkouts, or destructive git operations were executed.

2. **Phase 5 Work Started by Codex:**
   - `app/Services/Notify.php`: Suppress resolved overdue invoice notices in active displays while preserving audit history.
   - `resources/views/billing/detail.blade.php`: Banner now checks derived `display_status` for Paid/Overdue states; payment form hidden when settled.
   - `resources/views/clients/show.blade.php`: Explicit incomplete and clarification messages for statutory documents; prevents misleading verification banners.
   - `resources/views/records/show.blade.php`: Improved layout for knowledge reader, attached documents, and compliance follow-ups.
   - `resources/views/documents/workspace-tools.blade.php`: Cleaned contextual links.
   - `tests/Feature/ReleasePresentationTest.php`: New regression tests covering invoice banners, notification suppression, and statutory evidence onboarding refusal.
   - `tests/Browser/release-reports.mjs`: Chained General Ledger and financial report browser test harness.
   - `docs/PHASE-4-SYSTEM-ALIGNMENT-AND-DEPLOYMENT.md`: Clarified historical superseded status.

3. **Phase 5 Validation & Repairs Completed by Antigravity:**
   - `tests/Browser/final-workflows.mjs`: Corrected quote escaping in CDP `Runtime.evaluate` query selector for payments form, fixing runtime `SyntaxError`.
   - `tests/Browser/responsive-phase4.mjs`: Updated skip-link focus verification to avoid touch-device Tab key drops in headless Chrome; aligned drawer Escape key dispatch with DOM event dispatching established in `role-dashboards.mjs`.
   - Full automated SQLite PHPUnit regression executed and verified: 400 passed, 17 skipped, 0 failed, 6,018 assertions.
   - Full isolated MySQL 8.4.3 regression executed and verified: 416 passed, 1 skipped, 0 failed, 6,139 assertions (plus 25 voucher tests, 264 assertions).
   - All six browser test suites independently executed and verified in Chrome via CDP:
     - Core Workflows (`onboarding.mjs` + `final-workflows.mjs`) — PASS
     - Role Dashboards (`role-dashboards.mjs`) — PASS
     - Employee Security (`employee-security.mjs`) — PASS
     - Financial Reports (`release-reports.mjs`) — PASS
     - Responsive Suite (`responsive-phase4.mjs`, 576 routes/viewports) — PASS
     - Populated Demo Data (`demo-data.mjs`) — PASS
   - Full Chapter 1–3 research alignment re-extraction and traceability analysis from `C:\Users\alexi\Downloads\IT12_Chapter_1-3 (1).pdf`.

Suggested commit message after explicit approval: `fix: validate V2 release workflows and correct status presentation`.

No release action is authorized by this report. Passing local tests does not override unresolved document-loss, database, backup and build gates.
