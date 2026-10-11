# Render + Clever Cloud classroom demonstration

This configuration is for synthetic classroom data only. The demo was deployed
on 2026-10-11 at https://veritas-core-college-demo.onrender.com using Render Free
in Frankfurt and a dedicated Clever Cloud MySQL DEV database. Never import the
local accounting database. See RENDER-DEMO-RESULTS.txt for the verified results.

## Accounts and first deployment

1. Create Render and Clever Cloud accounts. Create a NEW, EMPTY Clever Cloud
   MySQL DEV add-on. Keep credentials in the hosting dashboards, not in Git/chat.
2. Connect this repository to Render and create a Blueprint from `render.yaml`
   on the `feature/render-demo` branch.
   Select the free plan. The Dockerfile builds PHP/Apache and Vite assets.
3. Generate an application key locally with `php artisan key:generate --show`.
   Enter the full `base64:...` value as APP_KEY in Render and retain it securely.
   Do not regenerate it on restart. Set APP_URL to the HTTPS Render service URL.
4. Enter the add-on's host, port, database, user and password as the corresponding
   DB_* settings. Configure provider-supported TLS and its CA before use; never
   disable certificate verification to solve a connection error.
5. On the FIRST deployment only, set DEMO_RUN_MIGRATIONS=true. The startup script
   runs normal migrations, never migrate:fresh, reset, or import. After success,
   set it back to false. Check logs for every migration and connection failure.
6. Provision roles/permissions and private demo login accounts before presenting.
   Do NOT run DatabaseSeeder in production or expose its known development
   passwords. Account provisioning and a hosted login check remain required.

## Trigger exception

Both DEMO_MODE=true and DEMO_SKIP_ACCOUNTING_TRIGGERS=true are required to skip
accounting triggers in fresh migrations. Defaults retain triggers. Application
authorization, review, posting, cent arithmetic, foreign keys and unique indexes
remain in place. Direct SQL can bypass protected-record immutability in this mode.
These flags do not remove existing triggers or install previously skipped ones.
Do not switch an existing database between modes. A later real deployment needs
a fresh fully protected database or a separately reviewed forward migration.

## Free hosting limitations

- Render sleeps when idle. Local uploads are lost on sleep/restart/redeployment.
  Documents remain access-controlled but are NOT durable on this setup. Use only
  disposable sample files until persistent private object storage is configured.
- The daily notification scheduler is not run by the web container. Scheduled
  notifications require a separate scheduler; manual actions still work.
- Mail uses logs; emails are not delivered. Queue jobs execute synchronously.
- Clever Cloud DEV is for testing with restricted storage/connections. Verify
  current limits and database privileges in the actual account before migration.
- No automatic deployment is enabled. The /up endpoint is a process health check,
  not proof of database connectivity, successful migrations or usable logins.
- TRUSTED_PROXIES=* is intended for Render's managed network edge only.

## Validation before calling the deployment complete

Build the Docker image, migrate the empty hosted database, provision secure demo
accounts, verify login and each voucher workflow and financial report over HTTPS,
check mobile/printing, and verify attachment behavior after a restart. Keep secrets
out of screenshots/logs and never copy .env or real accounting data into the image.

## Local verification (2026-10-11, Asia/Singapore)

- Demo-only migration and four-voucher workflow tests: 2 passed, 48 assertions.
- Clean full regression: 306 passed, 14 skipped, 2,098 assertions, 56.061 seconds.
  Command: `php -d extension=zip vendor/bin/phpunit --colors=never`.
  Database: SQLite :memory:, enforced by the existing test database guard.
  MySQL-only tests were skipped; Clever Cloud compatibility is not yet verified.
- `npm.cmd run build`: passed, 58 modules transformed.
- PHP syntax checks and `git diff --check`: passed.
- Initial runs exposed the local disabled ZIP extension. Two overlapping reruns
  also conflicted over fake attachment files. The final run above ran alone with
  ZIP explicitly enabled and passed. No real database migrations were run.
- Docker is unavailable locally; the Docker image build, live migration, account
  provisioning, HTTPS/mobile/printing and restart checks remain pending.

## Hosted verification (2026-10-11, Asia/Singapore)

- Render successfully built the Docker image from commit 5627624.
- All 15 migrations applied to the verified empty Clever Cloud database.
  It contains 38 tables, 72 permissions, one private owner login, zero clients,
  and zero triggers. Normal application checks remain enabled.
- The owner has an explicitly provisioned posting permission for this demo.
  Independent review remains enforced: create separate staff accounts in Users
  to demonstrate preparing and reviewing a voucher as different people.
- HTTPS login POST redirected to the dashboard; all 11 authenticated module
  checks returned 200. Built CSS and JavaScript returned 200; /.env and
  /.git/config returned 404. Frankfurt checks completed in 17.45 seconds total.
- The Singapore deployment worked but had slow database round trips. Frankfurt
  was selected closer to the database, whose address resolves to Clever Cloud's
  Paris network. Suspending the earlier Singapore service was attempted but
  blocked by a local DNS failure; it may still be active. Suspend only the older
  `veritas-core-demo` service manually; retain `veritas-core-college-demo`.
- Browser UI access was unavailable: hosted mobile interactions, print layout,
  attachment restart behavior and complete live voucher transactions were not
  visually verified. Local voucher workflow/printing tests passed as above.
- The initial direct database connection was not encrypted. TLS with the local
  trusted CA bundle failed validation; certificate verification was not disabled.
  A trusted provider CA and verified TLS are still required before any real use.
- Mail delivery, scheduled notifications, durable uploads, and database triggers
  are not provided by this free demonstration configuration.
- API keys, database passwords, APP_KEY, and owner credentials remain only in
  Git-ignored local configuration and the required hosting environment settings.
- Final report/configuration changes are committed locally. Pushing this final
  documentation update to GitHub failed because github.com could not resolve.
  The deployed application commit and earlier deployment files are already pushed.
