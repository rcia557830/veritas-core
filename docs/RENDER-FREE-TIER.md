# Veritas Core V2: free-tier deployment procedure

> **Superseded data scope:** follow [the selective deployment review](SELECTIVE-RENDER-DEPLOYMENT-REVIEW.md). The owner now excludes all bulk demo records and 204 generated PDFs. Bulk-copy instructions below are historical and must not be executed. Only approved account/configuration reconciliation is in scope; preserve existing hosted records and legitimate files. Trigger compatibility remains a release blocker.

Prepared 21 October 2026 (Asia/Manila). Local preparation only. **Do not redeploy, import, migrate, create services, or activate billing without the owner's approval.**

## Service and cost constraints

- Keep the existing Render Web Service **veritas-core-college-demo**, Docker runtime, **Free** plan. `render.yaml` now has `plan: free`, no persistent disk, and auto-deploy disabled. Do not create a second service using “New Blueprint”: a matching name does not prove that a blueprint manages the existing service. After approval, apply settings to the existing service ID and deploy that service.
- Keep the existing Clever Cloud MySQL **DEV / free** add-on. The owner confirmed the DEV plan on 21 October 2026; this is owner confirmation, not an independent console/API check. Documentation confirms DEV is free and has no managed backups or SLA. Capacity, grants, TLS requirements and existing contents remain unverified. No remote credentials were available: the local `.env` points to localhost. No remote database backup has been taken, and no import is proposed.
- R2 Standard is a **conditional candidate**, not an activated resource or a guaranteed zero-cost service. It requires subscription checkout and billing/payment setup. Included monthly usage is 10 GB-month, 1 million Class A operations and 10 million Class B operations, with free egress. Excess usage is billable; Infrequent Access is not covered. Account-wide usage includes other buckets. Alerts are not a hard spending cap. **Do not activate R2 or create a bucket without explicit approval of this billing exposure.** If the owner requires no billing-enabled services whatsoever, R2 cannot be treated as satisfying that requirement; keep deployment blocked until an acceptable storage provider is selected.
- Do not add paid workers, cron services, disks, databases, SMTP subscriptions or domains. Retain only an existing verified free mail service; if none is available, outbound mail remains a go-live blocker for invitations and password resets.

