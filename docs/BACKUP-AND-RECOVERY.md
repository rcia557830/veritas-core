# Veritas Core — Database Backup & Disaster Recovery Procedures

This document is the operational runbook for RBCIA staff who administer the
Veritas Core application. It covers database backups, private-file (attachment)
backups, restoration, and recovery verification.

> **Important:** A database backup alone is **not** a complete Veritas Core
> backup. Uploaded client documents, retained journal evidence, and other files
> are stored separately on the private disk (`storage/app/private`) and must be
> backed up alongside the database.

---

## 1. What is backed up

| Asset | Location | Backed up by |
| --- | --- | --- |
| MySQL database (clients, accounting, compliance, billing, audit, etc.) | `DB_DATABASE` (default `veritas_core_db`) | `php artisan veritas:backup:database` |
| Uploaded documents & retained journal evidence | `storage/app/private` | `php artisan veritas:backup:files` |
| Firm logo (public disk) | `storage/app/public` | `php artisan veritas:backup:files --include-public` |

Backup artifacts are written to `storage/app/private/backups/`, which is:

- **Outside the web root** (never under `public/`), so it cannot be downloaded
  through a URL.
- **Excluded from Git** (`storage/app/private/.gitignore` contains `*`, and the
  root `.gitignore` excludes `/storage/app/private/backups/`, `*.sql`, `*.zip`).

---

## 2. Recommended backup schedule (provisional — subject to RBCIA approval)

| Frequency | Action | Retention |
| --- | --- | --- |
| Daily (e.g. 01:00 local) | `veritas:backup:database` and `veritas:backup:files` | 14 days on-host |
| Weekly | Copy the latest DB + file backup to a **separate, offsite** location | ≥ 4 weeks offsite |
| Ad-hoc | Before any schema migration, major release, or accounting-period close | Keep indefinitely (manual archive) |

**Provisional daily policy:** one full database dump and one file archive per
day, retained 14 days locally, with a weekly offsite copy. This is a starting
point and must be confirmed against RBCIA's actual RPO/RTO requirements.

**RPO / RTO disclaimer:** No guaranteed Recovery Point Objective or Recovery
Time Objective is asserted here. RPO is bounded by the backup interval (data
created between backups is at risk). RTO must be measured during rehearsals on
the actual target environment (see §8).

---

## 3. Backup security requirements

- **Never place database passwords in scripts, documentation, or command lines.**
  The backup commands read credentials from Laravel's configuration and pass
  them to `mysqldump`/`mysql` through a temporary option file
  (`--defaults-extra-file`) that is created with restricted permissions and
  deleted immediately after use.
- Restrict OS access to `storage/app/private/backups/` to the application and
  backup operators only.
- **Encryption at rest:** MySQL dumps are plaintext SQL and file archives are
  plain ZIP. Encrypt copies before they leave the host (BitLocker/Veracrypt on
  the volume, or `openssl`/7-Zip AES-256 on the artifact). Backups stored
  offsite must always be encrypted.
- **Never** configure automatic upload of confidential backups to a third-party
  cloud service without explicit written approval.
- Treat a local backup as a convenience copy; **only an offsite, encrypted,
  restore-tested copy counts as verified disaster recovery.**

---

## 4. Database backup procedure

```bash
# From the application root, on the application host:
php artisan veritas:backup:database --retention=14
```

What it does:

1. Reads the configured database connection (no hardcoded credentials).
2. Runs `mysqldump` with `--single-transaction --triggers --hex-blob
   --no-tablespaces` for a consistent snapshot without modifying source
   records.
3. Writes a timestamped file `veritas-core-YYYYmmdd-HHMMSS-<db>.sql` plus a
   sidecar `.json` manifest (SHA-256, size, timestamp, status).
4. Verifies the artifact exists and is non-empty.
5. Prunes expired backups per `--retention`.

If `mysqldump` is not on `PATH`, pass its full path:

```bash
php artisan veritas:backup:database --binary="C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe"
```

