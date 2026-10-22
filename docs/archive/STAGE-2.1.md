# Stage 2.1: accounting schema and model foundation

Date: 2026-10-10 (Asia/Manila). Scope: isolated development/testing only.

## Delivered

Five new tables provide shared template definitions/items, independent client accounts,
fiscal years, and periods. Existing journal entries/lines receive nullable accounting
references and posting metadata. No existing label is mapped, no entry is posted, and
no account template, period, or opening balance is seeded by these migrations.

Account codes retain their displayed case, leading zeros and punctuation after trimming
outer ASCII spaces. The uniqueness key is the full SHA-256 digest after ASCII-only
case folding. Internal characters and non-ASCII case are not normalized. Control
characters, empty codes and codes longer than 255 characters are rejected. The key is
computed by the model and is not mass assignable. Uniqueness is scoped to client or
template and is tested on both database engines.

Accounts relate to their client, optional template item and journal lines. Template
items relate to a template and initialized accounts. Fiscal years relate to clients
and periods. Periods relate to their client/year, entries and closing user. Existing
entry/line models gain period, posting-user, account and ownership relationships.

New structured lines derive ownership from the parent entry. Paired-null CHECK and
composite foreign keys reject partially populated or cross-client references. Null
legacy references remain supported. Period/year and entry/period composite references
also enforce client ownership. Referenced accounts cannot be deleted; deactivation
preserves history. Account identity/ownership cannot be changed through model saves.
Accounts used in posted entries protect their code, name, classification and provenance.

Fiscal years and periods use explicit inclusive dates; no calendar-year rule is embedded
in the schema. Transactional model saves lock the client before overlap checks. SQLite
acquires its writer lock before reads using an identity-preserving UPDATE, since FOR
UPDATE is not supported there. Updates validate current locked values, including stale
model instances. Periods must lie within their same-client fiscal year; referenced
periods cannot be moved, and years cannot be shrunk past their periods.

`JournalEligibility` is a reusable validator for complete active account references,
an open same-client period, valid dates, two to 100 lines, one positive side per line,
and exact integer-cent balance with overflow protection. It does not post entries and
is intentionally not wired into the old free-text workflow yet. Stage 2.3 must invoke
it at submission/review; Stage 2.4 must invoke it under the posting locks. Structured
model writes reject excess precision before Eloquent decimal casting can round it.

## Database guarantees and limits

- Unique code keys per client/template; unique ownership pairs supporting composite FKs.
- Restrictive accounting references and query indexes for client/date/status/account.
- Database CHECKs for ordered dates, consistent Open/Closed closure metadata, and
  both-null-or-both-present account/client references.
- Classification/status domains are enforced by SQLite enum CHECKs and MySQL enums
  using the application's strict connection configuration.
- Overlap, period containment and historical-account changes use model/service
  validation and transactional locks. They are not cross-row SQL CHECK guarantees.
- Eloquent saves/deletes of posted entries/lines are rejected. Raw SQL, bulk query
  updates and privileged database access can bypass model events; do not use them for
  application accounting writes. Full posting/closing concurrency controls remain
  Stage 2.4 work; no posting or closing endpoint was added here.
- Template editing does not propagate to initialized accounts. Used template items
  cannot change identifying/classification fields, and used template versions cannot
  be renamed or renumbered. Template initialization UI/service remains Stage 2.2.

## Migrations and preservation

New files only; historical migration files were not edited:

1. `database/migrations/2026_10_10_000001_create_account_templates.php`
2. `database/migrations/2026_10_10_000002_create_client_accounts.php`
3. `database/migrations/2026_10_10_000003_create_accounting_years_and_periods.php`
4. `database/migrations/2026_10_10_000004_extend_ledger_accounting_references.php`

SQLite requires table reconstruction for these constraints. The ledger alteration runs
atomically with foreign keys temporarily disabled outside the transaction, performs
`foreign_key_check`, and restores enforcement in a finally block. Existing definitions,
indexes, rows and AUTOINCREMENT high-water marks are preserved. MySQL can replace an
implicit FK index with a covering composite index; the migration explicitly restores
original named indexes. MySQL DDL is not transactionally atomic.

Rollback is allowed only when the new accounting tables and reference/metadata columns
are unused. It refuses to discard populated accounting data. Use reviewed forward
corrections after use. Synthetic legacy upgrade/rollback/reapplication tests compare
original column values, IDs, status, labels, amounts, references and timestamps, verify
original indexes, and verify ID high-water marks. No production migration or restore
was performed.