Sources: [Render Free](https://render.com/docs/free), [Clever Cloud MySQL](https://www.clever.cloud/developers/doc/deploy/databases/mysql/), [R2 subscription setup](https://developers.cloudflare.com/r2/get-started/), [billing profile](https://developers.cloudflare.com/billing/get-started/create-billing-profile/), [R2 pricing](https://developers.cloudflare.com/r2/pricing/).

## Prepared application configuration

The PHP 8.4 Apache Docker image remains compatible with Laravel 12. Composer includes the S3 Flysystem adapter. Startup caches configuration and views; it never migrates or seeds. Only Apache runs under Supervisor. `QUEUE_CONNECTION=sync` runs jobs inside the requesting process; SMTP latency/failure affects that request, and queued retry processing is not automatic. Existing queued jobs must be inspected before cutover and never silently discarded.

There is no continuously running scheduler. The existing notification page generates due notifications for its user on access. The 07:00 daily task will not run automatically. If needed, an operator can run `php artisan veritas:notify` from an approved trusted machine with production configuration. This writes notifications and requires approval; it is not part of read-only preflight. Do not add keep-alive traffic to evade sleep.

Render sleeps after 15 idle minutes; the filesystem is ephemeral across restart/redeploy. Its 750 free instance hours are shared by the workspace. Monitor bandwidth/build quotas and disable paid overages wherever applicable. A Free plan alone does not prove every workspace charge is disabled.

Use the existing application key and these Render environment settings, supplied through secrets:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Manila
APP_URL=https://veritas-core-college-demo.onrender.com
TRUSTED_PROXIES=*
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
DOCUMENTS_DISK=s3
LOGOS_DISK=s3
AWS_DEFAULT_REGION=auto
AWS_USE_PATH_STYLE_ENDPOINT=true
LOG_CHANNEL=stderr
VERITAS_DEMO_DATA=false
```

Verify the actual service URL. Supply `APP_KEY`, `DB_*`, any required `MYSQL_ATTR_SSL_CA`, SMTP settings, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, and `AWS_ENDPOINT` separately. For R2, the endpoint is `https://<account-id>.r2.cloudflarestorage.com`. Do not set `AWS_URL` to a public domain.

Use a private Standard bucket, a bucket-scoped object read/write token, and disable both public `r2.dev` access and public custom domains. No public policy, storage symlink, signed URL or redirect is used to deliver documents. Laravel authorizes each download and streams the object with private/no-store caching. Journal evidence uses streaming SHA-256 verification. Uploads, duplicate detection and rollback cleanup use the same configured disk. Historical object keys and database references stay unchanged. Firm logos use authenticated streaming and the configured logo disk as well.

## Data preservation and required remote preflight

The fresh local source audit found:

| Data | Count |
|---|---:|
| Clients | 30 |
| Journals / lines | 360 / 720 |
| Vouchers | 360 |
| Notices | 15 |
| Knowledge Base articles | 36 |
| Users | 4 |
| Documents / journal evidence references | 204 / 360 |
| Distinct private PDFs | 204 |
| Audit logs / security events | 2,307 / 58 |

All 204 PDFs match recorded SHA-256 values, total 300,347 bytes. SQLite integrity is `ok` with zero foreign-key violations. Source checksum remains `9b9d28c3ae34511f447f3d82aaa798d456483c311f741c7365cfc01993844821`; therefore all stored password hashes, permissions and other records in that source remain unchanged. The verified local snapshot is in ignored `storage/app/private/transfer-20261020-162112-d02f8867/` (artifact timestamp reflects the host clock). The user-facing preparation date above uses Asia/Manila. Preserve the existing application key separately.

Before even proposing an import:

1. Obtain the Clever Cloud add-on identity from its console/API and corroborate the owner-confirmed DEV plan when access is available. Check current capacity/usage and free cost. A host name or SQL server version cannot establish the billing plan.
2. With approved secure credentials, inspect the server version, schema, table counts, engines, size, grants, foreign keys, triggers, routines and events read-only. Do not run migrations or test mutation probes against the hosted database. Inspect trigger privilege support specifically; never drop accounting protections to accommodate a free tier.
3. Take a consistent dump to ignored local private storage using a credential option file, not passwords in command arguments. Include tables/data, triggers, routines and events; use `--single-transaction --hex-blob --no-tablespaces` for transactional tables. Check engines and concurrent DDL before claiming consistency. If privileges prevent a complete backup, stop. Never log raw credentials or dump content.
4. Hash the dump and prove restoration in an isolated local MySQL instance. Compare all rows (including the four password hashes without displaying them), keys, roles, audit/security history and trigger definitions. Reconcile hosted records against the local source; do not overwrite a populated target merely because the local dataset passed tests.
5. Only after those checks, present a target-specific import/no-import decision, capacity check, artifact checksums and rollback plan for separate approval. No import command is authorized by this document.

The previous local rehearsal verified all 40 tables, 68 foreign keys, 16 accounting/audit triggers and auto-increment high-water marks. That is local evidence, not verification of Clever Cloud compatibility.

## Storage transfer and deployment after separate approvals

1. Freeze source writes and repeat `php scripts/audit-transfer.php` and `php scripts/stage-transfer.php`. Keep private backups, `.env`, SQL, credential files and logs outside Git and the Docker build context. Never upload the entire private directory: it contains backups and credentials as well as PDFs.
2. After storage activation and upload approval, copy only the snapshot's attachment manifest entries to the private bucket under their **identical relative keys**, such as `phase6-demo/...`. Include any retained historical attachments and existing public logos under their existing `logos/...` keys. Reject conflicting existing keys; do not overwrite or delete objects. Check source hashes before upload and stream/read back each destination object for SHA-256 comparison. Do not change database file paths.
3. With a trusted local configuration pointing to the approved database and bucket, run `php artisan veritas:verify-attachments --disk=s3`. This reads current, archived and detached evidence references without changing data. Require 204 objects / 564 references / zero failures for the present snapshot. Retained replacement files mentioned only in historical audit messages must also be copied and verified from the snapshot inventory.
4. Build and smoke-test Docker against a disposable database first. Then, only after explicit redeployment approval, configure and deploy the existing Free service with the verified database and external storage. No startup migrations/imports/seeders. Preserve all four password hashes and the application key.
5. Sign in with each existing role and check authorized PDF downloads, forbidden cross-client access, journal evidence integrity, logos and new PDF uploads. Record hashes, manually restart the service, and repeat. Then allow natural sleep (at least 15 idle minutes), wake it and repeat. Run the attachment verification again from the trusted local machine. Verify direct unauthenticated bucket URLs fail. Acceptance downloads/uploads create audit/data records and belong to this approved phase.
6. Retain independent database/object backups locally or on an already approved free backup destination. Existing `veritas:backup-files` tooling backs up local files, not the R2 bucket: do not mistake it for an external-object backup. Verify remote-object backup hashes and test restores. Rollback requires stopping writes and reconciling any new records, not merely rolling back application code to local storage.

Remote persistence is designed into the application but **has not been verified against R2 or Render**. This remains an acceptance gate, not a completed claim.

## Validation in this preparation

- Full SQLite regression suite: 375 passed, 17 skipped, 5,679 assertions (392 tests, including the first two remote-storage tests).
- Expanded focused storage suite: 3 passed, 20 assertions. Covers selected-disk uploads/downloads, authorization before storage access, legacy duplicate checks using streams, checksum verification/tamper failure and private logo delivery. These use a fake disk, not a live S3 endpoint.
- Composer manifest/lock validation and production platform checks passed on PHP 8.4.25; dependency installation reported no security advisories.
- Source audit before/after is identical, including the entire database checksum and attachment checksums. No application data or password hashes changed.
- Docker build and container smoke testing are pending: `docker version` reports that Docker Desktop is unable to start.
- Clever Cloud DEV is owner-confirmed. Independent account inspection, database backup, managed MySQL acceptance and real R2/Render sleep/restart tests are pending access and the applicable approvals. No cloud resources were activated and no deployment occurred.
