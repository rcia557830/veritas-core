# Stage 2.2 — Chart of Accounts and template initialization

Implemented on October 10, 2026 (Asia/Manila). Scope stops at account management and template initialization. No journal posting or Stage 2.3 workflow is introduced.

The client Chart of Accounts supports client selection, code/name search, classification and status filters, pagination, account creation/editing, activation/deactivation, and account-specific audit history. It uses the existing Blade components, navy/gold styles, navigation, validation feedback and confirmation modal. Accounting submissions show a saving state.

Shared templates support creation, editing, active/inactive status, items, and version cloning. A cloned version starts inactive for review. Definitions are copied into independent client accounts; template changes never propagate into client accounts.

**Integrity and initialization behavior**

- Existing five classifications and SHA-256 scoped code uniqueness remain unchanged. HTTP middleware now leaves `code` untouched so the approved ASCII-only normalization runs consistently; leading zeros and meaningful non-ASCII characters are retained.
- Account ownership and source identifiers cannot be edited through the account form. Posted account identity/classification protection from Stage 2.1 remains in force. Deactivation changes no journal records. There is no account-delete endpoint.
- Initialization requires an authorized Owner, an accessible client, an active template with active items, and explicit confirmation.
- The service takes the client lock, then the template lock. Account creation and audit records commit together. Any independent or different-version code collision rejects the entire initialization without overwriting, adopting, merging, or partially copying accounts.
- Repeating initialization skips accounts by source item ID. Edited or deactivated client accounts remain unchanged, including their codes and status. Retrying also leaves the audit unchanged when nothing is copied.
- Templates with copied accounts cannot gain, lose, or change item definitions/status, or change name/version. They may be activated/deactivated. A new version is required for definition changes. Model guards and transactional service checks share the template lock.
- Existing account labels, journal IDs, statuses, descriptions, amounts and references remain untouched. No automatic legacy mapping or opening-balance import occurs.

**Permissions**

| Permission | Owner | Office Manager | Bookkeeper |
| --- | --- | --- | --- |
| `account.view` | Yes | Yes | Assigned clients only |
| `account.create` | Yes | Yes | No |
| `account.update` | Yes | Yes | No |
| `account.deactivate` (activate/deactivate) | Yes | Yes | No |
| `account.initialize` | Yes | No | No |
| `account-template.manage` | Yes | No | No |

Account access also requires `client.view` and access to the relevant client. Policies check live permission grants, active user status, and role restrictions. Revoking permission blocks the action even for an Owner. All writes are authorized server-side, including service entry points.

These are configuration definitions and development/test grants. No permissions or roles were changed in the existing business database. Existing deployments will not gain new access automatically. Their actual role configuration must be reviewed before an explicitly approved permission rollout. Do not run the existing broad `PermissionSeeder` against production to activate this feature: it synchronizes complete role grants.

**Routes**

| Methods and path | Purpose |
| --- | --- |
| `GET /accounts` | Select client, list/search/filter |
| `GET /accounts/create`, `POST /accounts` | Account creation |
| `GET /accounts/{account}` | Details and audit history |
| `GET /accounts/{account}/edit`, `PUT/PATCH /accounts/{account}` | Eligible edits |
| `POST /accounts/{account}/status` | Activate/deactivate |
| `GET /accounts/initialize`, `POST /accounts/initialize` | Preview/confirm initialization |
| `GET /account-templates` | Template list |
| `GET /account-templates/create`, `POST /account-templates` | Template creation |
| `GET /account-templates/{accountTemplate}` | Version details/items |
| `GET /account-templates/{accountTemplate}/edit`, `PUT/PATCH /account-templates/{accountTemplate}` | Template edit/status |
| `POST /account-templates/{accountTemplate}/version` | Clone into next inactive version |
| `GET /account-templates/{accountTemplate}/items/create`, `POST /account-templates/{accountTemplate}/items` | Add an unused version's item |
| `GET /account-templates/{accountTemplate}/items/{item}/edit`, `PUT /account-templates/{accountTemplate}/items/{item}` | Edit an unused version's item |
| `DELETE /account-templates/{accountTemplate}/items/{item}` | Remove an unused version's item |

Route names use `accounts.*` and `account-templates.*`; account/template resource bindings are separate from the existing generic `{record}` binding.

**Database changes and safety**

No Stage 2.2 migrations or schema changes were needed. Historical migrations, Stage 2.1 tables/constraints, and the fail-closed test guard are preserved. No additional packages were installed. All executed migrations, wipes, seeds and accounting writes targeted SQLite `:memory:` or a separately provisioned MySQL 8.4.3 instance with a fresh temporary data directory, loopback-only high port, dedicated test credentials, marker token and verified `@@datadir`. The existing `.env` was not edited. After verification the disposable MySQL and HTTP servers were stopped, and temporary database files, credentials and browser profiles were removed.

SQLite uses the existing client writer-lock strategy and an equivalent template writer lock. MySQL uses `FOR UPDATE`. Scoped unique keys remain database-enforced in both engines. Raw query-builder/bulk SQL writes bypass Eloquent events and service authorization; future application writes must use the accounting services. Database administrators are not constrained by application policies.

**Verification**

Final runs used PHP 8.3.33 and PHPUnit 11.5.56:

| Verification | Actual result |
| --- | --- |
| Full SQLite 3.53.2 suite | 85 total: 82 passed, 3 MySQL-only skipped; 980 assertions; 9.691 seconds |
| Full disposable MySQL 8.4.3 suite | 85 passed, no skips; 1,003 assertions; 39.807 seconds |
| Scoped Laravel Pint | Passed |
| `node --check` on application JS and browser test | Passed |
| `git diff --check` | Passed |
| Chrome desktop/mobile smoke test | Passed; no JavaScript exceptions |