## Test isolation

`Tests\\TestCase::createApplication()` runs `TestDatabaseGuard` before RefreshDatabase
and any migration/seed hooks. It checks the resolved connection, environment, cached
configuration and actual FK enforcement, then removes alternate connections.

Default tests require SQLite `:memory:`. The old `phpunit.mysql.xml` alone is deliberately
insufficient: an ordinary MySQL database name does not prove safe isolation.

For the MySQL verification in this stage, a separate MySQL 8.4.3 server was initialized
in a new temporary data directory and bound to an unused loopback port. No existing
server configuration or data directory was reused. A restricted test user was granted
rights only to a randomly named disposable database plus read access to a separate
identity-marker table. A temporary PHPUnit XML supplied these credentials and forced
the testing connection without changing `.env` or tracked PHPUnit configurations.

The MySQL guard requires all of:

- Uncached testing environment, no connection URL override, loopback host, high port.
- Test-only username and `veritas_stage21_<16 hex characters>` database name.
- Random `VERITAS_TEST_TOKEN` matching the read-only marker in
  `veritas_test_guard.environment` for this exact database.
- `VERITAS_TEST_DATADIR` matching the server's actual `@@datadir`.
- Enabled foreign-key checks.

Only after verification may test migration/rollback/wipe hooks run. Do not place such
markers on a real business database. Recreating this MySQL test environment requires a
new disposable server and credentials; the temporary configuration is not committed.

SQLite command:

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
```

MySQL command, only after disposable provisioning:

```powershell
php vendor/bin/phpunit --configuration <temporary-verified-phpunit.xml> --do-not-cache-result
```

## Executed verification results

- PHP 8.3.33, PHPUnit 11.5.56, SQLite 3.53.2: 74 tests, 795 assertions,
  zero failures/errors, one intentional skip for the MySQL-only concurrency test.
- PHP 8.3.33, PHPUnit 11.5.56, isolated MySQL 8.4.3: 74 tests, 800 assertions,
  zero failures/errors/skips. Includes two-process overlapping-period creation.
- Scoped Laravel Pint check passed; `git diff --check` passed.
- Original 60-test application suite is included in both full runs.
- Migration tests passed on both engines: fresh install, synthetic legacy upgrade,
  rollback, reapplication, row/index/sequence preservation and refusal of populated
  accounting rollback. No actual client history was used or changed.

The test runs identified and resolved SQLite named-FK rollback incompatibility and
MySQL's removal of a redundant implicit index. No unresolved test failure remains.

## Files added or changed for Stage 2.1

Added models:

- `app/Models/AccountTemplate.php`
- `app/Models/AccountTemplateItem.php`
- `app/Models/Account.php`
- `app/Models/AccountingYear.php`
- `app/Models/AccountingPeriod.php`
- `app/Models/Concerns/HasAccountCode.php`
- `app/Models/Concerns/ValidatesAccountingDates.php`

Extended models: `app/Models/Client.php`, `LedgerEntry.php`, `LedgerItem.php`.

Added support/services:

- `app/Support/AccountCode.php`
- `app/Support/AccountingSchema.php`
- `app/Services/Accounting/AccountingTransaction.php`
- `app/Services/Accounting/PeriodValidation.php`
- `app/Services/Accounting/JournalEligibility.php`

Added tests/helpers:

- `tests/Feature/AccountingSchemaTest.php`
- `tests/Feature/AccountingMigrationTest.php`
- `tests/Feature/AccountingConcurrencyTest.php`
- `tests/Unit/TestDatabaseGuardTest.php`
- `tests/Support/AccountingFixtures.php`
- `tests/Support/TestDatabaseGuard.php`
- `tests/Support/accounting-period-worker.php`

Changed test bootstrap: `tests/TestCase.php`. Added this report. The four migrations are
listed above. No frontend, existing permissions, historical migrations, seeders, or
Increment 1 files were changed during Stage 2.1 beyond the existing model extensions.

## Remaining scope

Stage 2.2 should add authorized account management and template initialization, including
transactional protection against initialization/template-edit races. Stage 2.3 should
add structured journal forms and eligibility enforcement. Stage 2.4 should add posting
and closing authorization and concurrent-write controls; Stage 2.5 the posted ledger.
None of those stages is implemented here.

RBCIA must validate actual account codes/classifications, fiscal conventions, opening
balances, reporting conventions and migrated records before operational GLS replacement.
All accounting fixtures added here are explicitly synthetic. Automated verification is
not a production-readiness claim.
