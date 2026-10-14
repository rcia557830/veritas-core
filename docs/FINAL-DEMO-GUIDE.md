# Final demonstration guide

Phase 6, 2026-10-13 (Asia/Manila). All businesses, contacts, documents and deadlines are fictional. Allow 10–15 minutes for the presentation.

## Local startup and accounts

Use the existing PHP/Composer dependencies and Chrome installation. The PowerShell launcher selects only `database/veritas_demo.sqlite`, checks the database before migrations, sets local mail to the log, and restores process environment variables when it exits. It never changes `.env` or the business database. A cached Laravel configuration is refused.

```powershell
# First setup: enter four private passwords at hidden prompts, then serve.
powershell -NoProfile -ExecutionPolicy Bypass -File tests/start-demo.ps1

# Alternative FIRST setup: random passwords encrypted with Windows DPAPI.
powershell -NoProfile -ExecutionPolicy Bypass -File tests/start-demo.ps1 -GenerateCredentials -PrepareOnly

# Start an already prepared demo. Existing records/passwords are preserved.
powershell -NoProfile -ExecutionPolicy Bypass -File tests/start-demo.ps1
```

Open `http://127.0.0.1:8086`. Use `-Port 8087` if occupied. The generated local demo uses these accounts:

| Email | Role |
|---|---|
| super-admin@demo.example.test | Employee invitations, access and technical logs |
| owner@demo.example.test | Business overview, financial reports and Financial Audit Trail |
| office-manager@demo.example.test | Onboarding, document validation, independent accounting review |
| bookkeeper@demo.example.test | 25 assigned clients, preparation, vouchers and corrections |

If generated credentials were used, retrieve **only your chosen account** in a private local terminal. Never copy the output into source control, screenshots or presentation slides:

```powershell
$demoAccounts = Import-Clixml storage/app/private/phase6-demo-credentials.xml
($demoAccounts | Where-Object UserName -eq 'owner@demo.example.test').GetNetworkCredential().Password
```

The encrypted file can be decrypted only by the Windows account/machine that created it. These are initial demo credentials only; the application retains invitation-based employee creation and self-service password changes/reset. Administrators cannot edit employee passwords. New passwords require 12+ characters and at most 72 bytes. Re-running the seeder never resets a password.

Under the launcher's guarded environment, the underlying commands are `php artisan migrate`, then `php artisan demo:seed --confirm`. The dedicated seeder is `DemoDataSeeder`; do not run the legacy sample seeders for this demonstration. Calling it directly requires `VERITAS_DEMO_DATA=true`; without privately supplied initialization values, it generates unknown random passwords. Prefer the launcher. Never use `migrate:fresh` or `db:wipe` on a business database.

## Suggested 13-minute sequence

1. **Owner overview (1 minute):** show 30 fictional clients, outstanding billing and deadline queues. Explain that business and technical administration are separate.
2. **Document validation (3 minutes):** Office Manager opens `DEMO-005` (Tala Engineering) with its missing Business Permit and open follow-up. Show the checklist, receive a fictional PDF, link it to the requirement and validate it. Readiness and missing counts change together. `DEMO-002` has submitted documents; `DEMO-004` needs clarification; `DEMO-006` is ready to activate. `DEMO-001`, `011`, and `021` are already onboarded. Download a sample PDF to show the fictional watermark.
3. **Bookkeeping (4 minutes):** Bookkeeper opens `DEMO-001`, its JV draft or returned supplies voucher. Review the balanced lines and evidence, save/submit. Office Manager independently reviews and posts it. Open General Ledger and Trial Balance for the current year. Before any demo edits each client has PHP 148,000 debit and credit balances and PHP 15,000 earnings; the Balance Sheet reconciles. Only posted entries affect reports. Explain JV, CR, CV and CD; a voucher references one journal and adds no duplicate amount.
4. **Financial audit and billing (2 minutes):** Owner filters Financial Audit Trail by client/date/reference, opens a transaction and its evidence. Show `DEMO-002`'s PHP 6,500 invoice with PHP 2,000 paid and PHP 4,500 outstanding. Record a distinct PHP 4,500 receipt: balance becomes zero and display status becomes Paid. `DEMO-001` is unpaid, `DEMO-003` fully paid, and `DEMO-004` overdue initially. Firm billing is separate from the client's own books.
5. **Compliance (2 minutes):** show upcoming, overdue, awaiting documents, In Preparation (internal review), and Completed examples. Open a follow-up and explain the ten-calendar-day internal target. All dates are clearly marked illustrative; verify actual government requirements outside the demo. Record a follow-up and complete the example.
6. **Knowledge management and security (1 minute):** search for “Independent accounting review” or “Document intake checklist.” Explain searchable procedures and publishing permissions. Briefly show the Super Admin employee invitation screen and its separate technical navigation.

