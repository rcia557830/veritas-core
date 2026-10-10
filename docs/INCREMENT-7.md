# Increment 7 — Security, Database Backup, Disaster Recovery & Deployment Readiness

Increment 7 inspects, hardens, tests, and documents the security posture of the
Veritas Core knowledge-management system, and adds a safe database backup and
disaster-recovery workflow. It preserves all Increment 1–6 functionality
(RBAC, private documents, accounting integrity, compliance automation,
onboarding) and does not rebuild any module.

---

## 1. Security review summary

Reviewed: authentication (`AuthController`, `ActiveAccount`, `UserPolicy`),
RBAC (`config/rbac.php`, policies, middleware, `RecordInput`), client
isolation (`Access`, `RecordPolicy`), accounting integrity (`JournalWriter`,
`JournalReview`, `JournalPosting`, `VoucherWriter`, `AccountingTransaction`,
model guards, DB triggers), documents (`DocumentController`, `RecordWriter`,
`DocumentPolicy`, `JournalEvidence`), audit (`Audit`, `AuditLogController`),
notifications (`Notify`, `veritas:notify`), and deployment configuration
(`config/*`, `.env.example`, `.gitignore`).

The existing code already implements most of the required controls (see §4).
The main gaps were operational: **no database backup/restore capability**, **no
file-backup procedure**, and **no production deployment guidance**. These were
implemented in this increment (see §10–§12).

No **Critical** exploitable code vulnerability with a concrete cause was found.
Two **High** (operational) gaps were closed, and the remaining findings are
Low/Medium and documented in §18.

---

## 2. Risk classification

| ID | Class | Finding | Resolution |
| --- | --- | --- | --- |
| H1 | High (operational) | No database backup/restore procedure existed | Implemented `veritas:backup:database` + `veritas:restore:verify` (§10–§11) |
| H2 | High (operational) | No attachment/file-backup procedure | Implemented `veritas:backup:files` (§10) |
| M1 | Medium | No production deployment security guidance | Added `.env.production.example` (§12) |
| M2 | Medium | Increment 6 MySQL + browser verification outstanding | Executed in this increment (§14–§15) |
| L1 | Low | `.env` has a stray trailing `127.0.0.1` line | Reported only; `.env` intentionally untouched |
| L2 | Low | `SESSION_SECURE_COOKIE` / trusted-proxy not surfaced in `.env.example` | Surfaced in `.env.production.example` (§12) |
| L3 | Low | `email_verified_at` not enforced | Accepted (internal staff app); documented (§18) |
| L4 | Low | `public/storage` junction exists | Verified safe: serves only the public disk (firm logo); attachments are private (§8) |

---

## 3. Vulnerabilities confirmed and fixed

No exploitable injection, authentication-bypass, or cross-tenant data-exposure
vulnerability was confirmed in the existing code — each suspected area was
traced to a working safeguard (policy check, `Access` scope, model guard, or
generated file path).

Fixes made in this increment:

1. **File-backup exclusion bug (self-introduced, fixed before release).** The
   first version of `FileBackup` used an incorrect prefix match and would have
   archived disposable MySQL test data (`increment32-*`) into the attachment
   backup. Fixed to prune excluded directories via
   `RecursiveCallbackFilterIterator`; a 101 MB archive was replaced by a 132-byte
   archive containing only real private-disk files.
2. **Restore FK verification.** SQLite restore now enables `PRAGMA
   foreign_keys = ON` so the post-restore connection matches the application's
   enforcement setting and the integrity check is meaningful.

---

## 4. Existing protections verified (not rebuilt)

| Area | Verified safeguards (code references) |
| --- | --- |
| Authentication | bcrypt hashing (`User::casts` `password => hashed`); session `regenerate()` on login; logout invalidates + regenerates token; login throttle 5/min per email+IP (`AuthController::authenticate`); password-reset throttle (`routes/web.php`); reset deletes all user sessions; `current_password` on profile change; `ActiveAccount` force-logs-out inactive users |
| RBAC | `config/rbac.php` grants; Bookkeeper excluded from `bookkeeping.post/review/approve`, `document.validate/approve/reject`, `client.assign/archive/activate`, admin routes; `PermissionMiddleware`/`RoleMiddleware`; `RecordInput::authorize` field-level checks |
| Client isolation | `Access::query` scopes Bookkeepers to `assigned_to`; `RecordPolicy::view` enforces assignment; all cross-module reads go through `Access` |
| Accounting integrity | balanced-journal eligibility; independent review (`reviewed_by !== created_by`); reviewed-only posting; `lockForUpdate` + `AccountingTransaction` (SQLite lock workaround); posted-entry immutability (model guards + `protect_journal_posting` triggers); review SHA-256 digest; voucher identity/sequence/check-duplicate guards |
| Documents | private `local` disk; authorized download only (`DocumentPolicy::download`); `nosniff` header; MIME + extension + size limits; generated storage path; reviewed/approved replacement lock; SHA-256 journal evidence |
| Audit | `Audit::record` attributes user/action/module/record/client/ip + timestamp; no edit/delete routes; `Audit::visible` scoped to authorized modules |

