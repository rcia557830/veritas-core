# VERITAS CORE V2 — PROJECT CLEANUP & OPTIMIZATION AUDIT

**Date:** October 21, 2026
**Repository:** `veritas-core` (`feature/veritas-v2`)
**Laravel Framework:** 12.0
**PHP Version:** ^8.2 (Production: PHP 8.4 Bookworm)
**Database Engines Supported:** SQLite 3, MySQL 8.0+

---

## Executive Summary

A comprehensive repository-wide audit was conducted across all tracked files, untracked working-tree additions, gitignored artifacts, database migrations, controllers, services, models, Blade views, assets, tests, deployment configs, and documentation.

### Key Audit Findings:
1. **Database Migrations (19 Files):** **100% Active and Essential.** Every migration is tightly coupled to the schema directed acyclic graph (DAG), triggers, compound constraints, and the verified local SQLite demo migration history as well as local MySQL rehearsal baselines. (Remote hosted Clever Cloud database state is preserved untouched and not assumed without explicit live inspection). **Zero migrations should be deleted or squashed.**
2. **Application Core (Controllers, Models, Services):** **100% Referenced.** All 31 HTTP controllers are explicitly bound to active routes in `routes/web.php`. All 27 models, 34 services, and 9 support classes are active components of the V2 business, accounting, audit, and security domains.
3. **Frontend Runtime:** The active UI exclusively uses standard compiled assets in `public/styles.css`, `public/laravel.css`, and `public/app.js` with CDN Bootstrap 5.3.3. The Vite and Tailwind scaffolding (`package.json`, `vite.config.js`, `resources/css/app.css`, `resources/js/app.js`) is dormant, and `resources/views/welcome.blade.php` is an unused 82 KB Laravel scaffold.
4. **Primary Disk Cleanup Opportunity:** Over **1.5 GB** of disposable test artifacts reside in ignored private storage, consisting of 40+ `increment32-*` test run directories, 10 `rehearsal-*` MySQL dumps, obsolete browser screenshots, XML logs, and temporary Codex Stage 3.2 text files.
5. **Documentation Redundancy:** Over 250 KB of redundant documentation exists, notably `docs/RBAC-CODE.md` (190 KB of duplicate PHP code) and `docs/UI-CHANGES.md` (59 KB of duplicate code), alongside 20 historical incremental stage reports that can be consolidated.
6. **Active Work Protection:** All uncommitted changes in `git status` (including remote document storage S3 adaptation, Clever Cloud inspection, and Render Free tier deployment guides) are strictly identified and protected as **MUST KEEP**.

---

## 1. Database Migration Audit

The repository contains **19 migration files** in `database/migrations/`. Below is the complete technical audit of each file, detailing table alterations, trigger creations, dependencies, and risk analysis.

