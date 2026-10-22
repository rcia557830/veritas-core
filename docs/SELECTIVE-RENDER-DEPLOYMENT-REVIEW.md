# Veritas Core V2 — selective deployment review

Prepared 21 October 2026, Asia/Manila. **Decision: HOLD — not approved for deployment.**

Latest hosted-access result: the credentials file is available and Git-ignored, but verified TLS fails before SQL inspection. The server presents an automatically generated certificate whose identity does not match the requested hostname. No hosted account comparison or backup is complete. See [the hosted preflight report](HOSTED-DATABASE-PREFLIGHT.md).

This is the current deployment scope. It supersedes the bulk-data transfer procedure in `RENDER-FREE-TIER.md` and `RENDER-DEPLOYMENT.md`. Retain the latest application features, reconcile only the four approved accounts and essential system configuration, and preserve all existing hosted records. Do not import the populated SQLite database.

Target: GitHub `rcia557830/veritas-core`, branch `feature/veritas-v2`; existing Render service `veritas-core-college-demo`, Free, with its existing URL; existing Clever Cloud MySQL DEV. No new service, paid disk, plan upgrade or billable subscription is authorized.

## 1. Application modules and deployment readiness

All requested feature code remains included. No feature or integrity control has been removed to accommodate DEV.

| Module | Source readiness | Hosted acceptance dependency |
|---|---|---|
| Super Admin / employee administration | Implemented: invitations, account status, session revocation, role separation, technical dashboard | Approved identities and permissions, invitation/security schema, working mail, append-only security history |
| Owner / Office Manager / Bookkeeper | Implemented: distinct dashboards, permission checks, client assignment scoping | Actual hosted role/grant comparison and four-role acceptance |
| Clients and onboarding | Implemented: registration requirements, onboarding attribution, account/year setup | Existing client/reference integrity, reviewed onboarding backfill |
| Documents and requirements | Implemented: authorization, duplicate checks, private downloads, evidence retention | Durable approved private storage for legitimate existing files and future uploads |
| Journals and vouchers | Implemented: independent review, posting digests, ownership keys, immutable records | **Blocked if the 12 required journal/voucher triggers cannot be installed or verified** |
| Compliance and billing | Implemented: dual deadlines, follow-ups, payment idempotency | Additive schema, preserved live operational data, audit protection |
| Knowledge Base / Workspace Notices | Implemented: permission-based authoring and visibility | Tables/grants; preserve hosted content; import no seeded articles or notices |
| Audit/security history | Implemented: model guards plus database append-only guards | **Four append-only triggers required; application guards alone are not equivalent** |
| UI/UX and status badges | Included in branch and regression coverage | Browser checks on a disposable database, then approved hosted acceptance |

“Implemented” does not mean deployed or verified on Clever Cloud. Shared audit/security dependencies prevent declaring the non-accounting modules fully supported while the audit triggers are missing.

## 2. Database schema review

There are 19 migration files. Hosted `migrations`, actual DDL and grants have **not** been read: credentials are now supplied in `.deployment/clevercloud.env`, but certificate trust and hostname verification block authenticated access. The local `.env` points to localhost; that local MySQL candidate could not be inspected even outside the sandbox. Therefore the exact pending migration list is unknown.

The following is a source classification, not permission to execute migrations:

