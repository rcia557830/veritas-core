# Veritas Core V2 - Phase 4 Financial Audit Trail

Date: 2026-10-12 (Asia/Singapore). Branch: `feature/veritas-v2`. Base: `869e3b4`.

## Implemented behavior

- `/financial-audit` reads the existing `ledger_entries`, `ledger_items`, accounts, clients and optional voucher relationships. There is no second monetary store, fabricated transaction, accounting balance rewrite or additional posting.
- Each row is one journal line, with transaction date/reference, client, voucher type/reference, account code/name, exact debit/credit, description, preparer/reviewer/poster and posting state. Details include retained supporting evidence and chronological financial events. The detail page is read-only, including for drafts; editing remains in the existing authorized journal/voucher workflows.
- Search matches journal reference, description or voucher reference. Authorized client/account selectors, inclusive independent date bounds, validated status and 10/25/50/100 line pagination run on the server. Stable journal/line ordering and retained query parameters support paging without duplicate rows.
- All filtered lines contribute to totals, independent of the displayed page. Posted and unposted totals are separate. Integer-cent addition uses the existing checked `Money` helpers, with no floating-point SQL SUM. Account-filtered movements are not presented as a balanced journal.
- The same mapped-journal/soft-deletion boundary as General Ledger is used: a journal with an unmapped legacy item is excluded in its entirety. Original legacy records remain readable by authorized users through journal/direct financial details; no mapping or financial content is invented. Ordinary list totals are turnover for the selected dates, not Trial Balance closing balances.
- Filtered CSV streams in bounded batches, applies the same authorization/filters, has explicit status per line, preserves exact decimal amounts and quotes spreadsheet formula prefixes including whitespace/control-character prefixes. Exports contain metadata and amounts, never attachment content or private storage paths. Export permission is checked separately. List totals/page reads and streamed export reads use database transactions; coherent concurrent snapshots require the normal MySQL REPEATABLE READ isolation (SQLite uses its transaction snapshot).

## Authorization and separation

Financial audit uses the existing `generalLedger` policy (`bookkeeping.view`, `account.view`, `client.view` and a business role), preserving the Phase 2 grants without a new permission rollout. Owner and Office Manager see authorized business accounting; Bookkeeper sees assigned-client accounting only. CSV also requires `report.export`. Direct details, forged client/account selectors and retained document downloads are independently checked. Super Admin has no financial audit access.

The old Owner-only `/admin/audit-logs` URL remains a read-only compatibility entry to financial audit; it no longer lists mixed system events. Administration has `/admin/activity-logs`, requiring Super Admin and `security.view`, which reads only historical/new `auth`, `users` and `workspace` activity. `/admin/security-logs` remains the separate security-event interface. Financial descriptions are excluded from both technical listings, and technical activity is excluded from business recent-activity cards and financial history. No historical event is moved, reinterpreted or deleted.

## Durable evidence and forward migration

`2026_10_12_000004_protect_audit_history.php` adds nullable event class, entity type and JSON evidence to existing activity rows. New events explicitly identify technical, financial or other business activity and preserve actor/client/module/entity correlation. Existing rows retain their original IDs, fields and null new metadata; missing historical snapshots are not reconstructed. Entity type distinguishes accounting years from periods even though their local IDs may overlap.

Journal creation/edit, submission/review/return and posting now record allowlisted before/after financial state inside their existing client-locked transactions. Evidence includes original journal references, dates/status, preparation/review/posting attribution, review digest, line IDs/accounts/names/exact amounts, voucher identity/metadata and retained evidence IDs. Voucher edits capture the original and updated voucher metadata in their enclosing transaction. Details expose captured before/after line evidence without serializing application models. Passwords, invitation/reset tokens and file contents are excluded by the snapshot allowlist.

Existing follow-up and requirement-template create/update/delete actions now commit their record mutation and audit insert together. Other modules retain their existing audit descriptions and allowlisted after-state; comprehensive historic before/after snapshots for every non-journal module are not claimed. Current journal evidence snapshots, upload hashes, payment UUIDs, original attachment paths, immutable vouchers and posted journal triggers remain intact.

Model guards reject activity updates/deletes; new SQLite/MySQL triggers reject direct UPDATE/DELETE on both `audit_logs` and `security_events`, including bulk query-builder writes. Automatic rollback is refused. The migration does not touch monetary rows or historical balances and was applied only to disposable test databases. Normal installation migration is still a separately authorized deployment action.

## Verification