| # | Migration File | Created / Modified Schema & Triggers | Dependent Models / Services | Status & Risk Assessment |
|---|---|---|---|---|
| **01** | `0001_01_01_000000_create_users_table.php` | Creates `users`, `password_reset_tokens`, `sessions`. Base authentication and session tables. | `User`, `AuthController`, `UserController`, `EmployeeInvitations` | **MUST KEEP.** Foundation of user identities and auth sessions. Deleting breaks fresh installs and drops users table on existing DBs. |
| **02** | `0001_01_01_000001_create_cache_table.php` | Creates `cache`, `cache_locks`. Used by database cache driver. | Laravel Cache subsystem, `render.yaml` (`CACHE_STORE=database`) | **MUST KEEP.** Required for Render production caching where `CACHE_STORE=database` is configured. |
| **03** | `0001_01_01_000002_create_jobs_table.php` | Creates `jobs`, `job_batches`, `failed_jobs`. Queue processing infrastructure. | Laravel Queue subsystem, `config/queue.php` | **MUST KEEP.** Required for future background queue workers and database job drivers. |
| **04** | `2026_10_01_000001_create_workspace_tables.php` | Creates `roles`, `clients`, `documents`, `ledger_entries`, `ledger_items`, `compliance_records`, `invoices`, `invoice_items`, `payments`, `knowledge_articles`, `settings`, `notifications`, `notification_events`, `audit_logs`. Alters `users` (`role_id`, `status`, `last_login_at`). | Core V2 models: `Client`, `Document`, `LedgerEntry`, `ComplianceRecord`, `Invoice`, etc. | **MUST KEEP.** Primary baseline schema. Defines the core accounting firm tables and foreign key relationships. |
| **05** | `2026_10_02_000001_add_three_role_permissions_and_notices.php` | Alters `roles` (adds `slug`). Creates `permissions`, `permission_role`, `notices`. Migrates historical role names (`Administrator` -> `Owner`, `Staff` -> `Bookkeeper`). | `Permission`, `Role`, `Notice`, `NoticeController`, `Access`, `config/rbac.php` | **MUST KEEP.** Establishes RBAC permission pivot table and the Notices broadcast system. |
| **06** | `2026_10_10_000001_create_account_templates.php` | Creates `account_templates` and `account_template_items` with unique constraint `template_code_unique`. Enforces `AccountingSchema::assertUnused()` on rollback. | `AccountTemplate`, `AccountTemplateItem`, `TemplateManager`, `AccountTemplateController` | **MUST KEEP.** Governs standard Chart of Accounts templates used to initialize client ledgers. |
| **07** | `2026_10_10_000002_create_client_accounts.php` | Creates `accounts` table with FK to `account_template_items`. Compound unique keys `client_account_code_unique` and `account_owner_unique`. | `Account`, `ChartOfAccounts`, `AccountController`, `LedgerBalance` | **MUST KEEP.** Foundation of the dual-layer client chart of accounts. |
| **08** | `2026_10_10_000003_create_accounting_years_and_periods.php` | Creates `accounting_years` and `accounting_periods`. Injects SQL CHECK constraints: `year_dates_valid`, `period_dates_valid`, `period_closure_valid`. | `AccountingYear`, `AccountingPeriod`, `AccountingPeriodController`, `PeriodValidation` | **MUST KEEP.** Controls accounting year boundaries and monthly period closing immutability. |
| **09** | `2026_10_10_000004_extend_ledger_accounting_references.php` | Extends `ledger_entries` with `accounting_period_id`, `posted_by`, `posted_at`, compound index `entry_ledger_index`. Extends `ledger_items` with `account_id`, `client_id`, CHECK `line_ownership_complete`. | `LedgerEntry`, `LedgerItem`, `GeneralLedger`, `FinancialReports`, `JournalWriter` | **MUST KEEP.** Couples general journal transactions to strict accounting periods and client-isolated accounts. |
| **10** | `2026_10_10_000005_create_journal_documents.php` | Adds unique key `[id, client_id]` to `documents`. Creates `journal_documents` for retained accounting evidence with SHA-256 integrity checksums. | `JournalDocument`, `JournalEvidence`, `LedgerController` | **MUST KEEP.** Enforces legal accounting evidence retention. Rollback is intentionally blocked if evidence exists. |
| **11** | `2026_10_10_000006_protect_journal_posting.php` | Adds `review_digest` to `ledger_entries`. Generates 8 database triggers on `ledger_entries`, `ledger_items`, and `journal_documents` (`SIGNAL SQLSTATE '45000' / RAISE(ABORT)`). | `JournalPosting`, `JournalReview`, `FinancialAuditTrail` | **MUST KEEP.** Hard database-level immutability guarantee: prevents modification or deletion of posted entries and their evidence. |
| **12** | `2026_10_10_000007_create_vouchers.php` | Creates `vouchers` table (JV, CV, CR, CD) with unique sequence, reference, creation token, check details. Generates 4 triggers (`voucher_insert_guard`, `voucher_update_guard`, `voucher_delete_guard`, `voucher_journal_guard`). | `Voucher`, `VoucherWriter`, `VoucherController`, `FinancialAuditVoucherTest` | **MUST KEEP.** Core V2 4-voucher accounting workflow and anti-tampering triggers. |
| **13** | `2026_10_10_000008_create_document_requirements.php` | Creates `document_requirement_templates`, `document_requirements`, `requirement_documents`, `document_follow_ups`. | `DocumentRequirement`, `RequirementController`, `MissingDocumentsController`, `FollowUpController` | **MUST KEEP.** Drives client document checklist, template application, and follow-up tracking. |
| **14** | `2026_10_10_000009_extend_compliance_dual_deadlines.php` | Adds `submission_deadline`, `submission_deadline_is_manual`, `submission_deadline_override_reason` to `compliance_records`. Creates `compliance_follow_ups`. | `ComplianceRecord`, `ComplianceFollowUp`, `ComplianceController`, `DeadlineCalculator` | **MUST KEEP.** Powers the 10-calendar-day dual-deadline monitoring system for BIR/SEC compliance. |
| **15** | `2026_10_10_000010_add_onboarding.php` | Adds `scope` to `document_requirements` ('onboarding' vs 'periodic'). Adds `onboarded_at`, `onboarded_by` to `clients`. | `Client`, `OnboardingReadiness`, `ClientController` | **MUST KEEP.** Governs client onboarding completion, activation readiness scores, and missing document metrics. |
| **16** | `2026_10_12_000001_add_employee_security.php` | Adds `session_version`, `invitation_accepted_at` to `users`. Creates `employee_invitations` and `security_events`. Disables naive rollback. | `EmployeeInvitation`, `EmployeeInvitations`, `AccountSecurity`, `SecurityLogController` | **MUST KEEP.** Super Admin security framework: token-based employee invitations, session revocation, and security audit log. |
| **17** | `2026_10_12_000002_separate_technical_roles.php` | Enforces distinct `super-admin`, `owner`, `office-manager`, `bookkeeper` roles. Assigns technical capabilities strictly to `super-admin`, removing them from business roles. | `Role`, `User`, `UserController`, `RbacTest`, `EmployeeSecurityTest` | **MUST KEEP.** Enforces the four-role separation of duties (Super Admin = technical only, Owner/Manager/Bookkeeper = business operations). |
| **18** | `2026_10_12_000003_add_workflow_submission_guards.php` | Adds `submission_token` to `payments` with unique compound index. Adds `file_sha256` to `documents` with index `[client_id, file_sha256]`. | `Payment`, `Document`, `PaymentController`, `DocumentController` | **MUST KEEP.** Prevents duplicate double-payment submissions and duplicate document uploads via cryptographic hashing. |
| **19** | `2026_10_12_000004_protect_audit_history.php` | Adds `event_class`, `entity_type`, `evidence` to `audit_logs`. Installs append-only triggers on `audit_logs` and `security_events` preventing any UPDATE or DELETE. | `AuditLog`, `Audit`, `AuditLogController`, `AuditAtomicityTest` | **MUST KEEP.** Regulatory audit trail integrity guarantee. Prevents deletion or tampering with historical logs. |

