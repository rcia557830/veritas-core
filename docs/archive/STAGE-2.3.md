# Stage 2.3 — General Journal improvements

Completed and verified on October 10, 2026 (Asia/Manila). The existing `/ledger` module is now presented as **General Journal**. It continues to use `ledger_entries` and `ledger_items`; no second transaction store, posting workflow, opening balances, or financial statements were introduced.

**Implemented workflow**

- Client-specific active account dropdowns show account codes and names. New entries require account IDs, with ownership derived on the server. Forged, missing, inactive and cross-client references are rejected.
- Open accounting periods are loaded for the selected client. A supplied period must contain the transaction date. Drafts may omit a period; submission and review require a valid open period. No fiscal calendar is hardcoded in application logic.
- Entries retain transaction date, reference, description and notes, with 2–100 debit/credit lines. Each line requires exactly one positive side; negatives, excessive precision, malformed rows, duplicate line IDs, foreign line IDs and identical account/amount rows are rejected. Repeated accounts with different amounts remain supported.
- Unbalanced Drafts can be saved. Both submission and independent review recheck complete same-client references, active accounts, period status/date containment, positive exact balancing, and retained supporting evidence.
- PHP validation uses integer cents; journal display uses exact decimal strings. Browser totals use `BigInt` cents. The broader signed `DECIMAL(15,2)` storage range remains readable for legacy display without relaxing new-entry input limits.
- Draft/Needs Correction edits preserve submitted line IDs where retained. Explicitly changing a structured line's selected account updates its label; saving the same account preserves its stored label. Persisted journals cannot move to another client.
- Client changes clear account, period and document selections. Requests for obsolete client options are cancelled. Add/remove/reindex controls work in full-page and modal forms and do not clone persisted line IDs.
- Existing Bookkeeper submission and reviewer permissions remain in force. Self-review is denied. Returning either For Review or Reviewed entries to Needs Correction clears current `reviewed_by`/`reviewed_at`, while previous audit records remain.
- Account/period validation and journal writes use the existing client-lock protocol plus the journal row lock. A stale edit cannot overwrite a submitted entry, and a competing account deactivation is observed before submission.

**Supporting evidence**

Evidence is optional. Authorized same-client documents can be associated without copying their files. Each association retains document ID, number/title, private file path/name/type, SHA-256 digest where a file exists, attachment actor/time, and an optional detachment timestamp. Documents without attachments can be associated as metadata-only evidence.

Replacing a source attachment does not change the version attached to a journal. Its original private file remains available through a separately authorized evidence download. While the journal is editable, the user may explicitly choose the current replacement version. This detaches the old association, retains it in history, and appends a new snapshot. Ordinary saves preserve the selected version. Source documents with retained evidence cannot move to another client.

Download requires access to both the journal and source document, plus the existing document-download permission. The retained file's hash is verified before download, submission and review. Missing/tampered evidence fails closed. Archived source documents remain available through authorized historical evidence; they cannot be newly attached. Evidence snapshots cannot be edited or deleted through the model, and associations cannot be changed through the journal editor after submission. Existing document approval/reopening protections remain intact.

**Database changes**

One additive migration: `database/migrations/2026_10_10_000005_create_journal_documents.php`.

It creates `journal_documents` and adds the composite unique document index needed for ownership references. Composite foreign keys enforce that evidence, journal and source document share the same client. Source records and attachment actors use restrictive deletion rules. The entry/detachment index supports evidence-history queries. Rollback refuses to discard any retained evidence; empty rollback and reapplication are covered by the migration suite.

No historical migration was rewritten. No journal/account backfill, label rewrite, status conversion, or file duplication is performed by this migration. As with existing accounting services, raw database writes are outside Eloquent/service authorization and must not be used as application workflow shortcuts.

**Routes and authorization**

Existing `/ledger` CRUD and `/ledger/{record}/transition` routes remain. Added:

| Route | Authorization |
| --- | --- |
| `GET /ledger/options?client_id=…` (`ledger.options`) | Bookkeeping view, account view, client view and client scope; document options additionally respect document permissions |
| `GET /ledger/{record}/evidence/{evidence}` (`ledger.evidence`) | Journal view, matching evidence ownership, source-document download authorization and hash verification |

No new permission names, role grants or production permission updates were introduced in Stage 2.3. Existing `bookkeeping.*`, `account.view`, `client.view`, `document.view` and `document.download` permissions are reused. The Stage 2.2 permission rollout still needs review before deployment to an existing business database.

**Legacy and business-data preservation**

Unmapped legacy journals remain visible with their original IDs, account labels, amounts, descriptions, references and statuses. Their financial content is read-only in ordinary CRUD. Submission/review cannot bypass account/period requirements, and no mapping is inferred. Existing Reviewed legacy entries remain Reviewed; they are not posted or automatically treated as posting-eligible. A controlled mapping process requires separate scope and validation.

Tests snapshot legacy records around rejected edits and review attempts, verify exact original content, and preserve nullable references. Migration tests continue to verify fresh installation, legacy upgrade, rollback/reapplication, original rows/indexes and sequence high-water marks. Earlier billing, document-integrity, compliance-notification, account-management and access-control regressions remain included.

Existing journal workflow tests now author clearly synthetic structured fixtures instead of treating the development seeder's legacy free-text lines as submission-eligible. Their security and independent-review assertions remain; explicit legacy-preservation tests cover the newly restricted behavior.

**Executed verification**

PHP 8.3.33 and PHPUnit 11.5.56:

| Check | Actual final result |
| --- | --- |
| Full SQLite 3.53.2 suite | 100 total: 95 passed, 5 MySQL-only skipped; 1,156 assertions; 12.805 seconds |
| Full disposable MySQL 8.4.3 suite | 100 passed, no skips; 1,193 assertions; 54.361 seconds |
| Scoped Laravel Pint | Passed |
| JavaScript syntax checks | Passed for application JS and both browser scripts |
| `git diff --check` | Passed |
| Chrome desktop/mobile workflow | Passed; no JavaScript exceptions |

The 15 added test cases include journal references/periods, balanced/unbalanced and malformed/duplicate lines, precise decimal handling, document authorization/integrity/replacement/history, independent review/correction, legacy read-only behavior, evidence foreign keys/rollback protection, and two MySQL concurrency cases. Concurrency tests use separate PHP processes and observe an InnoDB lock wait before committing the competing transaction. They prove that an account deactivation prevents submission and that an edit waiting behind submission is denied.

Browser checks exercised the existing Stage 2.2 account/template workflows and the new journal workflow: Bookkeeper login, client-dependent option loading and clearing, account/period/document selection, add/remove/reindex controls, modal editing, `0.10 + 0.20 = 0.30` totals, saving, submission, Manager review and return for correction. Desktop and mobile screenshots were inspected. A strict mobile check exposed hidden accessibility labels extending outside the table scroll container; a journal-only positioning rule fixed it. The final device/client/scroll widths are all 390 pixels. Table contents scroll within the form. These are functional smoke checks, not exhaustive cross-browser or accessibility certification.

Synthetic screenshots are retained locally (Git-ignored):

- `storage/app/private/stage23-verification/journal-form-desktop.png`
- `storage/app/private/stage23-verification/journal-form-mobile.png`
- `storage/app/private/stage23-verification/journal-reviewed.png`

All migrations, seeds and writes ran only against SQLite `:memory:` or a fresh disposable MySQL instance. The inherited fail-closed guard verified testing mode, loopback/high port, dedicated user, database-name pattern, marker token, actual server data directory and foreign-key enforcement. The existing `.env` and business database were not changed. Browser uploads were isolated in a temporary storage directory. Test servers, profiles, database files and credentials were removed after verification.

