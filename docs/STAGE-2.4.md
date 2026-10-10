# Stage 2.4 - Journal posting and accounting integrity

Implemented October 10, 2026 (Asia/Manila). This stage extends the existing Laravel journal, policies, RBAC, accounting models and evidence store. Posted `ledger_entries` and their `ledger_items` remain the accounting source of truth. `LedgerEntry::posted()` is the query boundary for future General Ledger work; there is no duplicate transaction table.

## Posting and authorization

`POST /ledger/{record}/post` (`ledger.post`) is separate from review. The policy requires journal access, `bookkeeping.post`, an active Owner or Office Manager role, and a poster other than the creator. The reviewer may post. Client access is rechecked inside the transaction. Bookkeepers cannot post, even if accidentally granted the permission.

The existing Draft -> For Review -> Reviewed -> Posted workflow and Needs Correction state remain. Posting validates independent review, unused posting metadata, the open same-client period and date, 2-100 valid lines, active same-client accounts, exact positive debit/credit balance, and supporting evidence access/integrity. Evidence remains optional.

Review records a SHA-256 snapshot of the transaction header, reviewer metadata, period identity/boundaries, ordered lines and account identities, and active retained document snapshots. Posting recomputes it. Missing or changed snapshots require return for correction and a new independent review. Saving/correction/submission clears the current snapshot. Pre-existing Reviewed entries are not backfilled or automatically posted; unmapped legacy entries remain unchanged and ineligible.

Source-document replacement preserves the previously attached file version. Posting validates that retained version, not the source's latest attachment. Missing/unreadable files return validation errors; tampering fails hash validation. Evidence downloads still require both journal access and source-document download authorization.

## Transactions and immutability

`AccountingTransaction::forClient()` locks the client before the journal row. Existing account and accounting-period model writers use the same client lock. All posting checks run after acquiring locks. A conditional Reviewed-to-Posted update records `posted_by`/`posted_at`, followed by the audit insert in the same transaction. Any failure rolls both back. Competing requests observe current state; duplicate requests fail with a clear validation error and create no second audit event.

The model rejects ordinary posting/status/metadata writes. Existing model guards reject posted header, line, evidence and deletion changes. Database triggers additionally reject all updates/deletes of Posted headers, and insertion/update/deletion of their lines or evidence, including bulk Eloquent/query-builder writes. MySQL child guards use locking parent reads. Existing account identity and referenced-period protections preserve historical references while allowing subsequent account deactivation or period closure.

The only application query-builder bypass for posting metadata is the guarded update in `JournalPosting`. Privileged direct SQL capable of forging a new posting, removing triggers, changing schema, or editing private files is not an authorized application workflow. Files are verified against retained hashes, not made physically write-once.

The navy/gold journal detail page offers a confirmed Post action, eligibility errors, posted actor/time, read-only state and access-filtered journal audit history. Existing edit/delete/correction actions are unavailable for posted entries and remain enforced server-side.

## Database and permission changes

One additive migration, `2026_10_10_000006_protect_journal_posting.php`, adds nullable `review_digest` and eight immutability triggers. It does not rewrite legacy records, seed financial data or change permissions. Rollback refuses when review snapshots or Posted records exist. Empty rollback/reapplication and legacy sequence/index preservation are covered by the migration regression tests.

`bookkeeping.post` is added to the permission catalog and provisional local/testing Owner and Office Manager grants. Production permission seeding does not add this grant; it preserves any explicitly assigned posting grant. No production permission seeder or migration was run. Deployment requires an explicitly reviewed permission rollout and a migration account able to create triggers. MySQL binary logging may also require an administrator-approved trigger-creation configuration. That setting was changed only on the disposable test server.

## Corrections and future General Ledger contract

Business-specific adjustment/reversal rules remain deferred for RBCIA confirmation. No correction endpoint, automatic reversal date, closed-period exception or year-end closing procedure is introduced.

The extension contract is to create a new same-client Draft using the existing journal writer, retain an explicit restrictive link to the original Posted entry, and run that new entry through submission, independent review and this same posting service. The original remains immutable. A future additive link migration and correction service must define adjustment versus reversal, reasons, allowed dates, duplicate reversal handling, and evidence rules after approval. Reports must include the original and posted correction entries through `posted()`; they must never replace or rewrite the original. No speculative correction data is stored in this stage.

## Verification

Final regression results (PHP 8.3.33 / PHPUnit 11.5.56):

| Check | Result |
| --- | --- |
| Isolated SQLite full suite | 138 total: 128 passed, 10 MySQL-only skipped; 1,260 assertions; 27.008 seconds |
| Disposable MySQL 8.4.3 full suite | 138 passed, no skips; 1,337 assertions; 149.298 seconds |
| New MySQL posting concurrency cases | All 5 passed with observed InnoDB lock waits |
| Scoped Laravel Pint | Passed |
| Journal browser JavaScript syntax | Passed |
| Git whitespace check | Passed |