---

## 5. Authentication and RBAC results

Verified by automated tests and inspection:

- Inactive accounts cannot authenticate and, once deactivated, are force-logged
  out on the next request (`ActiveAccount`).
- Login throttles after 5 failed attempts (per email + IP).
- Password reset rejects weak passwords (min 10 chars) and is throttled.
- Profile updates require the current password.
- Owner-only admin routes (`/admin/users`, `/workspace`, `/admin/audit-logs`)
  return 403 for Bookkeeper and Office Manager.
- An Owner cannot demote or deactivate themselves; at least one active Owner is
  enforced (`UserController::save`).
- Bookkeepers cannot post, review, or edit posted accounting records.

New tests: `AuthSecurityTest` (6), `AccountingSecurityTest` (4) — see §16.

---

## 6. Client data isolation results

Verified by inspection and tests:

- `Access::query` returns zero rows for Bookkeepers lacking module `view`, and
  scopes Bookkeepers to `assigned_to` clients; Owner/Office Manager are
  unscoped.
- `RecordPolicy::view` enforces assignment for every client-scoped record.
- A Bookkeeper cannot download another Bookkeeper's client document (403).
- Journal evidence from a different client is rejected
  (`JournalEvidence::sync`).
- Revoked module visibility also removes profile, search, and report data
  (`RbacTest::test_revoked_module_visibility...`).

New test: `DocumentSecurityTest::test_bookkeeper_cannot_download_another_bookkeepers_client_document`.

---

## 7. Accounting integrity results

Preserved from Increments 2–3 and re-verified:

- Independent review required (`reviewed_by !== created_by`) before posting.
- Reviewed-only posting with `lockForUpdate` and a posting-metadata guard.
- Posted entries are immutable at the model and database-trigger levels
  (`JournalPostingTest::immutableWrites`).
- Voucher identity/reference immutability and check-number dedupe.
- Client-specific voucher sequence uniqueness.
- Financial reports exclude unposted entries; Trial Balance / Income Statement /
  Balance Sheet / General Ledger / Accounts Ledger are covered by
  `FinancialReportsTest` and `GeneralLedgerTest`.

New test: `AccountingSecurityTest` confirms Bookkeeper cannot post or review,
cross-client evidence is rejected, and posted vouchers cannot be edited even by
the Owner.

---

## 8. Document storage security

- Attachments live on the private `local` disk (`storage/app/private`), never
  under `public/`; `public/storage` is a junction to the public disk used only
  for the firm logo.
- Downloads require the `document.download` permission **and** view access.
- The stored `file_path` is generated by `Storage::store()` (random name) and is
  **not** mass-assignable through the request.
- MIME/extension/size limits (`mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png`,
  `max:20480`).
- Reviewed/approved attachments are locked; replacement requires explicit
  reopening (`DocumentIntegrityTest`).
- Journal evidence is retained with a SHA-256 digest and verified on download.

New tests: `DocumentSecurityTest` (6) — cross-client download, missing-file 404,
executable/oversized rejection, forged `file_path` dropped, generated storage
path.

---

## 9. Audit integrity findings

- Sensitive actions (user create/role/activation, document validation,
  journal review/post, voucher create, compliance deadline change/filing,
  onboarding completion, settings) write attributed `AuditLog` rows with
  user, action, module, record, client, IP, and timestamp.
- No route exists to edit or delete audit records; the index is read-only and
  Owner-gated (`audit.view`).
- Audit descriptions contain no passwords (`AuditSecurityTest`).
- `Audit::visible` prevents activity cards from leaking restricted-module
  records.

New tests: `AuditSecurityTest` (3). No tamper-proof (cryptographic chaining) is
claimed — audit integrity relies on DB access control, which is the implemented
and verified mechanism.

---

## 10. Backup implementation

Three Artisan commands were added (registered via `->withCommands()` in
`bootstrap/app.php`), backed by `App\Services\Backup\*`:

| Command | Purpose |
| --- | --- |
| `veritas:backup:database` | Consistent `mysqldump` (or SQLite `VACUUM INTO`) dump, timestamped name, SHA-256 manifest, retention prune |
| `veritas:backup:files` | ZIP of the private disk (attachments/journal evidence), manifest, retention prune |
| `veritas:restore:verify` | Guarded restore into a disposable target + integrity verification |

