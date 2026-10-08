# VERITAS CORE - RBCIA Accounting Firm

Laravel 12 / PHP 8.2+ / MySQL application converted from the existing VERITAS CORE frontend. It keeps the navy and gold theme, Manrope typography, sidebar, cards, responsive tables, modal forms, notifications and Ctrl+K workspace search. Business data lives in the database; JavaScript handles interface interactions only.


## Current updates

The three-role RBAC upgrade and usability improvements are implemented. See [role matrix and upgrade instructions](docs/RBAC.md), [complete RBAC code](docs/RBAC-CODE.md), and [file-by-file UI changes with full code](docs/UI-CHANGES.md).

Pagination defaults to 10 with 25/50 options. Billing summary cards use all authorized invoices and remain unchanged by table filters. Office Managers oversee clients, validate documents, review bookkeeping, manage compliance/notices/knowledge and billing; only Owners administer accounts/settings. Bookkeepers retain assigned-client access, prepare their own bookkeeping, and can author their own draft articles. See the matrix for exact workflow limits.

## Already installed on this computer

Project: `C:\laragon\www\veritas-core`  
Database: `veritas_core_db`  
Local URL: **http://127.0.0.1:8000** while the development server is running.

The database has been migrated and seeded, dependencies installed, and the public storage link created. You do not need to repeat installation on this computer. Start MySQL in Laragon and, if needed, run:

```powershell
cd C:\laragon\www\veritas-core
php artisan serve --host=127.0.0.1 --port=8000
```

The Apache virtual host is configured and tested. Windows denied editing the hosts file, so `veritas-core.test` needs the hosts entry described below before that address resolves.

## Install from the source ZIP on another computer

1. Extract the `veritas-core` directory into `C:\laragon\www`. Start MySQL and Apache in Laragon. Select PHP 8.2 or newer. PHP must include `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`, `ctype`, `tokenizer`, `session` and `gd`. Tests also use `pdo_sqlite`.
2. Open Laragon Terminal (PowerShell), then run:

```powershell
cd C:\laragon\www\veritas-core
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Only copy `.env.example` and generate a key for a **new** installation. Preserve the existing `.env` and application key when updating an installation.

3. In HeidiSQL/phpMyAdmin or the MySQL console, create the database:

```sql
CREATE DATABASE veritas_core_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. Check these values in `C:\laragon\www\veritas-core\.env`:

```dotenv
APP_NAME="VERITAS CORE"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://veritas-core.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=veritas_core_db
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
```

Use your actual MySQL password if you changed Laragon's default. Then run:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
```

The development seeder creates sample users, clients, documents, balanced ledger entries, compliance requirements, invoices, payments and articles. Sample documents have metadata but no actual attachments; edit one to upload a file. Existing browser/localStorage records from the old prototype are not automatically imported.

Do not use `migrate:fresh` on a database containing records you want to keep: it drops tables. Normal updates use `php artisan migrate`.

## Laragon `.test` address

Copy `docs/laragon/veritas-core.conf` to:

`C:\laragon\etc\apache2\sites-enabled\veritas-core.conf`

If Laragon has generated an `auto.veritas-core.test.conf`, use a single virtual host for this name rather than keeping conflicting definitions. The included configuration serves **only `public`**, limits this development site to the local machine, and denies access to the project source directory.

Using an owner text editor, add this line to `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 veritas-core.test
```

Reload Apache using Laragon, then open **http://veritas-core.test**.

If hosts-file permissions or virtual hosts are unavailable:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Open **http://127.0.0.1:8000**. When using this address for password reset links and scheduled notifications, set `APP_URL=http://127.0.0.1:8000` in `.env`, then run `php artisan config:clear`.

## Development accounts

| Role | Email | Password |
|---|---|---|
| Owner | owner@veritascore.local | password123 |
| Bookkeeper | bookkeeper@veritascore.local | password123 |
| Office Manager | manager@veritascore.local | password123 |