| Migration suffix / date | Change | DEV compatibility review |
|---|---|---|
| `0001…create_users_table` | Users, password reset tokens, sessions | Ordinary DDL; baseline comparison required |
| `0001…create_cache_table` | Cache and locks | Ordinary DDL; preserve hosted runtime state until reviewed |
| `0001…create_jobs_table` | Jobs, batches, failed jobs | Ordinary DDL; synchronous queue does not authorize deleting existing jobs |
| `2026_10_01…create_workspace_tables` | Core business tables and roles/settings | Baseline only; never rerun over populated tables |
| `2026_10_02…add_three_role_permissions_and_notices` | Role slugs, permission catalog/pivot, notices | Renames Administrator/Staff while preserving role/user IDs; inspect slug collisions and existing grants |
| `2026_10_10_000001` | Account templates/items | No triggers; preserve existing templates |
| `2026_10_10_000002` | Client chart of accounts and ownership keys | No triggers; client accounts are operational data, not global seed data |
| `2026_10_10_000003` | Accounting years/periods, checks, composite keys | Verify MySQL version, enforced CHECK support and existing data |
| `2026_10_10_000004` | Journal account/period/ownership references and posting fields | Inspect nullable legacy links, indexes and foreign keys; do not fabricate ownership |
| `2026_10_10_000005` | Retained document evidence and ownership keys | Requires existing document/client relationship validation |
| `2026_10_10_000006` | `review_digest` plus 8 posted-journal triggers | **Trigger blocker** |
| `2026_10_10_000007` | Voucher table, unique identities and 4 voucher triggers | **Trigger blocker** |
| `2026_10_10_000008` | Requirement templates, requirements, document links, follow-ups | No triggers in this migration; retain existing requirements/links |
| `2026_10_10_000009` | Compliance submission deadlines and follow-ups | Additive; no automatic historical deadline rewrite |
| `2026_10_10_000010` | Onboarding attribution and requirement scope | Backfills existing requirement scope based on period link; review affected rows |
| `2026_10_12_000001` | User session version, invitation acceptance, employee invitations and security events | No triggers here; security history protection depends on `000004` |
| `2026_10_12_000002` | Technical/business permission separation | Preserves users/passwords; **removes technical grants from all roles and resets Super Admin grants**; review permission diff before execution |
| `2026_10_12_000003` | Payment submission UUID uniqueness, document SHA-256 index | Additive; legacy values nullable, no synthetic backfill |
| `2026_10_12_000004` | Audit metadata and 4 append-only triggers | **Trigger blocker**; automatic rollback deliberately refused |

MySQL DDL can commit before a later trigger creation fails. The three trigger migrations perform table/column changes before creating triggers. Running them blindly can leave a partially changed schema without a completed migration ledger entry. A “migrations table says applied” result also does not prove the triggers exist. Compare actual definitions and constraints.

Before approval, restore a hosted backup into isolated MySQL, diff its schema with the expected schema built in a separate disposable database, derive an explicit ordered pending-migration allowlist, and rehearse that exact list against the restored hosted data. Check table/row preservation, relationships, unique keys, indexes, accounting guards and grants. Do not use `migrate --pretend` as proof that grants or data constraints will work.

## 3. Four accounts: source and reconciliation plan

The owner identified **`database/veritas_demo.sqlite` as the authoritative intended account source**. It contains exactly these four active accounts with nonempty bcrypt hashes:

| Source user ID | Name / role | Source email |
|---|---|---|
| 1 | Super Admin / `super-admin` | `super-admin@demo.example.test` |
| 2 | Owner / `owner` | `owner@demo.example.test` |
| 3 | Office Manager / `office-manager` | `office-manager@demo.example.test` |
| 4 | Bookkeeper / `bookkeeper` | `bookkeeper@demo.example.test` |

Their row update timestamps range from 15 October 2026 00:29:47 to 14:43:26 as stored in SQLite. These timestamps are not password-change timestamps. Source SHA-256: `9b9d28c3ae34511f447f3d82aaa798d456483c311f741c7365cfc01993844821`. The prior complete local conversion verified exact password-hash preservation, not intended-current-password login on Render.

The source has 158 role-permission assignments: Super Admin 10, Owner 64, Office Manager 54, Bookkeeper 30. The private inventory records permission names per role for a later target diff. These counts are not authorization to overwrite hosted grants.

The `.test` addresses are development-domain identities. The owner's source selection permits preparing a plan for these four records; it does not justify changing unrelated online identities or assuming these addresses receive mail. Reconcile any different hosted addresses explicitly before approval.

