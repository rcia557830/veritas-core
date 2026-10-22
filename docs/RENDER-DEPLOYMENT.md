> **Current plan: SELECTIVE DEPLOYMENT, FREE SERVICES ONLY — 21 October 2026.** Follow [the selective deployment review](SELECTIVE-RENDER-DEPLOYMENT-REVIEW.md). Import no bulk demo records or generated PDFs. Earlier full-dataset transfer instructions below are historical and must not be executed. No hosted database modification, import, service creation or redeployment is authorized. Historical local rehearsal evidence is retained below.
# Render / Clever Cloud preparation — 15 October 2026 (Asia/Manila)

Status: local conversion verified; cloud unchanged. Docker image build and live deployment are not yet verified. Do not create a Render service, import into Clever Cloud, push deployment changes, or run remote migrations without approval.

Update, 21 October 2026: see [current Docker/WSL diagnosis and repeated migration rehearsal](DOCKER-WSL-DIAGNOSIS-20261021.md). WSL is now installed and both Windows features are enabled; the historical installation diagnosis below is superseded.

The repeated local conversion passed, and the complete isolated MySQL suite now passes: 390 tests, 5,789 assertions, one SQLite-only skip. The earlier full-suite follow-up gap described below is closed. The final audit confirms that SQLite and all 204 attachments remain unchanged.

## Verified source

Source: `database/veritas_demo.sqlite`; configured by the existing `.env`.
Private file root: `storage/app/private`; all 204 distinct referenced attachments are in `phase6-demo/` (300,347 bytes). Documents and journal evidence share these files. Every nonempty file reference resolves inside the private root and matches its recorded SHA-256; none lack a recorded hash. SQLite integrity is `ok`, with zero foreign-key violations.

| Data | Rows |
| --- | ---: |
| Users | 4 |
| Clients | 30 |
| Journals / journal lines | 360 / 720 |
| Vouchers | 360 |
| Notices | 15 |
| Knowledge Base articles | 36 |
| Documents / journal attachments | 204 / 360 |
| Audit logs / security events | 2,307 / 58 |

Source SHA-256: `9b9d28c3ae34511f447f3d82aaa798d456483c311f741c7365cfc01993844821`.

The local MySQL 8.4.3 rehearsal compared every row in all 40 tables, including IDs, soft-deleted records, relationships, evidence, roles and all four current password hashes. Hashes are copied as stored values, never rehashed or reset. Verification normalizes only database representations: JSON key ordering, declared decimal scale, and midnight timestamps in DATE columns. Decimal precision loss and non-midnight times in DATE columns fail verification. All 68 foreign keys pass; all 16 triggers are restored, and mutation probes against posted journals, audit logs and security events are rejected. All 27 source auto-increment high-water marks are preserved. The SQLite file remained unchanged.

This establishes internal integrity and local conversion fidelity. No original-machine checksum manifest was provided, so it does not independently establish byte-for-byte identity with that machine. Do not run the demo launcher/seeders to provision deployment accounts.

## Local tools and artifacts

Run from the repository root:

```powershell
php scripts/audit-transfer.php
php scripts/stage-transfer.php
powershell -NoProfile -File scripts/rehearse-mysql.ps1
```

The runner creates a unique empty MySQL data directory, loopback high port and database, verifies server identity before mutation, creates the schema from migrations, loads all source rows without model events, compares results, and shuts down only its own server. It has no remote import mode. Temporary trigger removal and truncation are restricted to this newly created disposable target, never the source or Clever Cloud. A failed rehearsal retains diagnostic files for review.

Artifacts are ignored by Git under `storage/app/private/`:

- `transfer-audit.json`: source counts and attachment manifest.
- `transfer-20261015-145636-9c031f2b/`: consistent SQLite snapshot, verified referenced private files, public assets if present, and checksums. The application key is deliberately excluded; preserve the existing `.env` securely and supply its existing key separately.
- `rehearsal-*/report.json`: conversion results; `converted.sql` is written only after successful checks. Treat the SQL dump as confidential: it contains credentials and all application records. It remains a local review artifact, not authorization to import.
- `deployment-regression.txt` and `deployment-mysql-regression.txt`: regression output.

There is no need to transfer historical browser-test directories, old local database backups, logs, or DPAPI credential files into the production attachment root. Retain them locally. Stop writes and repeat the snapshot, audit and conversion immediately before an approved cutover.

Final verified conversion: `storage/app/private/rehearsal-552708b1226141ecb2522b68fe5b1c2d/report.json`. Its `converted.sql` is 4,315,609 bytes, SHA-256 `700466d111882297855de33b0221fa4672a941d505c6556c6b443fa622c9fb32`.

## Regression checks