**Development credentials only. Change these passwords before deployment.** The seeder refuses to run outside local/testing environments and does not reset an existing user's password when rerun.

## Everyday workflow

- Owner creates bookkeeper accounts in **User Management**, then assigns clients using the client form. Bookkeeper-created clients are assigned to their creator. Bookkeeper see only assigned clients and their related records.
- **Documents:** upload PDF, DOC/DOCX, XLS/XLSX, JPEG or PNG (up to 20 MB), review status, add notes and download authorized attachments.
- **Ledger Review:** enter at least two lines; each line has either a debit or a credit. Unbalanced drafts can be saved, but submission requires equal positive totals. A different Owner or Office Manager reviews or returns the entry with correction notes. Only drafts can be deleted.
- **Compliance:** assign bookkeeper, set a deadline, and edit to mark Filed with a filing date and reference. Overdue/urgency are calculated from dates in the Asia/Manila timezone.
- **Billing:** create draft invoice items with quantities and prices, enter tax as a PHP amount, and issue the invoice. Issued invoices are locked for editing. Record partial/full payments, print, or download a PDF. Overpayments are rejected; an owner can cancel an unpaid invoice. Totals and balances derive from items and payments.
- **Knowledge:** Owners and Office Managers author Draft/Published/Archived articles; Bookkeepers can read Published articles and create/edit their own drafts. Content is safely displayed as plain text with line breaks.
- **Reports:** choose a module and filter by date, client, status or type. CSV exports all matching authorized records. Print page prints the displayed page and the summary for the complete filtered result. Invoice PDFs are available separately from invoice details.
- **Workspace:** owner manages firm details, logo, PHP currency, page size and notifications.
- **Profile:** update name/email or change password with the current password. Owners can deactivate users and request reset links. Deactivation or role/password changes invalidate existing sessions.
- **Search:** Ctrl+K (Cmd+K on macOS), arrow keys and Enter. Queries/search results respect the same server-side access rules as detail pages.

Archiving clients is reversible through their profile. Other removed records are soft-deleted and retained in the database, without an end-user restore interface. Invoices use cancellation rather than deletion. Payments are retained and do not have an edit/delete interface.

## Frontend assets

No Vite or npm build is required. The application uses `public/styles.css`, `public/laravel.css` and `public/app.js` directly. Bootstrap 5.3.3, Bootstrap Icons 1.11.3 and Manrope load from CDN/Google Fonts, so the full styling and modal behavior require an internet connection. The original source frontend remains separately in the RBCIA workspace.

## Upload storage and PHP limits

Client attachments are private at `storage/app/private/documents`; only an authenticated, authorized download controller can serve them. They are intentionally not in the public storage symlink. Firm logos are stored under `storage/app/public/logos` and served through `public/storage`.

In Laragon's selected PHP `php.ini`, set `upload_max_filesize=20M` and `post_max_size=25M` (or higher) to enable the app's full upload limit, then restart Apache. Smaller PHP limits take precedence. Use `php --ini` to find the CLI configuration and ensure Apache uses the intended PHP version.

## Password resets and email

Development uses `MAIL_MAILER=log`. Reset messages and links appear in `storage/logs/laravel.log`; no email is delivered externally. For actual delivery configure your SMTP provider's mailer, host, port, username, password and verified sender in `.env`, then run `php artisan config:clear`. Set `APP_URL` to the address users actually access.

## Notifications and scheduler

Database notifications include document status changes, ledger review events, compliance assignments/deadlines and overdue invoices. The bell loads current notifications and supports mark-one/all read. Notifications are reauthorized when displayed, including after a client's assignment changes.

Generate reminders manually:

```powershell
php artisan veritas:notify
```

The same command is scheduled daily at 07:00 Asia/Manila. Configure Windows Task Scheduler to run once per minute:

- Program: your Laragon `php.exe` (for example `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`).
- Arguments: `artisan schedule:run`
- Start in: `C:\laragon\www\veritas-core`