### Migration Conclusion
All 19 migrations are active, necessary, and tested on both SQLite and MySQL. **Do not delete, edit, or squash any migration files.**

---

## 2. Source Code & Assets Audit

### A. Controllers (31 Files) — `app/Http/Controllers/`
* **Status:** **ALL 31 ARE ACTIVE.**
* **Reference Verification:** Every controller (`AccountController`, `AccountingPeriodController`, `AccountTemplateController`, `AuditLogController`, `AuthController`, `ClientController`, `ComplianceController`, `DashboardController`, `DocumentController`, `FinancialAuditController`, `FinancialReportController`, `FollowUpController`, `GeneralLedgerController`, `InvitationController`, `InvoiceController`, `KnowledgeController`, `LedgerController`, `MissingDocumentsController`, `ModuleController`, `NoticeController`, `NotificationController`, `PaymentController`, `ReportController`, `RequirementController`, `RequirementTemplateController`, `SearchController`, `SecurityLogController`, `SettingController`, `UserController`, `VoucherController`, and base `Controller`) is imported and mapped in [routes/web.php](file:///c:/Users/alexi/RBCIA/veritas-core/routes/web.php).
* **Action:** **MUST KEEP ALL.**

### B. Models & Concerns (29 Files) — `app/Models/`
* **Status:** **ALL 27 MODELS & 2 CONCERNS ARE ACTIVE.**
* **Models:** `Account`, `AccountTemplate`, `AccountTemplateItem`, `AccountingPeriod`, `AccountingYear`, `AuditLog`, `Client`, `ComplianceFollowUp`, `ComplianceRecord`, `Document`, `DocumentFollowUp`, `DocumentRequirement`, `DocumentRequirementTemplate`, `EmployeeInvitation`, `Invoice`, `InvoiceItem`, `JournalDocument`, `KnowledgeArticle`, `LedgerEntry`, `LedgerItem`, `Notice`, `Payment`, `Permission`, `Role`, `Setting`, `User`, `Voucher`.
* **Concerns:** `HasAccountCode`, `ValidatesAccountingDates`.
* **Action:** **MUST KEEP ALL.**

### C. Services & Support (43 Files) — `app/Services/` & `app/Support/`
* **Status:** **ALL ARE ACTIVE.**
* **Services:** 14 root services (`Access`, `AccountSecurity`, `Audit`, `DeadlineCalculator`, `DocumentCompleteness`, `EmployeeInvitations`, `Notify`, `OnboardingReadiness`, `RecordInput`, `RecordWriter`, `Records`, `RoleDashboard`, `SecurityMail`, `Summary`).
* **Accounting Services:** 15 accounting domain services in `app/Services/Accounting/` (`AccountingTransaction`, `ChartOfAccounts`, `FinancialAuditTrail`, `FinancialReports`, `GeneralLedger`, `JournalEligibility`, `JournalEvidence`, `JournalPosting`, `JournalReview`, `JournalWriter`, `LedgerBalance`, `PeriodValidation`, `TemplateManager`, `TemplateTransaction`, `VoucherWriter`).
* **Backup Services:** 5 disaster recovery services in `app/Services/Backup/` (`BackupException`, `BackupStore`, `DatabaseBackup`, `FileBackup`, `RestoreVerification`).
* **Support Classes:** 9 classes in `app/Support/` (`AccountCode`, `AccountingSchema`, `DemoDatabase`, `DemoLabels`, `Display`, `Modules`, `Money`, `Navigation`, `PasswordPolicy`).
* **Action:** **MUST KEEP ALL.**

### D. Blade Views & Components (96 Files) — `resources/views/`
* **Status:** 95 views are actively rendered by the 31 controllers and layouts.
* **Unused Scaffold View Identified:**
  - `resources/views/welcome.blade.php` (82,540 bytes): The default Laravel initial splash screen. Route `/` in `routes/web.php` issues a permanent redirect: `Route::redirect('/', '/dashboard');`. It is never called by any controller and never rendered.
* **Action:**
  - `resources/views/welcome.blade.php`: **SAFE TO REMOVE.**
  - All other 95 views: **MUST KEEP.**

### E. Frontend Pipeline & Assets — `public/`, `resources/css/`, `resources/js/`
* **Current Application Asset Architecture:**
  - The entire application layout ([head.blade.php](file:///c:/Users/alexi/RBCIA/veritas-core/resources/views/layouts/head.blade.php) and [app.blade.php](file:///c:/Users/alexi/RBCIA/veritas-core/resources/views/layouts/app.blade.php)) loads:
    - `public/styles.css` (25 KB — custom theme & component styling)
    - `public/laravel.css` (10 KB — base resets & layout classes)
    - `public/app.js` (18 KB — command palette, modals, toasts, dynamic lines)
    - Bootstrap 5.3.3 & Bootstrap Icons via CDN.
* **Dormant Vite Pipeline:**
  - `package.json`, `package-lock.json` (if present), `vite.config.js`
  - `resources/css/app.css` (413 bytes)
  - `resources/js/app.js` (22 bytes)
  - `resources/js/bootstrap.js` (127 bytes)
  - *Evidence:* The only file that contained `@vite` was `welcome.blade.php`. Neither `Dockerfile` nor `render.yaml` runs `npm run build` or `vite`.
* **Action:**
  - `public/styles.css`, `public/laravel.css`, `public/app.js`: **MUST KEEP.**
  - `vite.config.js`, `package.json`, `resources/css/app.css`, `resources/js/app.js`, `resources/js/bootstrap.js`: **REVIEW FIRST.** (Can be preserved if you plan to compile assets in the future, or pruned if strictly adhering to vanilla CSS/JS).

### F. Unit & Feature Tests — `tests/`
* **Status:** Over 40 test files covering concurrency, schema checks, RBAC, vouchers, and compliance.
* **Scaffold Placeholder Identified:**
  - `tests/Unit/ExampleTest.php` (243 bytes): Contains only `$this->assertTrue(true);`.
  - *Note:* `tests/Feature/ExampleTest.php` tests `/` redirecting to `/dashboard` and should be kept.
* **Action:**
  - `tests/Unit/ExampleTest.php`: **SAFE TO REMOVE.**
  - All other test suites in `tests/Feature/`, `tests/Unit/`, `tests/Browser/`, and `tests/Support/`: **MUST KEEP.**

---

## 3. Storage, Test Runs & Disposable Artifacts Audit

This category contains the vast majority of junk data consuming disk space.

### A. Stage 3.2 Temporary Files in `storage/`
* `storage/stage32-focused.txt`
* `storage/stage32-mysql.txt`
* `storage/stage32-provision.php`
* `storage/stage32-sqlite.txt`
* `storage/stage32-temp-path.txt`
* *Evidence:* Explicitly ignored in `.gitignore` (lines 38-39: `# Temporary Codex Stage 3.2 files`). No code imports or references these files.
* **Action:** **SAFE TO REMOVE.**

### B. Disposable Test Runs in `storage/app/private/`
In accordance with `app/Services/Backup/FileBackup.php` line 16 (`private const EXCLUDED = ['backups', 'increment32-', 'stage'];`), these directories are designated disposable test artifacts:
* **`increment32-*` (40+ directories):** Old MySQL regression test logs, browser screenshots, and JUnit reports from prior development iterations.
* **`rehearsal-*` (10 directories):** Old MySQL rehearsal test directories.
* **Old Browser Run Output Folders:**
  - `badges-labels-*` (4 folders)
  - `notices-browser-*` (2 folders)
  - `population-browser*` (multiple folders)
  - `population-workflow*` (multiple folders)
  - `stage22-verification` through `stage31-verification`
  - `check-visible-demo*` (4 folders)
  - `hosted-review-*` (2 folders)
* **Old Test Report Logs & XML Files:**
  - `deployment-regression.xml` (132 KB), `deployment-regression.txt`, `deployment-mysql-regression.txt`
  - `phase3-sqlite.xml`, `phase5-sqlite.xml`, `phase6-sqlite.xml`, `phase5-final-focused.xml`
  - `deployment-mysql-notices-recheck.txt`, `deployment-sqlite-notices-recheck.txt`
  - `diagnostic-mysql-regression-20261021.txt`, `rehearsal-guard-check.txt`, `rehearsal-output.txt`
  - `demo-8000-server.out.log`, `demo-8000-server.err.log`
  - `selective-mysql-validation.txt`, `selective-role-browser-validation.txt`
  - `test-list.txt` (76 KB)
* **Action:** **SAFE TO REMOVE.**

### C. Ad-Hoc Scripts in `storage/app/private/`
* `check-demo-credentials.php`
* `check-population-repeat.php`
* `check-visible-demo.mjs`
* `inspect-population.php`
* `population-browser.mjs`
* `prepare-population.php`
* `mailpit.db`
* *Evidence:* Ad-hoc test scripts placed directly in private storage during previous verification sessions instead of in `tests/Support/` or `scripts/`.
* **Action:** **REVIEW FIRST.** (Can be deleted if superseded by official scripts in `tests/Support/`).

### D. Critical Data to PRESERVE in `storage/` & `database/`
* **`database/veritas_demo.sqlite`:** Primary demo database (30 clients, 204 documents, 360 vouchers). **MUST KEEP.**
* **`storage/app/private/phase6-demo-credentials.xml`:** Encrypted DPAPI vault containing the 4 demo passwords. **MUST KEEP.**
* **`storage/app/private/phase6-demo/*.pdf` (204 files):** Actual demo client PDF attachments referenced by `documents` and `journal_documents`. **MUST KEEP.**
* **`storage/app/private/backups/`:** Disaster recovery database and file backups. **MUST KEEP.**
* **`storage/app/private/transfer-20261015-*/` & `transfer-20261020-*/`:** Staged transfer packages for Clever Cloud / Render. **MUST KEEP (until user confirms migration is complete).**

---

## 4. Documentation Audit & Consolidation Opportunities

There are **44 documentation files** in `docs/` (~700 KB).

### A. Redundant Code Snapshot Dumps (Safe to Remove / Consolidate)
* `docs/RBAC-CODE.md` (190,212 bytes — 3,485 lines): Contains full file-by-file code duplicates of controllers, models, and views from October 2, 2026. The real code is already in `app/`.
* `docs/UI-CHANGES.md` (59,221 bytes — 968 lines): Contains duplicate code excerpts and logs of early UI adjustments.
* *Total space saved:* ~250 KB of redundant text.
* **Action:** **REVIEW FIRST / SAFE TO REMOVE (with approval).**

### B. Historical Milestone Increments (Candidate for Archive Folder)
* `docs/INCREMENT-3.1.md`, `INCREMENT-3.2.md`, `INCREMENT-3.2-RESULTS.txt`, `INCREMENT-4.md`, `INCREMENT-5.md`, `INCREMENT-6.md`, `INCREMENT-7.md`
* `docs/PHASE-1-AUDIT.md`, `PHASE-2-PREPARATION.md`, `PHASE-2-IMPLEMENTATION.md`, `PHASE-3-IMPLEMENTATION.md`, `PHASE-4-HANDOFF.md`, `PHASE-4-IMPLEMENTATION.md`, `PHASE-5-IMPLEMENTATION.md`, `PHASE-6-IMPLEMENTATION.md`
* `docs/STAGE-2.1.md` through `STAGE-2.5.md`
* **Recommendation:** Rather than cluttering the root `docs/` directory, move these 20 historical progression files into a dedicated `docs/archive/` folder or retain them as-is.

### C. Active Master Documentation (Must Keep)
* `docs/FINAL-DEMO-GUIDE.md` (Definitive 13-minute demo manual and credential access instructions)
* `docs/BACKUP-AND-RECOVERY.md` (Comprehensive backup and recovery procedures)
* `docs/FINAL-DEPLOYMENT-CHECKLIST.md` & `docs/FINAL-SYSTEM-READINESS.md`
* `docs/FINAL-UNIFIED-ERD.md`, `docs/ERD.md`, `docs/FINAL-USE-CASE-DIAGRAM.md`, `docs/diagrams/`
* `docs/RBAC.md` (Permission matrix and role specifications)
* `docs/RENDER-DEPLOYMENT.md`, `docs/RENDER-FREE-TIER.md`, `docs/SELECTIVE-RENDER-DEPLOYMENT-REVIEW.md`
* `docs/HOSTED-DATABASE-PREFLIGHT.md`, `docs/DOCKER-WSL-DIAGNOSIS-20261021.md`
* `docs/FILES.md`, `docs/ACCEPTANCE.md`
* **Action:** **MUST KEEP ALL.**

---

## 5. Active & In-Progress Work Protection

The following pending and untracked changes represent active Render Free deployment adaptations and attachment verification. **None will be modified or deleted:**

1. **Modified Tracked Files:**
   - `.env.production.example` (Updated remote S3 storage variables)
   - `.gitignore` (Preserving staging exclusions)
   - `app/Http/Controllers/DocumentController.php` (Remote storage disk support)
   - `app/Http/Controllers/LedgerController.php` (Evidence stream disk support)
   - `app/Http/Controllers/SettingController.php` (Logo remote disk support)
   - `app/Services/Accounting/JournalEvidence.php` (Disk abstraction for attachments)
   - `app/Services/RecordWriter.php` (Remote upload support)
   - `composer.json` & `composer.lock` (`league/flysystem-aws-s3-v3` added)
   - `config/filesystems.php` (Remote disk configuration)
   - `docker/supervisord.conf` (Render service adjustments)
   - `docs/RENDER-DEPLOYMENT.md` (Updated deployment instructions)
   - `render.yaml` (Production Render Free specification)
   - `resources/views/partials/sidebar.blade.php` & `routes/web.php`
2. **Untracked Files:**
   - `app/Console/Commands/VerifyAttachments.php` (Validates remote storage attachments)
   - `docs/DOCKER-WSL-DIAGNOSIS-20261021.md`
   - `docs/HOSTED-DATABASE-PREFLIGHT.md`
   - `docs/RENDER-FREE-TIER.md`
   - `docs/SELECTIVE-RENDER-DEPLOYMENT-REVIEW.md`
   - `scripts/inspect-hosted-database.php`
   - `scripts/inspect-local-account-sources.php`
   - `tests/Feature/RemoteDocumentStorageTest.php`

---

## 6. Classification Summary Table

| Category | Safe to Remove (Verified Disposable) | Review First (Needs Confirmation) | Must Keep (System, Data & Active Work) |
|---|---|---|---|
| **Database Migrations** | *None (0 files)* | *None (0 files)* | **All 19 migrations** in `database/migrations/` |
| **Controllers & Code** | `welcome.blade.php`, `tests/Unit/ExampleTest.php` | Vite/Tailwind scaffolding (`package.json`, `vite.config.js`) | All 31 controllers, 27 models, 34 services, 9 support classes, 9 seeders, 95 views |
| **Test Artifacts & Temp Files** | 5 `storage/stage32-*` files, 40+ `increment32-*` folders, 10 `rehearsal-*` folders, 20+ browser run folders, obsolete XMLs and logs | 6 ad-hoc scripts in `storage/app/private/` (`check-demo-credentials.php`, etc.), `mailpit.db` | All test suites in `tests/Feature`, `tests/Browser`, `tests/Support`, and 5 `.ps1` test runners |
| **Documentation** | *None deleted immediately* | `docs/RBAC-CODE.md` (190 KB), `docs/UI-CHANGES.md` (59 KB), 20 historical increment/stage files | Core guides: `FINAL-DEMO-GUIDE.md`, `BACKUP-AND-RECOVERY.md`, `RBAC.md`, ERDs, Render deployment docs |
| **Data & Credentials** | *None (0 files)* | Staged transfer folders (`storage/app/private/transfer-*`) | `veritas_demo.sqlite`, `phase6-demo-credentials.xml`, 204 demo PDFs in `phase6-demo/`, `backups/` |
| **Deployment Work** | *None (0 files)* | `.deployment/` (Clever Cloud TLS diagnostic) | `Dockerfile`, `docker/`, `render.yaml`, all 15 modified files, all 8 untracked files |

---

## 7. Next Steps & Approval Request

No files have been modified or deleted during this audit phase.

Please review the findings above and let me know which groups of disposable files you would like to proceed with cleaning:
1. **Group 1 (Recommended — Zero Risk):** Remove `welcome.blade.php`, `tests/Unit/ExampleTest.php`, `.phpunit.result.cache`, `storage/stage32-*`, and all disposable `increment32-*`, `rehearsal-*`, and temporary test run directories in `storage/app/private/`.
2. **Group 2 (Documentation Cleanup):** Remove redundant code dump files (`docs/RBAC-CODE.md` and `docs/UI-CHANGES.md`) and/or organize historical phase notes into `docs/archive/`.
3. **Group 3 (Ad-hoc Scripts & Transfer Folders):** Remove ad-hoc verification scripts in `storage/app/private/` and/or past transfer staging packages (once verified not needed).

Awaiting your explicit instruction before performing any deletions.
