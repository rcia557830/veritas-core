# Hosted database preflight — 21 October 2026

**Status: blocked before authenticated database inspection. No hosted changes were made.**

## Credential handling

- `.deployment/clevercloud.env` exists, is untracked, and was already excluded by `.git/info/exclude`.
- Added `/.deployment/` to the repository `.gitignore` so exclusion persists across clones. The Docker build allowlist also excludes this directory.
- Credentials were read only by a local script and placed temporarily in an ignored MySQL option file; they were not passed as password command arguments or printed. Temporary option files were removed in `finally`.
- The values later pasted into chat were not repeated, copied into repository files or used to weaken verification. No password or account reset was performed.

## Actual connection findings

The MySQL client was configured with `ssl-mode=VERIFY_IDENTITY` and the existing local CA bundle. It failed with **MySQL error 2026: certificate-chain verification failed**, before executing the read-only preflight queries.

A separate **unauthenticated** OpenSSL handshake inspected the presented certificate structure. It sent no database credentials and performed no SQL:

| Check | Result |
|---|---|
| Certificates presented | Two |
| Leaf certificate | Automatically generated MySQL certificate |
| Issuer/root | Automatically generated, self-issued CA |
| Certificate validity dates | Currently valid |
| Subject alternative name | Absent |
| Leaf identity matches requested database hostname | **No** |
| Local public CA bundle exists | Yes |

This is not merely a missing password or absent CA-bundle file. Supplying a CA alone will not fix the confirmed hostname mismatch. The certificate received from an unauthenticated network handshake is diagnostic evidence, **not an independently trusted identity**. It was not installed as a trusted CA. No retry used `ssl-mode=DISABLED`, `PREFERRED`, `REQUIRED`, or disabled certificate/hostname verification.

## Hosted account comparison

The owner-confirmed SQLite source remains `database/veritas_demo.sqlite`. Its four active account records and role grants were inventoried previously without exposing hashes:

| Role | Source ID | Source grant count | Hosted ID / identity / status / hash comparison |
|---|---:|---:|---|
| Super Admin | 1 | 10 | Not inspected |
| Owner | 2 | 64 | Not inspected |
| Office Manager | 3 | 54 | Not inspected |
| Bookkeeper | 4 | 30 | Not inspected |

No credential correctness, same-person match, ID collision, password equality or intended-current-password login has been established on Clever Cloud. No account mapping or migration is approved by this preflight.

## Backup and database compatibility

**No hosted dump was taken and no hosted-backup restoration was verified.** Existing local SQLite snapshots and MySQL rehearsal dumps are not substitutes for a current Clever Cloud backup.

The hosted server version, actual schema, migration ledger, row counts, grants, triggers, foreign keys, storage engines and routines/events remain uninspected. The certificate's MySQL naming is not proof of a server version or schema.

Separately, [Clever Cloud's documented DEV restrictions](https://www.clever.cloud/developers/doc/deploy/databases/mysql/) still conflict with V2's required trigger creation. The application needs 8 posted-journal guards, 4 voucher guards and 4 append-only audit/security guards. Actual installed definitions cannot be checked until authenticated inspection is possible. No trigger creation probe, migration or mutation was attempted.

## Safe next steps and remaining blockers

1. Obtain a provider-supported verified connection route: a hostname-valid certificate chain and independently trusted CA, or an authenticated tunnel with a reviewed trust configuration. Do not treat certificates captured from this failed connection as trusted merely to proceed.
2. If the provider only supports automatically generated certificates on DEV, obtain its independently authenticated certificate/fingerprint and supported connection instructions. Review a secure verification approach before implementation; do not silently disable validation.
3. Alternatively, a complete provider-authenticated export delivered securely could allow local account/schema comparison and restore testing. Availability and completeness of such an export must be established; DEV is not assumed to provide managed backups.
4. Once secure access is resolved, inspect in a read-only transaction, inventory the existing database, and create a consistent complete local dump. Verify that dump on a separate disposable local server, not on Clever Cloud. Preserve all hosted records.
5. Resolve trigger support, then finalize the ID-safe four-account mapping, permission differences and exact migration allowlist. Preserve existing hosted IDs/relationships; copy approved stored hashes exactly; import no bulk demonstration data.
6. Durable private storage, Docker image validation, intended-password acceptance and a final reviewed release SHA remain release gates described in the [selective deployment review](SELECTIVE-RENDER-DEPLOYMENT-REVIEW.md).

No deployment, push, hosted migration/import/reset, provider configuration change, paid upgrade or billing activation occurred. PHP syntax/style and Git whitespace checks passed for this local preflight preparation; application suites were not rerun for these diagnostic/documentation-only changes.