During development `php artisan schedule:work` is an alternative. Reminder generation also runs for the signed-in user when opening notifications. An event key prevents duplicate reminders for the same event and recipient.

## Tests and validation

```powershell
php artisan test
php vendor/bin/pint --test app bootstrap config database routes tests
```

`phpunit.xml` forces an isolated SQLite memory database; tests do not reset `veritas_core_db`.

To check MySQL behavior, create the dedicated test database once:

```sql
CREATE DATABASE veritas_core_test_codex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then run:

```powershell
php vendor/bin/phpunit --configuration phpunit.mysql.xml
```

This configuration forces `veritas_core_test_codex`, uses the MySQL connection credentials from `.env`, and refreshes only that test database. Do not point test configuration at a live database.

Verified on PHP 8.3.30 and MySQL 8.4.3: **34 tests, 456 assertions**, passing with both SQLite and MySQL. Coverage includes authentication/reset tokens, active accounts, direct-URL RBAC, assignment privacy, all main page/form rendering, CRUD, private uploads and validation, balanced ledger review, integer-cent invoice calculations, partial payments/overpayments, PDF output, notifications, filters, CSV and settings. Live MySQL HTTP checks also passed for login, main pages, search, notifications, CSV and PDF through the local server; the Apache virtual host returned HTTP 200. Browser visual/interaction checks were not completed because the computer-use helper was unavailable. See `docs/ACCEPTANCE.md` for the browser checklist.

## Files and architecture

- `routes/web.php`: named routes, authenticated/active groups and owner gates.
- `app/Http/Controllers`: auth, dashboard, six module controllers, payments, reports, settings, users, notifications, search and audit.
- `app/Http/Requests/RecordRequest.php`: module create/update server validation.
- `app/Http/Middleware/ActiveAccount.php`, `app/Policies/RecordPolicy.php`: account and record authorization.
- `app/Services/Access.php`, `Records.php`, `RecordWriter.php`: scoped querying, filters, transactional writes.
- `app/Services/Summary.php`, `Notify.php`, `Audit.php`: statistics, database notifications and activity logging.
- `app/Support/Modules.php`: module field/status/column definitions used by shared forms and tables.
- `app/Support/Money.php`: decimal/cents conversion; financial operations use integer cents.
- `app/Models`: normalized relationships and derived totals/statuses.
- `database/migrations`: all domain and Laravel system tables.
- `database/seeders/DatabaseSeeder.php`: local development accounts/data.
- `resources/views/layouts`, `partials`, `components`: shared branded interface.
- `resources/views/records`: reusable module index, form, detail, filters and table.
- `resources/views/clients`, `ledger`, `billing`: specialized profiles, review, payments and invoice printing.
- `resources/views/auth`, `dashboard`, `admin`, `settings`, `reports`, `errors`: corresponding pages.
- `public`: application CSS/JS and Laravel entry point.
- `tests/Feature/WorkspaceTest.php`: integration/regression tests.
- `docs/ERD.md`: database diagram and normalization notes.
- `docs/laragon/veritas-core.conf`: local Apache configuration.

## Troubleshooting and deployment

- Connection refused: start MySQL in Laragon, check host/port/password and run `php artisan config:clear`.
- 419: refresh the form and sign in again. CSRF protection is enabled.
- 403: confirm the active role and client assignment. Bookkeeper cannot use owner URLs.
- Blank/old views after changes: `php artisan optimize:clear`.
- Missing logo: run `php artisan storage:link` and check storage folder permissions.
- Missing Composer/PHP: use Laragon Terminal and its selected PHP version.

Before deployment, change development passwords, configure real SMTP, use a dedicated MySQL account, HTTPS, `APP_ENV=production`, `APP_DEBUG=false` and secure cookies. Serve only `public`. Back up MySQL together with `storage/app/private`, `storage/app/public` and the existing `.env` application key. The source ZIP intentionally excludes dependencies, `.env`, uploaded documents, runtime logs and database contents; run `composer install` and migrations after extraction. It is a source distribution, not a backup of business data.