For a longer security demonstration, Super Admin invites a new `@example.test` employee. Local mail is written to `storage/logs/laravel.log`; privately open the invitation URL, set a password, log in, then change the password in the employee's own profile. The reset flow similarly uses logged mail. No external email is delivered by the launcher. Do not expose tokens on slides.

## Expected initial dataset

30 clients (six per business type), four user accounts, 210 requirements, 204 PDF documents, six document follow-ups, 270 ledger accounts, 30 accounting years/periods, 360 vouchers/journals, 720 journal lines, 360 retained evidence links, 30 invoices/60 items/22 payments, 120 compliance records/24 compliance follow-ups, and 36 published knowledge articles. The October 14 supplement preserves the original eight articles and adds missing procedures, general articles, templates and guidelines. See [local population verification](DEMO-POPULATION-VERIFICATION.md) for the port-8080 configuration and current verification details.

240 journals are Posted; 30 each are Draft, For Review, Reviewed and Needs Correction. Every client has all four voucher types. Bookkeeper cannot access the five manager-assigned clients (codes divisible by six). The initial total firm billing balance is PHP 133,500. Repeat seeding preserves demonstration edits; it does not refresh dates or rewind workflows.

Client contact details are fictional yet pass the application's own client-update validation: phones use `+63 900 000 NNNN` and TINs use `000-000-NNN`. Professional businesses are filed under the generic Professional Regulation Commission (PRC), not a specific professional board.

## Troubleshooting and limitations

- If the launcher refuses a populated unmarked database, keep that file intact. Use a new isolated checkout/demo file after confirming ownership; the launcher never deletes databases. A failed first initialization can leave an unmarked disposable file and PDFs; retain them for diagnosis before preparing another copy.
- If credentials were lost, use self-service reset with the local log mailbox. The provisioning command will not overwrite existing employee passwords. A DPAPI vault from another Windows account/machine cannot be decrypted.
- Missing attachments require restoring the matching private files, not reseeding reviewed evidence. PDFs live in `storage/app/private/phase6-demo/` and must stay private.
- Compliance urgency changes as time passes; dates are anchored to the first seed date. Start a new isolated dataset for a future presentation if fresh relative deadlines are needed.
- Bootstrap, icons and fonts retain existing external delivery dependencies. Rehearse with the actual demonstration network and installed Chrome; browser coverage is desktop and 390px mobile emulation.
- This is a local SQLite demo; MySQL behavior is verified separately on a guarded disposable instance. No production host, SMTP delivery, government filing integration, or multi-device certification is implied. Do not claim measured time savings without evaluation data.

## Regression commands

Run database suites sequentially. The MySQL harness creates its own token-verified database/server/data directory on a random high port; it does not use `veritas_core_db`.

```powershell
php vendor/bin/phpunit --log-junit storage/app/private/phase6-sqlite.xml
powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-accounting.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-accounting.ps1 -BrowserOnly -SecurityBrowser
powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-accounting.ps1 -BrowserOnly -RoleBrowser
powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-accounting.ps1 -BrowserOnly -DemoBrowser
```

## Production handoff (no deployment)

There are no new Phase 6 schema migrations. The demo launcher reports its migration status. The real database's pending migrations were deliberately not queried or applied; an authorized operator must review them during deployment planning.

Before go-live, approve roles/posting grants, take a matched database/private-file backup and rehearse restore into a separate target. Preserve APP_KEY. Confirm `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure HTTP-only cookies, trusted proxies, private storage permissions, least-privilege database access, and durable storage. Keep `VERITAS_DEMO_DATA` disabled; never include the demo vault/database/files in deployment artifacts.

Configure and test invitation/reset SMTP delivery. `config/mail.php` reads `MAIL_SCHEME` (the production template now uses that key). Verify transport security with the actual provider. Configure `schedule:run` every minute for the existing 07:00 `veritas:notify` task in Asia/Manila. Run and monitor workers if the database queue is used. Confirm rotating security logs, audit access, backup retention, failure alerts and production smoke tests. See [deployment checklist](FINAL-DEPLOYMENT-CHECKLIST.md) and [backup runbook](BACKUP-AND-RECOVERY.md); production approval remains a separate gate.