| Check | Result |
| --- | --- |
| Focused accounting/security/document/RBAC checkpoint | 82 passed, 610 assertions |
| Additional Phase 4 financial/voucher/atomicity checkpoint | 14 passed, 130 assertions |
| Final SQLite regression | 354 passed, 17 skipped, 2,588 assertions; zero failures/errors |
| Disposable MySQL voucher preflight | 25 passed, 264 assertions |
| Final disposable MySQL regression | 370 passed, 1 skipped, 2,710 assertions; zero failures/errors |
| Business and financial audit browser workflows | Passed, including persisted-data verification, desktop/mobile rendering and access to all financial columns by scrolling |
| Security browser regression | Passed: invitations, password change/reset, replay denial and four-role boundaries |
| Changed PHP Pint, browser-script syntax, whitespace checks | Passed |

After regression, visual review identified that the shared `table-panel` class clipped the wide financial table. Only the financial list container was changed to native horizontal scrolling with a focusable labeled region. The 14 focused financial/atomicity tests passed again (130 assertions); the business browser journey was rerun with explicit scroll-access checks. Accounting/database code did not change after the full regressions.

Final business browser evidence: `storage/app/private/increment32-28860db82cd640bf825c44fb40575713/`. Screenshots include `browser/financial-audit-mobile-scrolled.png`, `financial-audit-detail-mobile.png` and `financial-audit-filtered-desktop.png`; screenshot review confirms reachable columns and readable layout. Business runs assert no JavaScript exceptions and verify persisted fixtures after the journey.

Security browser evidence: `storage/app/private/increment32-31990ad2b8fd426c8e6277780406594e/`.

Targeted checks overlap the full suites. SQLite skips are MySQL-only concurrency cases; MySQL skips the in-memory SQLite backup error-path test. SQLite evidence: `storage/app/private/phase4-sqlite.xml`. Focused evidence: `phase4-targeted.xml` and `phase4-focused.xml` in the same private directory. MySQL evidence: `storage/app/private/increment32-d7af9ab5c49d49e88c33b7990e7bb0b3/regression.xml` and `vouchers.xml`.

The existing independent accounting fixture passes unchanged: Trial Balance debit/credit PHP 1,380.00 each; Cash PHP 1,130.00; Receivables/Payables PHP 180.00 each; Capital PHP 800.00; revenue PHP 300.00; expenses PHP 70.00; net income PHP 230.00; Balance Sheet PHP 1,310.00 on each side, difference PHP 0.00. The new audit test directly reconciles filtered posted account movements to General Ledger, including exact PHP 0.10 + PHP 0.20 debit and PHP 0.15 credit, while drafts remain separate. Four-voucher journal turnover is PHP 1,800.00 debit/credit each, with exactly one contribution per line; Trial Balance closing totals are a different metric.

Execution findings: the first SQLite run had one existing backup error because CLI PHP ZIP was disabled. A temporary ignored `storage/app/private/phase4-php/zip.ini`, selected with process-only `PHP_INI_SCAN_DIR`, enabled the installed extension for verification; normal php.ini was unchanged. The first MySQL preflight had one worker-startup readiness timeout in the existing duplicate-check concurrency case (24 other tests passed). A fresh guarded run passed all 25 preflight cases and the full regression without changing timing/assertions or weakening accounting controls. These incomplete/failed attempts are not counted as successful regressions.

## Retention, recovery and limits

- Audit history is retained indefinitely by the application; no purge, pruning, mutation or deletion UI/API is provided. Any future retention requirement needs a reviewed policy and forward evolution preserving financial evidence.
- Database/server administrators remain outside application RBAC. Privileged SQL can drop triggers/tables, TRUNCATE, restore altered data or change grants; server administrators can change code/files/backups. These controls provide application-level append-only behavior, not cryptographic tamper evidence or independently certified records. Restrict runtime DDL/TRUNCATE grants and keep protected off-host backups with periodic guarded restore verification in the deployment environment. Existing backup/restore verification is retained.
- Before/after evidence starts with this version. Legacy descriptions/IDs remain available but cannot prove missing earlier state. Current account codes/names are shown in the list; journal event snapshots retain the saved line name/account ID rather than inventing historical account codes.
- Explicit adjustment/reversal linking, cancellation, calendar reopening and formal closing entries remain unsupported in the existing model. The page says so; it neither infers links from descriptions nor changes original postings.
- Filtered totals scan matching lines in bounded batches to keep integer arithmetic exact. Very large exports hold a read transaction and may need an operationally scheduled snapshot/export facility. Reopening a page later can show legitimate new postings.
- This is synthetic fixture reconciliation, not certification of RBCIA's actual balances or production readiness. Production mail, hosting configuration, external recovery and real client reconciliation remain deployment checks.
- `veritas_core_db` was not reset, seeded, migrated or used by tests. No Phase 2 authentication, Phase 3 accounting-control replacement, sidebar redesign, expanded demo seeding, push, deployment or modification to `main` was performed.