Read-only local inventory: `php scripts/inspect-local-account-sources.php`. Identity metadata is saved only in ignored `storage/app/private/selective-account-inventory.json`; no password/hash is exported or printed. No local MySQL account source was available. Hosted comparison remains pending a verified connection, as detailed in the hosted preflight report.

For each source account, the approval table must contain source ID/email, hosted ID/email, current and intended role/status, password-equality boolean, affected relationships and proposed action:

1. Match verified identities by email, checking case/collation and normalization collisions. Never infer identity solely from role or numeric ID. If the owner identifies an existing account under a different email, record that explicit mapping.
2. For a matched online user, retain the **hosted user ID** and all existing foreign-key references. Do not renumber existing users to 1–4.
3. For an absent approved identity, allocate a new hosted auto-increment ID and record source-to-target mapping. If a source ID is already used by another person, it remains untouched. Do not insert source IDs blindly, use `REPLACE`, truncate users, or delete other hosted accounts.
4. Map role IDs by stable role slug, permission IDs by permission name. Review actual role-to-permission changes, including custom grants. `PermissionSeeder` uses `sync()` and can remove grants; it is not a harmless production bootstrap. Do not run it blindly. Preserve or explicitly approve `bookkeeping.post`; production permission seeding does not automatically grant it.
5. Copy only approved names, emails, stored hashes, role assignments and statuses. Write stored hashes exactly through the query builder in an approved transaction, avoiding password rehash/reset hooks. Verify equality in memory and emit only pass/fail counts. Do not include hashes in SQL previews, diffs, logs or the report.
6. Resolve any source Active / hosted suspended conflict explicitly. Preserve verified authentication metadata; do not copy source sessions, reset tokens, invitations or remember tokens. Do not overwrite a newer hosted session version with an older value. Session revocation/version increments are a separate reviewed security action; changing credentials is not permission to erase security history.
7. Audit the legitimate reconciliation using the target application’s redacted audit/security mechanism after required protections exist. Import no synthetic source history. Protect the last active Owner/Super Admin throughout the transaction.

The actual target mapping and account-change SQL cannot be finalized until the hosted inventory/backup exists. No account-export/import artifact has been prepared or executed.

Password hashes cannot establish knowledge of intended plaintext passwords. Verify the owner’s intended credentials against an isolated reconciled copy without printing or recording them, then perform owner-assisted login for all four roles on Render after deployment approval. Local synthetic-login tests are separate evidence.

## 4. Data included and excluded

Allowlist for potential transfer: these four approved users’ reconciled fields; reviewed role/permission catalog additions and grant changes; explicitly reviewed non-demo global reference configuration only.

Preserve existing hosted workspace settings. If missing, obtain legitimate firm details and review a minimal configuration insert. Do not copy the demo `settings` row. Global account templates and requirement templates may be valid reference data only after review; the SQLite source contains zero account templates, zero template items and zero document requirement templates. Client-specific chart accounts, accounting years/periods and requirements are operational data, even though their tables support configuration-like screens.

Explicitly excluded from SQLite transfer:

- 30 clients; 360 journals and 720 lines; 360 vouchers.
- 15 seeded Notices and 36 seeded Knowledge Base articles.
- 120 compliance records, 24 compliance follow-ups, 30 invoices, 60 invoice items and 22 payments.
- 270 client accounts, 30 accounting years, 30 accounting periods.
- 204 document records/PDFs, 360 journal evidence references, 210 document requirements, 204 requirement links and 6 document follow-ups.
- 2,307 synthetic audit logs, 58 synthetic security events, generated notifications/events.
- Demo workspace settings, sessions, cache/locks, reset/invitation tokens, jobs/batches/failed jobs and the SQLite migration ledger.

Exclusion means **do not transfer these local records**, not delete matching table contents on Clever Cloud. Preserve all existing online records, including any suspected demo data, unless a separate reviewed deletion is authorized. No `converted.sql`, full SQLite snapshot, bulk seed command or transfer package is an approved deployment input.

