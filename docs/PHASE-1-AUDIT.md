# VERITAS CORE V2 - Phase 1 preparation

Audit date: 2026-10-12 (Asia/Manila). Working branch: `feature/veritas-v2`.
Starting commit: `39c501b96118a22244f884b71343df56ddb78c82`, also the local Increment 8 tip. The working tree was clean at the start.

## Scope and evidence

Reviewed the Laravel 12 project, routes, authentication, RBAC, access scoping, requirement/compliance controllers, accounting safeguards, seeders, backup tests, deployment guidance and existing Increment 8 reports. PHP CLI is 8.4.25, with PDO SQLite, PDO MySQL and ZIP available. The default PHPUnit configuration forces SQLite `:memory:`; `TestDatabaseGuard` checks configuration, foreign keys and connection identity before refresh migrations.

Reviewed the locally available remote-tracking ref `origin/feature/render-demo` at `fb76761d1ad31492f7c9e63cc4b61e9003f18eaa`. No fetch was performed; this is not a claim about the current server-side branch tip. Its common ancestor with this branch is `a56ba34`, before the latest Increment 8 reconciliation, diagrams and isolation changes. Both the changes since that ancestor and the direct tip-to-tip differences were reviewed. No merge or cherry-pick was performed.

The demo audit is historical evidence from a different checkout and environment. Its PHP version, database connectivity failure, missing ZIP extension, desktop SQL export, browser results and test counts are not current local results. No live database, external deployment or desktop export was accessed for this phase.

## Reuse decisions

| Demo change | Decision and reason |
| --- | --- |
| `docs/PHASE-1-AUDIT.md` | Adapt its inventory, consolidation proposals and risk list into this branch-specific report. Do not copy its environment claims as current findings. |
| Staff/sample seeder separation | Reuse the design. `StaffSeeder` creates the existing three development accounts; `SampleDataSeeder` retains exactly the existing six-client fixture body. `DatabaseSeeder` coordinates both. |
| Eighteen-client sample expansion | Exclude. It breaks fixture-dependent totals and adds legacy journals that lack posting prerequisites. Expanded demonstrations need their own complete accounting fixtures. |
| `DemoSeeder`, `config/demo.php`, hosted seeding script | Exclude. Production demo flags, shared credentials and hosted provisioning do not belong in invitation preparation. Every new development seeder rejects environments other than local/testing. |
| `AccountingSchema::installsTriggers` and migrations 000006/000007 | Reject. Skipping triggers weakens journal/voucher immutability. Existing migrations and accounting services remain untouched. |
| `DemoDeploymentTest` | Exclude. It validates deliberately triggerless databases and uses SQLite-specific inspection. Existing guarded accounting/schema/reconciliation tests remain intact. |
| Docker/Apache/startup files and Render blueprint | Defer executable deployment configuration. Serving only `public`, persistent APP_KEY, ZIP support and image secret exclusions are reusable requirements; the blueprint also enables trigger skipping, log mail and wildcard proxy trust. None is a safe production deployment by itself. |
| Vite lockfile/build step | Defer until the asset contract is settled. Current application pages use public CSS/JS; the dormant welcome template uses the Vite pipeline. |
| Demo README/deployment result logs | Do not import hosted success claims. Carry forward relevant requirements in the existing deployment checklist. |

## Preparation changes

- Split development accounts from business fixtures without changing role grants, account ordering, passwords, six-client records, billing totals or legacy journal representation. Existing users retain their passwords, names, roles and statuses on rerun. These seeders are development tools, not a production account migration.
- Add seeder regressions covering accounts-only initialization, repeatability, preserved credentials/Owner identity, fixture totals, and production/staging refusal even if a demo flag is set.
- Isolate backup test source disks and backup destinations beneath a unique temporary storage directory. Clean up that directory after each test. Production backup code and retention rules are unchanged.
- Correct the README test instructions: the old fixed MySQL test database is rejected by the current guard; MySQL tests require the verified disposable runner.
- Document the confirmed four-role and invitation/password requirements in [Phase 2 handoff](PHASE-2-PREPARATION.md).

No application permissions, authentication behavior, accounting code, schema, UI routes or operational data were changed. No production seeders, migration/reset commands, push, deployment or changes to `main` were performed. Test refreshes apply only to the guarded in-memory database; backup restore tests use temporary synthetic SQLite files.

## Module inventory and consolidation decisions

| Area | Existing responsibilities | Consolidation recommendation |
| --- | --- | --- |
| Authentication and administration | Auth/profile/reset, roles/permissions, users, settings, audit | Separate technical administration from business oversight in Phase 2. Reuse the password broker and centralized permission definitions. |
| Clients and onboarding | Register, assignments, archive/restore, onboarding checklist/readiness | Group onboarding under Clients; preserve the distinction between onboarding completion and operational status. |
| Documents and requirements | Private uploads, validation, expected-document templates, missing-document monitoring, follow-ups | One Documents navigation group with Files, Requirements, Missing and Templates views. Files and requirements are different records and must not be merged. |
| Accounting | Chart, versioned templates, periods, journal preparation/review/posting, vouchers, General Ledger | One Accounting group. Vouchers identify the same journals; ledger balances are derived from posted lines. Preserve all four voucher types and independent review. |
| Reports | Operational CSV/filter reports and financial statements | Label operational reports clearly; share account movement display components only after behavior is covered. These report families are not interchangeable. |
| Compliance | Records, internal/official deadlines, monitoring, client checklists, follow-ups | Share filters/deadline predicates and group views. Preserve filing evidence and both deadline types. |
| Billing | Firm invoices, payments, PDF/print | Retain separately from clients' accounting books. No automatic journal integration without an ownership/business decision. |
| Knowledge and notices | Durable reference articles and management announcements | Retain both. Different lifecycle and visibility rules; not redundant modules. |
| Backup and audit | Database/files recovery, security/business activity | Retain. Separate security-log access from financial audit access in Phase 2. |

