# Render + Clever Cloud classroom demonstration

This configuration is for synthetic classroom data only. No hosted service has
been created by adding these files. Never import the local accounting database.

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