Security properties:

- Credentials never appear in command lines/logs — `mysqldump`/`mysql` read them
  from a temporary `--defaults-extra-file` with restricted permissions, deleted
  immediately afterwards.
- Artifacts live under `storage/app/private/backups/` (private, git-ignored,
  never under `public/`).
- Artifacts are validated non-empty with a SHA-256 manifest.
- Retention (`--retention=N`, default 14) prunes expired sets.
- Backups never modify source records.

Operational procedures are documented in `docs/BACKUP-AND-RECOVERY.md`
(schedule, retention, offsite, encryption, restore, checklist, incident
response, RPO/RTO caveats). The provisional daily policy (14-day local +
weekly offsite) is subject to RBCIA approval.

---

## 11. Restore verification

`veritas:restore:verify` refuses to run when:

- the target equals the configured primary database (host+port+name match), or
- the environment is `production` without `--force`, or
- the MySQL target name does not use the disposable `veritas_restore_*`
  convention without `--force`.

On a permitted target it: validates the backup file → imports into the
disposable database → verifies the `migrations` table, foreign-key enforcement,
representative row counts, and reads representative records (firm name, a
client, a user email) to prove the restored app can read real data.

Tested end-to-end on a disposable SQLite target (`BackupTest`); the MySQL path
is guarded and documented for use against the disposable MySQL harness.

---

## 12. Production deployment configuration findings

- `config/app.php` already defaults `APP_DEBUG=false` and `APP_ENV=production`;
  `config/app.php` timezone is `Asia/Manila`.
- Added a sanitized `.env.production.example` (no secrets) covering
  `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, HTTPS `APP_URL`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_ENCRYPT=true`,
  `LOG_CHANNEL=daily`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, and
  trusted-proxy guidance.
- Web root is Laravel `public/` (`docs/laragon/veritas-core.conf`,
  `public/index.php`). `.env`, `*.sql`, `*.zip`, `*.pem`, `*.key` and the
  private backups directory are git-ignored.
- `public/storage` is a junction to the public disk (firm logo only); client
  attachments remain on the private disk.

Not asserted: actual HTTPS enforcement or production server hardening (no
production host exists). These remain **documented but not deployed**.

---

## 13. Scheduler reliability

`veritas:notify` is registered in `routes/console.php` and scheduled
`dailyAt('07:00')->withoutOverlapping()` (Asia/Manila). Idempotency is
enforced by `notification_events.insertOrIgnore` + `Notify::deadlineKey`
(date-snapshot dedupe) and verified by `ComplianceNotificationTest`
(`test_legacy_deadline_alerts_are_retained_and_replaced_without_duplicate_active_alerts`
runs the command twice and asserts no duplicate active alerts or extra rows).

Also verified by that suite: deadline-change handling, completed/archived
records generating no alerts, client access restrictions on alerts, and
Asia/Manila date handling. Hosting configuration: the Laravel scheduler must be
invoked every minute by the host cron (`* * * * * php artisan schedule:run`),
which is **not** active in any deployed environment and is noted as pending
external confirmation.

---

## 14. Increment 6 MySQL verification results (outstanding, now completed)

Full PHPUnit regression suite against a disposable MySQL 8.4.3 server
(loopback high port, isolated data directory, guarded identity verification via
`tests/verify-accounting.ps1`):

| Metric | Value |
| --- | --- |
| MySQL version | 8.4.3 (Community Server, Win64) |
| Test count | 318 |
| Assertion count | 2151 |
| Passed | 318 |
| Failed | 0 |
| Errors | 0 |
| Skipped | 1 (SQLite-only backup failure-path test) |

The 13 SQLite-skipped concurrency suites (`AccountingConcurrencyTest`,
`AccountInitializationConcurrencyTest`, `JournalConcurrencyTest`,
`JournalPostingConcurrencyTest`, `VoucherConcurrencyTest`) all run and pass on
MySQL. No real or production data was modified; the disposable server was shut
down and its artifacts retained under `storage/app/private/increment32-*`.

---

## 15. Desktop and mobile browser test evidence

Functional browser tests were run with headless Chrome (Chrome DevTools
Protocol, no third-party driver) against the verified disposable MySQL instance
via `tests/verify-accounting.ps1 -BrowserOnly`. The flow chains the existing
accounting and voucher scripts plus the new `tests/Browser/onboarding.mjs`.

**Result: PASS.** All flows completed with no JavaScript exceptions and no
horizontal overflow on mobile:

- **Chart of accounts** — owner login, account create/edit/deactivate,
  templates, initialization, version cloning (desktop + 390px mobile).
- **Onboarding** — client registration, onboarding checklist, justified
  onboarding exemption ("Complete with exemption"), onboarding list, dashboard,
  document-requirements monitoring, compliance monitoring (desktop + 390px
  mobile).