Redundancy is primarily navigation, repeated filters/selectors and rendering, not duplicate accounting domains. The unused welcome screen, scaffold example tests and dual asset pipeline are cleanup candidates after confirming references/build usage. No business module, table, record, compatibility route or existing test was deleted in this phase.

## Findings carried into subsequent work

| Priority | Current source evidence | Disposition |
| --- | --- | --- |
| High | Backup tests previously used the real backup destination even with a fake source disk. | Fixed in test setup. The initial unmodified baseline ran before this finding was inspected; it passed. No pre-run inventory exists to certify that historical backup files were untouched by that initial run. Subsequent runs use isolated destinations. |
| High | Demo branch lacks current requirement-list/monitoring client-period scoping and independent reconciliation tests. | Retained current Increment 8 fixes/tests; no branch replacement. Requirement create/edit period options still query all clients, but current gates restrict these forms to management; apply explicit scoping before future role expansion. |
| High | New roles cannot be added safely through configuration alone: routes, `Access`, policies and last-owner logic hard-code existing roles. Owner currently receives every permission in `PermissionSeeder`. | Phase 2 needs explicit role grants and separate technical routes; adding new permissions blindly would also grant them to Owner. |
| Medium | `ComplianceController::monitor` filters deadlines after pagination. | Existing bug: matches after page one can disappear and totals are wrong. Repair with pagination regression in the next workflow work. |
| Medium | Requirement update validation can omit effective client checks when only a period changes; linked-document period compatibility is not rechecked on such updates. | Repair effective-value validation, add negative partial-update tests, then assess a forward composite FK after an anomaly inventory. No historical migration edits. |
| Medium | Completed/Filed handling differs among deadline, summary, overdue filters and write paths; filing evidence may be cleared. Manual submission overrides also need effective-date revalidation. | Define terminal-state semantics and add regressions before consolidation. |
| Medium | No year/period management UI; no dedicated reversal/payment correction workflow. Existing seed journals are legacy, unmapped records. | Preserve financial evidence; retain Increment 8 production readiness blockers. Do not silently map or post legacy records. |
| Medium | Audit storage is mutable through ordinary model/database access; several follow-up writes and audit writes are separate. | Plan transactional evidence and separate security/financial audit authorization and retention. No claim of append-only audit storage. |
| Operational | Mail delivery, trusted proxy boundary, durable private uploads, installed MySQL triggers, scheduler and recovery on a target host are unverified here. | Existing production NO-GO remains. Historical demo hosting success does not satisfy these gates. |

## Verification

Baseline before edits: `php artisan test --compact` — **306 passed, 14 skipped, 2,067 assertions**, exit 0, 94.80 seconds. No baseline test failures. ZIP is enabled here, unlike the old demo audit environment.

| Check | Result |
| --- | --- |
| Targeted `php vendor/bin/phpunit tests/Feature/DevelopmentSeederTest.php tests/Feature/BackupTest.php --colors=never` | 12 passed, 1 MySQL-only skip, 51 assertions; exit 0. |
| Final `php artisan test --compact` | **309 passed, 14 skipped, 2,099 assertions; zero failures/errors**, exit 0, 37.01 seconds. |
| Pint `--test` on all five changed/new PHP files | Passed. |
| `git diff --check` | Passed. |
| Exact extracted sample-body comparison against starting `DatabaseSeeder` | Passed, including UTF-8 client names; six-client fixture content unchanged. |
| Diff of `app`, `config`, `routes`, `database/migrations` | Empty. Accounting protections, active permissions and authentication behavior unchanged. |

The first targeted invocation through Artisan failed because its Windows subprocess interpreted the filter's pipe; using direct PHPUnit file paths resolved invocation. The first executed targeted run found two new test-harness defects: production seed confirmation invoked an unexpected console prompt, and a backup path assertion compared Windows separators literally. Fixed by testing the seeders' guards directly and normalizing separators in the assertion. The targeted rerun and final full run above passed; no introduced regression remains known.

The 14 skips cover MySQL-specific backup/concurrency scenarios. No MySQL server was started and no existing database was reset or migrated. MySQL concurrency, browser interaction, real mail delivery, hosted deployment and live accounting reconciliation were not rerun or certified in this preparation phase. Historical reports remain available but do not substitute for current target-environment verification.

Phase 1 preparation is complete. The working changes remain uncommitted on `feature/veritas-v2`. Phase 2 implementation can proceed from the confirmed handoff; the workflow findings and existing production readiness blockers above remain outstanding.