The complete SQLite suite finished with 390 tests, 5,667 assertions and 17 skips. The isolated MySQL voucher suite passed 25 tests / 264 assertions. The full isolated MySQL suite completed 390 tests / 5,775 assertions with one skip and one failure: the Notices seeder test used hard-coded IDs 1 and 2, which do not survive MySQL auto-increment gaps after rolled-back tests. The test now uses actual fixture IDs; application authorization and business records are unchanged. The focused MySQL follow-up passed all eight Demo tests (2,774 assertions). The full MySQL suite was not repeated after this test-only correction. The MySQL runner now accepts `-Filter` for targeted reproduction and invokes the PHP executable directly to preserve filter arguments. Run storage-faking regression suites sequentially because they share a temporary attachment folder.

PHP syntax/style checks and shell syntax validation passed. Container build, browser acceptance and managed-server validation remain pending; these are distinct from successful local conversion checks.

The corrected Notices test also passed separately on SQLite: one test, 177 assertions. An earlier overlapping run failed because another suite reset its shared fake attachments; sequential rerun resolved that test-environment conflict. The real transferred attachment root was unaffected and passed a final audit.

## Docker prerequisite

Historical findings from 15 October follow. Use the linked 21 October diagnosis above for the current state; do not repeat installation solely on the basis of this older section.

Docker Desktop 4.95.0 installed successfully at `C:\Program Files\Docker\Docker`; CLI 29.8.2 responds. The Linux engine is unavailable because WSL is missing. The WSL package installer returned `0x80073d28` (administrator privileges required). This is a Windows installer failure, not an automatic approval-review rejection.

In a Windows **Administrator PowerShell**, complete:

```powershell
winget install --id Microsoft.WSL --exact
wsl --install --no-distribution
```

Restart Windows if requested, open Docker Desktop, complete its first-run prompts, and select Linux containers / WSL 2. Then in a new terminal:

```powershell
docker version
docker build --pull -t veritas-core:render-review .
```

The build must succeed and the container must be smoke-tested against a disposable MySQL database before cloud approval. Do not point a local smoke test at Clever Cloud. See [Docker's Windows prerequisites](https://docs.docker.com/desktop/setup/install/windows-install/).

## Superseded deployment design (historical)

`Dockerfile` packages PHP 8.4 / Apache, production Composer dependencies, MySQL client tooling, queue worker and scheduler. Only `public/` is served. `.dockerignore` uses a source allowlist excluding `.env`, SQLite, attachments, logs, SQL exports and local dependencies. `docker/entrypoint.sh` requires an application key and database endpoint, initializes writable runtime directories and caches configuration/views. It never seeds, imports or migrates.

The former Starter/10 GB disk design is withdrawn. The current blueprint targets `veritas-core-college-demo` with `plan: free`, no disk, synchronous queues and external private storage. See the current procedure linked above.

The application now honors `TRUSTED_PROXIES`; the blueprint trusts the Render edge so HTTPS URLs and cookies work behind its proxy. Do not use `*` when directly exposing this image on an untrusted network. Worker timeout is 60 seconds, below the database queue retry interval of 90 seconds. `/up` is a process health endpoint, not proof that database access, attachments or SMTP work.

## Remaining preflight and approval boundary

1. Complete Docker engine setup, build the image and test HTTP login, each role's access, private downloads, PDF generation, upload persistence across restart, worker and scheduler locally. Existing passwords are never printed or reset; owner-assisted login can verify them after infrastructure tests.
2. Confirm the intended Clever Cloud add-on, server version, capacity, endpoint, TLS/CA requirements and grants. Map its MySQL host/port/database/user/password to Render's `DB_*` secrets. `MYSQL_ATTR_SSL_CA` is supported by the app when a verified CA file is supplied; never silently disable certificate verification. The local Oracle MySQL result is not a claim that the managed Percona instance has been tested. See [Clever Cloud MySQL](https://www.clever.cloud/developers/doc/deploy/databases/mysql/).
3. Before proposing an import, inspect the remote schema read-only and take a restorable backup. Confirm trigger privileges and shared-host restrictions, including dump DEFINER clauses; prepare a target-specific reviewed import and rollback procedure. Do not import over existing tables or disable accounting guards to bypass a hosting limitation. The raw local dump is not yet a reviewed Clever Cloud import.
4. Fill Render secrets: existing `APP_KEY`, final HTTPS `APP_URL`, database values, verified SMTP settings and sender. Keep the existing Free service and no disk; resolve the storage approval boundary described in the current procedure. No credentials are committed here. Do not generate a replacement application key.
5. Present the exact service, cost choice, destination database, reviewed artifact checksums, import commands and rollback plan for approval **before** remote writes or deployment.
6. After approval: freeze local writes, repeat snapshot/conversion, back up any target, load the approved database and private/public storage, verify all rows/hashes/triggers on the destination, and perform role-based acceptance tests before opening access. Preserve the four password hashes. Explicitly decide whether to invalidate copied sessions and runtime cache for cutover; this rehearsal preserves all rows.
7. Retain encrypted off-host database and attachment backups plus the application key and test a restore. Disk snapshots alone are not a database-and-attachments backup. Rollback must stop production writes, restore a consistent approved database/files pair and retain any post-cutover records for reconciliation.

No Render or Clever Cloud API, database, deployment, remote import or remote migration was invoked during this preparation.