- **Vouchers** — JV/CV/CR/CD creation on mobile, review, independent posting,
  fixed references, immutable posted UI, filtering, four print layouts, General
  Ledger and four financial reports (desktop + 390px mobile).

Screenshots were captured under the disposable artifact directory
(`storage/app/private/increment32-*/browser/`, 38 PNGs). CBL/COR requirement
badges and the full "document verify → approve → onboard" path are covered by
the HTTP feature suites (`OnboardingReadinessTest`, `OnboardingActivationTest`,
`DocumentIntegrityTest`); the browser pass exercises the registration,
exemption, dashboard, and monitoring surfaces end-to-end.

---

## 16. SQLite test results

PHPUnit 11.5.56, PHP 8.4.25, SQLite `:memory:` with foreign keys.

| Run | Result |
| --- | --- |
| Full regression (incl. 29 new Increment 7 security tests) | **318 passed, 14 skipped, 0 failed, 0 errors, 2050 assertions** |

The 14 skips are the 13 MySQL-only concurrency suites plus the MySQL-only backup
success-path test (`BackupTest::test_database_backup_succeeds_against_mysql`).
The concurrency suites run only on the disposable MySQL harness.

---

## 17. MySQL test results

PHPUnit 11.5.56, PHP 8.4.25, disposable MySQL 8.4.3 (guarded identity
verification):

| Run | Result |
| --- | --- |
| Full regression (incl. new Increment 7 security tests) | **318 passed, 1 skipped (SQLite-only backup test), 0 failed, 0 errors, 2151 assertions** |

The new security suites all pass on MySQL: `AuthSecurityTest`,
`DocumentSecurityTest`, `AccountingSecurityTest`, `AuditSecurityTest`, and
`BackupTest` (including `test_database_backup_succeeds_against_mysql`, which
runs a real `mysqldump` backup of the disposable database). The 13 concurrency
suites that skip on SQLite run and pass on MySQL.

---

## 18. Remaining vulnerabilities or limitations

- **No tamper-proof audit.** Audit integrity depends on database access
  control, not cryptographic chaining (correctly not over-claimed).
- **No external notification transport.** Reminders are internal workspace
  notifications only; no email/SMS is wired.
- **`email_verified_at` not enforced.** Accepted for an internal staff app.
- **No production host.** HTTPS enforcement, trusted-proxy config, log
  rotation, and scheduler automation are **documented but not deployed** and
  require external confirmation on the eventual host.
- **RPO/RTO not guaranteed.** No measured RPO/RTO is asserted; the recovery
  time must be measured during rehearsals on the target environment.
- **Onboarding browser flows** beyond the accounting modules remain covered by
  HTTP feature tests rather than a dedicated Chrome pass (see §15).
- **Posting grants are provisional for development.** `PermissionSeeder`
  removes `bookkeeping.post` outside local/testing unless already present; a
  real installation requires an explicit permission rollout.

---

## 19. Operational recommendations

1. Adopt the provisional daily backup policy and assign named backup/recovery
   operators (see `docs/BACKUP-AND-RECOVERY.md`).
2. Enable an offsite, encrypted weekly copy before go-live.
3. Rehearse a full restore on the production-like target and record the
   measured recovery time.
4. Configure the host cron for `php artisan schedule:run` (every minute) and
   confirm notification delivery.
5. On the production host, set `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`,
   a strong `APP_KEY`, a least-privilege DB account, TLS, and the correct
   trusted proxies.
6. Perform a dependency vulnerability review (`composer audit`) as part of
   release.
7. Confirm RBCIA's CBL/COR onboarding applicability and the backup retention
   period.

---

## 20. Increment 8 readiness assessment

Increment 7 does not depend on Increment 8 and is self-contained. The system is
incrementally ready for the next stage; recommended prerequisites before
Increment 8: confirm the production hosting plan, the scheduler/cron
arrangement, the offsite backup target, and RBCIA's retention policy. No
Increment 8 work has been started.

---

## Status legend

- **Implemented** — backup/restore commands, `.env.production.example`, 29
  security tests, `docs/BACKUP-AND-RECOVERY.md`.
- **Verified by automated tests** — authentication/RBAC, client isolation,
  accounting integrity, document security, audit attribution, backup
  failure/file-protection/restore guards, SQLite restoration integrity.
- **Verified by browser testing** — see §15.
- **Documented but not deployed** — HTTPS, trusted proxies, log rotation,
  scheduler cron, offsite storage.
- **Pending external confirmation** — production host configuration, scheduler
  activation, RBCIA policy approvals.

> The application is **not** claimed production-ready based solely on local
> test results.