Reproduction:

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
# Only after provisioning a separately verified disposable MySQL server:
php vendor/bin/phpunit --configuration <temporary-verified-phpunit.xml> --do-not-cache-result
```

For browser tests, export the verified disposable connection/guard environment, run `php tests/Browser/prepare.php --journal`, start `php -S 127.0.0.1:<port> -t public tests/Browser/server.php`, and run:

```powershell
node tests/Browser/chart-of-accounts.mjs http://127.0.0.1:<port> <chrome-executable> <temporary-artifacts> tests/Browser/general-journal.mjs
```

The guard is mandatory for preparation and every request through this test router. Do not use these helpers against a business database. The Windows headless browser needed execution permission outside the sandbox; it used a temporary profile and the guarded local server.

**Files created for Stage 2.3**

- `app/Models/JournalDocument.php`
- `app/Services/Accounting/JournalWriter.php`
- `app/Services/Accounting/JournalEvidence.php`
- `database/migrations/2026_10_10_000005_create_journal_documents.php`
- `resources/views/ledger/form.blade.php`
- `resources/views/ledger/form-content.blade.php`
- `tests/Feature/GeneralJournalTest.php`
- `tests/Feature/JournalConcurrencyTest.php`
- `tests/Support/JournalFixtures.php`
- `tests/Support/journal-workflow-worker.php`
- `tests/Browser/general-journal.mjs`
- `docs/STAGE-2.3.md`

**Files modified relative to Stage 2.2**

- `app/Http/Controllers/LedgerController.php` — form/options, service-based transitions, protected evidence downloads.
- `app/Http/Requests/RecordRequest.php` — structured journal validation.
- `app/Models/LedgerEntry.php` — evidence relationship, legacy detection, exact totals.
- `app/Models/Document.php` — protect ownership of referenced evidence sources.
- `app/Policies/BookkeepingPolicy.php` — preserve unmapped legacy financial content.
- `app/Services/RecordWriter.php` — delegate journal writes to the accounting service; remove unreachable old journal-writing code.
- `app/Services/Accounting/JournalEligibility.php` — reject duplicate account/amount rows at submission/review.
- `app/Support/Money.php` — exact journal formatting and legacy-storage parsing; signed decimal fix preserved.
- `app/Support/Modules.php` — General Journal display title, existing module key retained.
- `routes/web.php` — options and evidence endpoints.
- `resources/views/ledger/detail.blade.php` — exact totals, period/legacy state, correction actions and evidence history.
- `public/app.js` — client-dependent options, safe line controls, exact journal totals and accessible reindexing.
- `public/laravel.css` — contain journal table accessibility labels on mobile.
- `tests/Feature/WorkspaceTest.php`, `tests/Feature/RbacTest.php` — structured synthetic workflow fixtures.
- `tests/Feature/AccountingMigrationTest.php` — include the fifth additive migration in rollback verification.
- `tests/Browser/chart-of-accounts.mjs` — optional journal workflow and clearer harness errors.
- `tests/Browser/prepare.php`, `tests/Browser/server.php` — synthetic journal fixtures and isolated browser file storage.

Earlier uncommitted stage changes remain in the workspace and are not new Stage 2.3 changes. `workspace.code-workspace` was left untouched.

**Remaining boundaries and Stage 2.4 recommendation**

No known failing Stage 2.3 check remains. Period administration/closing screens and controlled legacy mapping are not introduced here; account/period structures must be configured through approved administration before operational use. RBCIA still needs to validate its actual chart, fiscal periods, opening balances, reporting conventions and any migration plan. Supporting evidence remains optional.

Subject to separate approval, Stage 2.4 should implement authorized, idempotent transaction posting from independently Reviewed, fully eligible journals; revalidate accounts, period and evidence inside the posting transaction; preserve immutable posted entries/lines and audit metadata; and test concurrent posting/period closure and retry behavior. Do not automatically post legacy Reviewed records. Financial statements and production migration remain outside this implementation.