Chrome desktop/mobile workflow checks **passed**, with no JavaScript exceptions. Checks included the existing account/template and journal workflows, independent re-review, the actual posting confirmation dialog, posted actor/time, removal of edit/post/correction controls, and a 390px posted page without horizontal overflow. Desktop and mobile screenshots were inspected. These are smoke checks, not exhaustive cross-browser or accessibility certification.

The first browser attempt clicked before deferred scripts loaded; the harness now waits for full page readiness before exercising confirmation. The clean-fixture rerun passed. Test runs on the two database engines were kept sequential because the existing file-storage fakes share a directory.

Final logs and synthetic screenshots are retained locally, Git-ignored, in `storage/app/private/stage24-verification/`, including `sqlite.txt`, `mysql.txt`, `browser.txt`, `journal-post-confirm-mobile.png`, `journal-posted-mobile.png`, and `journal-posted-desktop.png`.

Reproduce with the existing guarded configurations:

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
# Requires a separately verified disposable MySQL instance and guard environment:
php vendor/bin/phpunit --configuration <temporary-verified-phpunit.xml> --do-not-cache-result
php tests/Browser/prepare.php --journal
php -S 127.0.0.1:<port> -t public tests/Browser/server.php
node tests/Browser/chart-of-accounts.mjs http://127.0.0.1:<port> <chrome-executable> <temporary-artifacts> tests/Browser/general-journal.mjs
```

Do not run these helpers against a business database. Windows headless Chrome required execution outside the sandbox and used a temporary profile. After verification, the disposable web/MySQL servers were stopped and the temporary database directory, credentials and browser profiles were removed. All database work uses the existing fail-closed guard, SQLite `:memory:` or a newly initialized disposable MySQL server with a dedicated user, random database/token, verified data directory, and foreign-key enforcement. The business `.env` and live database are untouched.

New tests cover authorized posting, metadata, duplicate requests, invalid states/accounts/periods/balances, cross-client foreign keys, missing permission/client access, creator exclusion, stale review snapshots, tampered/missing files, retained replacements and download authorization, atomic audit rollback, direct model metadata guards, bulk-write immutability, non-destructive migration rollback, provisional permission rollout, and correction/re-review.

Five new two-process MySQL tests observe an actual InnoDB lock wait before releasing the first transaction. All five passed:

| First transaction | Waiting operation | Required result |
| --- | --- | --- |
| Post | Post | One Posted entry and one posting audit; duplicate rejected |
| Close period | Post | Posting rejected; remains Reviewed |
| Deactivate account | Post | Posting rejected; remains Reviewed |
| Post | Close period | Posted history retained; closure succeeds afterward |
| Post | Deactivate account | Posted history retained; deactivation succeeds afterward |

The browser workflow extends the Stage 2.3 desktop/mobile scenario through correction, resubmission, independent re-review, posting confirmation, posted metadata and read-only views.

## Files changed in this stage

Created:

- `app/Services/Accounting/JournalPosting.php`
- `app/Services/Accounting/JournalReview.php`
- `database/migrations/2026_10_10_000006_protect_journal_posting.php`
- `tests/Feature/JournalPostingTest.php`
- `tests/Feature/JournalPostingConcurrencyTest.php`
- `tests/Support/journal-posting-worker.php`
- `docs/STAGE-2.4.md`

Modified:

- `app/Services/Accounting/JournalWriter.php` - review snapshot lifecycle.
- `app/Services/Accounting/JournalEvidence.php` - readable validation error for unavailable retained files.
- `app/Models/LedgerEntry.php` - posting metadata guard and authoritative posted query scope.
- `app/Policies/BookkeepingPolicy.php` - posting authorization.
- `app/Http/Controllers/LedgerController.php`, `routes/web.php` - separate posting endpoint.
- `config/rbac.php`, `database/seeders/PermissionSeeder.php` - provisional posting grants and production safeguard.
- `resources/views/ledger/detail.blade.php` - posting, confirmation, eligibility, actor/time, read-only and audit history.
- `public/laravel.css` - wrap audit hashes on narrow journal screens.
- `tests/Feature/AccountingMigrationTest.php` - six additive migrations in rollback verification.
- `tests/Browser/general-journal.mjs` - posting workflow checks.

Earlier uncommitted stages remain in the workspace and are not attributed to Stage 2.4.

## Remaining scope

Correction rules, operational period administration, controlled legacy mapping, opening balances, fiscal policy and production rollout still need separate approval. Existing generic workspace reports have not been converted into financial statements. Stage 2.5 should implement a read-only General Ledger from Posted journal lines, scoped by authorized client/account/period, with exact running balances, stable ordering and drill-down to the original journal/evidence. RBCIA must confirm opening-balance and report conventions first. Stage 2.5 has not been started.