Docker’s source allowlist excludes SQLite, seeders, attachments, backups and `.env`; startup performs no migrations, imports or seeding. Verify the final build context and image before release. Tests use disposable fixtures, not the online database.

## 5. Clever Cloud DEV / free-hosting blockers and safe options

[Clever Cloud documentation](https://www.clever.cloud/developers/doc/deploy/databases/mysql/) explicitly lists trigger/function creation as unavailable directly on DEV. Required guards:

- 8: posted header UPDATE/DELETE and INSERT/UPDATE/DELETE of journal lines/evidence.
- 4: voucher insert/update/delete protection and voucher-linked journal identity protection.
- 4: UPDATE/DELETE prevention on `audit_logs` and `security_events`.

Do not introduce a skip-trigger flag, mark failed migrations applied, remove guard SQL, rely only on Eloquent guards, or claim the full application is supported without these controls.

Safe first option: retain the current website unchanged and seek provider confirmation of whether support can install/retain the exact guards on DEV **at no cost**. This is an inquiry proposal, not a claim that an exception exists or authorization to send a support message. Existing triggers, if present, must be inspected for exact definitions, valid definers and enforceability; their mere count is insufficient.

If the provider cannot support these controls, the full requested deployment on the specified DEV database is blocked. A different genuinely free database supporting the same protections would require a separately approved architecture/provider change and migration rehearsal. No replacement provider is selected or activated. A future equivalent-integrity redesign is substantial work requiring a reviewed threat model and tests, not a deployment shortcut. Do not silently disable modules.

Render remains Free with no disk, sync queue and Apache only. No automatic 07:00 scheduler execution while asleep; on-access notification generation remains. Preserve the current service identity and URL. R2 remains an unactivated conditional option requiring billing approval. Excluding the 204 generated PDFs does **not** solve persistence for existing legitimate hosted attachments or future uploads. Inventory and back up those files before any restart/deploy; external storage remains a release gate. No approved guaranteed-zero-billing storage backend has been verified.

## 6. Verification evidence and gaps

Current local validation:

- Isolated MySQL 8.4 suite: **393 tests, 392 passed, 1 skipped, 5,809 assertions**. Separate voucher preflight: **25 passed, 264 assertions**. Full run took 8 minutes 25 seconds.
- Final focused private-storage/logo tests after correcting Super Admin logo access: **3 passed, 22 assertions** on SQLite.
- PHP syntax, Pint and Git whitespace checks passed. The account inventory and validation logs are Git-ignored. The tracked-file check found only sanitized `.env` examples, not actual `.env`, SQLite files, SQL dumps or credential files.
- Source audit remains identical to the prior audit, including database checksum, counts and all PDF hashes. No source records/passwords changed.
- Four-role browser validation **passed** after the sandbox initially blocked Chrome startup (`spawn EPERM`). The successful run used a new isolated database outside the sandbox: four distinct desktop/mobile dashboards, independently checked counts, all visible links, direct-route restrictions, client isolation and mobile navigation. The account/template browser workflow also passed with no JavaScript exceptions.
- Reviewed the resulting Super Admin desktop and Owner mobile screenshots. Browser artifacts are in ignored `storage/app/private/increment32-7a21340d9413466987d4df07a8c0014e/browser/`. Browser identities/data are synthetic; none were deployed.

The previous free-tier preparation also passed 375 tests with 17 skips on SQLite. These are local results and do not validate Clever Cloud or the intended current passwords. The test runner verifies a unique local server identity before creating or migrating its synthetic database; it never targets the hosted database.

The review also corrected private-logo authorization to include Super Admin alongside the three business roles, and set the review-only Render blueprint's branch explicitly to `feature/veritas-v2`. No hosted settings were changed.

Pending hosted checks: complete read-only schema/account/grant inspection, restorable backup, exact target migration rehearsal, four intended passwords, legitimate file inventory, record preservation, managed-server trigger support, and restart/sleep persistence. Docker image validation is also pending because Docker Desktop was unable to start in the preceding check.

## 7. Exact revision and deployment procedure

Read-only GitHub verification found:

```text
Repository: https://github.com/rcia557830/veritas-core
Branch: feature/veritas-v2
Local HEAD and remote branch HEAD:
388c595523deeb03ff7f3dc9f72174a82ca7b66f
```

That commit contains the latest committed application work but **does not include the uncommitted Free-plan/private-storage changes or this selective review**. There is no final release commit SHA yet. Do not deploy the current remote SHA as though it includes the proposed changes. No commit or push was made during this review.

After resolving blockers, the concrete approval package must include the final reviewed diff, a local release commit SHA, exact pending migration allowlist, account mapping and permission diff, backup/restore evidence and file manifest. Then:

1. Obtain approval of that package. Verify the existing service's connected repository/branch, current deployed SHA, URL, plan and live auto-deploy setting through Render. `autoDeployTrigger: off` in the repository does not prove the current service is configured that way.
2. With approval, disable live auto-deploy before pushing to `feature/veritas-v2`; otherwise a push could deploy prematurely. Commit only explicitly reviewed source/docs/tests, never `git add .` over private artifacts. Push the approved release and confirm the exact remote SHA.
3. Freeze hosted writes and take/verify the final backup. Apply only the rehearsed approved pending schema changes and account/configuration transaction, from a trusted operator environment. Render Free does not require purchasing a shell/worker to run these. Stop immediately on discrepancies.
4. Preserve the hosted `APP_KEY`, database secrets, actual URL and approved mail/storage settings. Keep `APP_ENV=production`, `APP_DEBUG=false`, `VERITAS_DEMO_DATA=false`, Free plan and no disk.
5. In the **existing** service, use **Manual Deploy → Deploy a specific commit**, entering the approved full SHA. Do not use “New Web Service,” “New Blueprint,” or “Deploy latest” while approval names a specific revision. [Render procedure](https://render.com/docs/deploys).
6. Verify the deployed SHA, `/up`, database/schema readiness, all four role logins and access boundaries, page/module behavior, authorized file access and restart/sleep persistence. Compare pre/post record digests and foreign keys; permit only approved schema/configuration/account changes and genuine acceptance-test audit events. Check that no local demo client/content/file identifiers were added.

The unfulfilled gates prevent providing a truthful final release SHA or claiming a complete production acceptance pass today.

## 8. Backup and rollback

Before proposing account imports or schema execution, inspect Clever Cloud read-only and take a full consistent dump to ignored private local storage. Use a protected MySQL option file; no passwords on the command line or in logs. Include schema/data, triggers, routines and events as privileges permit; explicitly record any inaccessible objects. Use transactional snapshot options only after confirming storage engines and preventing concurrent DDL. A dump that omits required objects is not a complete backup.

Record SHA-256, server version, migration ledger, actual object definitions, row counts/digests, grants, account/role mappings and private-file hashes. Restore on isolated local MySQL and prove relationships, account hash equality and existing records survive. Back up the hosted application key/secrets separately through a secure channel. Never commit dumps, `.env`, credentials or backup contents.

Capture the currently deployed Render commit and configuration before cutover. Reverting application code alone cannot reverse role changes, password replacement or partially committed DDL. Several V2 migrations intentionally refuse `down()`. Do not use `migrate:rollback`, database wipes, or restore over live writes as an automatic rollback.

On failure, keep writes stopped, retain post-cutover records for reconciliation, and obtain approval for the exact recovery action: compatible previous code or a tested restoration of the consistent hosted database/files pair with the original key. Trigger restoration on DEV is itself a blocker; prove the provider-supported restoration route before accepting the rollback plan. No paid resource or second Render service may be introduced for rollback without separate approval.