There were no failures or errors in the final suites. They include the complete Increment 1 and Stage 2.1 suites, account workflows and invalid inputs, all five classifications, normalization, scoped uniqueness, client isolation, revoked permissions, historical protection, template versions, repeated initialization, atomic rollback, and business-record preservation. Stage 2.1 migration installation/upgrade/rollback/reapplication tests continue to pass on both engines. Snapshot assertions confirm chart operations leave synthetic ledger entries/lines, documents, compliance records, invoices/items and payments unchanged.

MySQL concurrency tests use separate PHP processes. They observe an actual InnoDB lock wait before releasing the first transaction. One proves a concurrent initialization retains exactly two accounts and two audit records. Another proves a concurrent item addition is rejected once the first initialization commits. The Stage 2.1 concurrent-period test also runs. The test-only MySQL user needs read access to `performance_schema.data_lock_waits` for the new lock-wait assertions.

Browser verification used Chrome headless through the Chrome DevTools Protocol, with no new dependency. The first Windows-sandbox launch was blocked; the same isolated test succeeded with elevated execution permission. Verified Owner login, client selection, create/edit/deactivate, confirmation dialogs, search/status filtering, duplicate validation, template/item creation, initialization and version cloning. No JavaScript exceptions were observed. Desktop (1440×1000) and mobile (390×844) screenshots were inspected; the mobile table uses the existing horizontal table scrolling without page overflow. This is a smoke test, not exhaustive cross-browser/accessibility certification. Other roles and denied requests are covered by HTTP/policy regression tests.

Synthetic browser screenshots are retained locally in `storage/app/private/stage22-verification/`: `client-chart.png`, `client-chart-mobile.png`, `account-details.png`, `template-version.png`. They are ignored by Git and contain clearly labeled synthetic data.

Reproduction commands (use the installed PHP executable if `php` points at another runtime):

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
# Only after provisioning and verifying a disposable MySQL instance:
php vendor/bin/phpunit --configuration <temporary-verified-phpunit.xml> --do-not-cache-result
```

For browser smoke tests, export that verified disposable connection/token/datadir as process environment variables, run `php tests/Browser/prepare.php`, then start `php -S 127.0.0.1:<port> -t public tests/Browser/server.php`. Both helpers check the existing test guard before database work; they require verified MySQL. Run `node tests/Browser/chart-of-accounts.mjs http://127.0.0.1:<port> <chrome-or-edge-executable> <temporary-artifact-directory>`. The test uses the development-only seeded Owner credentials. Shut down the test server and remove disposable data/credentials afterward; never point these helpers at a business database.

**Files created**

- `app/Http/Controllers/AccountController.php`
- `app/Http/Controllers/AccountTemplateController.php`
- `app/Policies/AccountPolicy.php`
- `app/Policies/AccountTemplatePolicy.php`
- `app/Services/Accounting/ChartOfAccounts.php`
- `app/Services/Accounting/TemplateManager.php`
- `app/Services/Accounting/TemplateTransaction.php`
- `resources/views/accounts/index.blade.php`
- `resources/views/accounts/definition.blade.php`
- `resources/views/accounts/form.blade.php`
- `resources/views/accounts/show.blade.php`
- `resources/views/accounts/initialize.blade.php`
- `resources/views/account-templates/index.blade.php`
- `resources/views/account-templates/form.blade.php`
- `resources/views/account-templates/show.blade.php`
- `resources/views/account-templates/item.blade.php`
- `tests/Feature/ChartOfAccountsTest.php`
- `tests/Feature/AccountInitializationConcurrencyTest.php`
- `tests/Support/account-initialization-worker.php`
- `tests/Browser/server.php`
- `tests/Browser/prepare.php`
- `tests/Browser/chart-of-accounts.mjs`
- `docs/STAGE-2.2.md`

**Files modified relative to Stage 2.1**

- `app/Models/AccountTemplate.php` — locked identity/version protection.
- `app/Models/AccountTemplateItem.php` — locked protection of entire used versions.
- `app/Providers/AppServiceProvider.php` — policy registration.
- `bootstrap/app.php` — preserve account-code input before canonicalization.
- `config/rbac.php` — permission definitions and conservative development grants.
- `routes/web.php` — dedicated account/template routes.
- `resources/views/partials/sidebar.blade.php` — authorized navigation links.
- `public/app.js` — saving feedback limited to accounting forms.

Earlier uncommitted Increment 1/Stage 2.1 changes remain in the workspace. They are not new Stage 2.2 changes. `workspace.code-workspace` was left untouched.

**Remaining scope and limits**

There is no automatic upgrade/merge from one template version into an already initialized chart: conflicting codes fail safely. Concurrent cloning from different source versions may produce a friendly unique-version conflict that requires refreshing; this cannot create a duplicate or partial version. Account history shows audited account-management actions, not a posted general-ledger report. Search sorting/case behavior follows the database collation; canonical code uniqueness is consistent across MySQL and SQLite.

Before real use, RBCIA must validate actual chart definitions and accounting conventions, and existing role grants must be reviewed. No unverified firm templates were seeded. The synthetic demonstration is not a claim of readiness to replace GLS.

Recommended Stage 2.3, subject to separate approval: structured General Journal forms selecting active same-client accounts and periods; exact debit/credit validation; draft/submission/review eligibility enforcement through accounting services; ownership, period, access and concurrency regression tests. Continue preserving nullable legacy references without automatic mapping. Posting and closing remain separately approved later work.
