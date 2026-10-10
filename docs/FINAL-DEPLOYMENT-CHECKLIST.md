# Increment 8.4 — final deployment checklist

**Current gate: NO-GO for RBCIA production.** Complete and sign each item against the actual target environment; the [readiness assessment](FINAL-SYSTEM-READINESS.md) explains the blockers. This is a handoff checklist, not evidence that a production installation was configured.

## Policy and acceptance

- [ ] Obtain approved Chapters 1–3 and map exact objectives/acceptance IDs to [traceability](FINAL-REQUIREMENTS-TRACEABILITY.md).
- [ ] RBCIA approves fiscal years, period cadence, closing/reopening authority and safeguards, opening balances, reversals and report basis.
- [ ] Deliver and UAT an authorized, audited year/period management workflow; initialize and verify open periods for every go-live client before allowing journal work.
- [ ] Approve CBL/COR requirements/exemptions, compliance lead-day and holiday rules, voucher numbering, record retention and backup RPO/RTO.
- [ ] Complete staff role UAT, training, named operator assignment and signed go-live approval.

## Host and Laravel configuration

- [ ] Provision supported PHP/Laravel runtime, required extensions, Composer dependencies, web server and MySQL; size CPU, memory, disk and private backup volume for expected clients/attachments.
- [ ] Serve only `public/`; deny web access to `.env`, Git, `storage/app/private`, logs, database dumps and ZIP archives.
- [ ] Populate `.env` from sanitized `.env.production.example`; use `APP_ENV=production`, `APP_DEBUG=false`, correct `APP_URL`, `APP_TIMEZONE=Asia/Manila`, a generated secret `APP_KEY`, working mail, cache and queue settings.
- [ ] Enforce HTTPS with a valid certificate, redirect HTTP, set secure/HTTP-only/SameSite session cookies, and configure only trusted reverse proxies.
- [ ] Store secrets in an access-controlled vault; restrict `.env` permissions, rotate leaked values and preserve the APP_KEY for recovery. Never commit filled secrets.
- [ ] Use a dedicated least-privilege MySQL account, secure transport where available, verified charset/timezone, connection limits, disk capacity and independent backup credentials.
- [ ] Restrict Owner and Office Manager accounts; verify `permission_role` in the actual database, especially `bookkeeping.post`; disable sample users/passwords and review inactive accounts.

## Storage, operations and recovery

- [ ] Make private attachment storage writable only by the app/backup operator; keep the public disk limited to intended assets, and verify authorized downloads and denied direct URLs.
- [ ] Configure `php artisan schedule:run` every minute and verify `veritas:notify` at the intended local time; monitor failures. Configure workers if deployment uses queued jobs.
- [ ] Schedule database and private-file backups together; approve retention and offsite encrypted copy. Include public logo if required. See [backup runbook](BACKUP-AND-RECOVERY.md).
- [ ] Verify backup manifests and SHA-256, restore into a disposable `veritas_restore_*` target, test representative records and file retrieval, and time a full rehearsal. Record observed RPO/RTO.
- [ ] Configure rotating logs, access/error logs, database/queue/scheduler/backup failure alerts, disk-space checks, HTTPS health and incident contacts. Restrict audit-log administration.
- [ ] Stage deployment with a maintenance/write-freeze plan, versioned code and dependency lock, tested migrations, database/file backup immediately before change, smoke tests and a rollback decision point.
- [ ] Rehearse rollback into a **separate** target; document how code, schema, database and files return to a consistent point without assuming down migrations can safely undo live accounting writes.
- [ ] Complete desktop/mobile UAT and final reconciliation on non-production records, then obtain RBCIA sign-off before importing actual client data.

Do not run `migrate:fresh`, `db:wipe` or the browser fixture script against the normal or production database. The test harness is exclusively for its guarded disposable MySQL instance.