The command exits non-zero and logs the error on failure (no partial artifact
is left behind).

---

## 5. File (attachment) backup procedure

```bash
php artisan veritas:backup:files --retention=14
# include the firm logo too:
php artisan veritas:backup:files --retention=14 --include-public
```

What it does:

1. Zips the private disk (`storage/app/private`), excluding the `backups/`
   directory and disposable test artifacts (`increment32-*`, `stage*`).
2. Writes `veritas-files-YYYYmmdd-HHMMSS.zip` plus a sidecar manifest.
3. Verifies the archive exists and is non-empty, then prunes expired backups.

---

## 6. Restoration procedure (always into a disposable target)

Restoration is **verification-first** and guarded against overwriting the live
database.

### 6.1 Preflight safeguards (automatic)

`veritas:restore:verify` refuses to run when:

- The target database equals the configured **primary application database**.
- The environment is `production` (unless `--force` is passed).
- The MySQL target name does not use the disposable `veritas_restore_*`
  convention (unless `--force` is passed).

### 6.2 Restore a database backup into a disposable database

```bash
php artisan veritas:restore:verify \
    --file="storage/app/private/backups/database/veritas-core-YYYYmmdd-HHMMSS-veritas_core_db.sql" \
    --target="veritas_restore_rehearsal" \
    --driver=mysql
```

The command:

1. Validates the backup file.
2. Creates the disposable target database (if absent) and imports the dump.
3. Verifies the `migrations` table, foreign-key enforcement, representative row
   counts (`users`, `roles`, `clients`, `settings`, …), and reads a
   representative record (firm name, a client, a user email).
4. Exits non-zero if any integrity check fails.

### 6.3 Restore file (attachment) backup

Extract the ZIP into a **staging directory** and copy only the needed files back
into `storage/app/private`, preserving the original paths (e.g.
`documents/…`). Never extract directly over a live private disk without a
verification pass.

---

## 7. Verification checklist (run at least monthly)

- [ ] `veritas:backup:database` exits 0 and the artifact is non-empty.
- [ ] `veritas:backup:files` exits 0 and the archive is non-empty.
- [ ] Manifest files are present and SHA-256 matches the artifact.
- [ ] A restore into `veritas_restore_*` succeeds and representative records are
      readable.
- [ ] Foreign-key violations are empty and `migrations` is present.
- [ ] An offsite copy was made and can be decrypted/read by an authorised user.
- [ ] Backup artifacts are not reachable through a public URL.

---

## 8. Recovery incident response

1. **Stop writes** to the affected application (maintenance mode or app-level
   freeze) if the live database is suspected corrupt.
2. **Never restore over the live database.** Restore the backup into a fresh
   disposable database first and verify integrity (§6).
3. Identify the newest backup whose verification passes.
4. Recover files from the file backup for the same point in time.
5. Measure the elapsed time from failure to verified restore; record it as the
   observed recovery time for future planning.
6. After any recovery, reconcile any records created between the last good
   backup and the incident (these are the likely data-loss window).

---

## 9. Common backup failures and remedies

| Symptom | Cause | Remedy |
| --- | --- | --- |
| `mysqldump executable was not found` | binary not on `PATH` | pass `--binary` with full path |
| `Access denied` | wrong DB credentials | verify `.env` DB credentials and least-privilege account |
| empty artifact removed | target not writable / disk full | free disk; check `storage/app/private/backups` permissions |
| restore refuses to run | target equals primary / production | use a `veritas_restore_*` target in a non-production env |
| `cannot start a transaction` on SQLite tests | restoring over a live in-memory DB | use a dedicated file target |

---

## 10. Responsible personnel

- **Backup operator** — runs daily backups, monitors the run log, rotates media.
- **Recovery approver** — authorises restores and offsite retrievals.
- **Firm principal (Owner role in-app)** — approves retention policy and any
  cloud/offsite storage change.

These roles are provisional and must be assigned to named RBCIA staff.

