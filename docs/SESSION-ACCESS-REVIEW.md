# Session and Super Admin access review — 21 October 2026

Branch: `feature/veritas-v2`. Existing Render Free service and Clever Cloud MySQL DEV retained. **Prepared locally only; the hosted incidents are not yet resolved or fully diagnosed. No push, deployment, hosted migration, seeding, account update or permission update was performed.** Existing unrelated untracked files were left untouched.

## Findings and limits

### HTTP 419

- `config/session.php` already defaults to `database`; the existing Render blueprint also specifies database sessions, encryption and Secure cookies. No driver change or session migration is needed in source. The live Render driver is unknown: a blueprint does not prove the service environment matches it.
- The original users migration defines `sessions` with the required columns. **The hosted table has not been verified.** A fresh verified-TLS Clever Cloud preflight failed with MySQL 2026, certificate-chain verification failure, before SQL inspection. Do not infer that migrations are pending or rerun them from this result.
- The public deployed `/login` returned 200 with a CSRF hidden field and an HTTPS form action. Its session cookie was Secure, HttpOnly, host-only and SameSite=Lax, with a 7200-second maximum age. Two consecutive GET requests using returned cookies preserved the CSRF token. No credentials or token values were logged. This verifies immediate anonymous continuity, not authenticated sessions, APP_KEY stability or persistence through sleep/redeploy.
- Authentication uses `AuthController`, not `LoginController`. Successful login regenerates the session ID and stores the account security version; logout invalidates the session and regenerates the CSRF token. CSRF middleware and `@csrf` remain enabled.
- Confirmed source defect: `bootstrap/app.php` read `TRUSTED_PROXIES` directly via `env()`. After `config:cache`, a value supplied only through `.env` is unavailable there. Proxy trust is now stored in `config/trustedproxy.php`, which Laravel's existing TrustProxies middleware reads. The regression test serializes configuration and boots a separate PHP process; without the new configuration it reports an insecure request, with it the request is secure. **This is not established as the hosted incident's cause:** Render process-level environment variables can still be available, and the public form already uses HTTPS.
- The startup script requires an existing APP_KEY and does not generate one. The actual deployed key, its history and effective cached environment cannot be verified through a public HTTP response. Do not regenerate or print it.
- File sessions would be ephemeral on Render Free. Whether this deployment actually uses them remains unknown. Database sessions are appropriate once the hosted table and its connection are verified. See [Render Free storage behavior](https://render.com/docs/free) and [Laravel 12 session drivers](https://laravel.com/docs/12.x/session).

### HTTP 403

- The user identified **Super Admin on `/dashboard`** as the failing case.
- In this branch, `/dashboard` includes all four canonical role slugs. `DashboardController` serves the technical overview to an active `super-admin`; it does not require business grants. A test confirms that even a Super Admin with all grants removed can open the overview while protected modules remain forbidden.
- Therefore missing technical grants alone do not explain a dashboard 403 in this revision. The deployed revision/routes and the authenticated account's `role_id`, joined role slug and status need comparison. A missing/unrecognized role mapping can deny the dashboard. This is a diagnostic candidate, not a confirmed hosted root cause.
- Super Admin technical routes remain protected by both role and permission checks. Business routes continue to reject Super Admin, even if business grants are accidentally present. Owner, Office Manager and Bookkeeper permissions and client scoping are unchanged.
- `PermissionSeeder` uses `sync()` and can remove existing grants; it also deliberately withholds new `bookkeeping.post` grants in production. It was not run against hosted data. Do not use it as a blanket repair.
- The four approved hosted identities and actual grants could not be verified without database access. Tests use isolated synthetic accounts, not the approved accounts or hosted passwords. No user was recreated and no role was assigned by an assumed numeric ID or display name.

## Prepared changes

| File | Change |
|---|---|
| `bootstrap/app.php` | Remove uncached proxy environment lookup |
| `config/trustedproxy.php` | Cacheable proxy configuration using Laravel's existing middleware |
| `render.yaml` | Explicit host-only `SESSION_DOMAIN=null` and `SESSION_SAME_SITE=lax`; review-only |
| `app/Console/Commands/InspectAccess.php` | Read-only runtime/session schema/account role/grant inspection |
| `tests/Feature/DeployedSessionTest.php` | Encrypted database-session login/logout for four roles, real CSRF rejection, session revocation, technical/business access boundaries, proxy cookies |
| `tests/Feature/TrustedProxyCacheTest.php` | Separate-process regression for `.env` proxy trust after configuration caching |
| `tests/Feature/InspectAccessTest.php` | No mutation or secret disclosure; missing session table detection |
| `docs/SESSION-ACCESS-REVIEW.md` | Evidence, limitations and release checks |

The new command is `php artisan veritas:inspect-access`. It reads the executing runtime's effective configuration and configured database; running it locally does **not** inspect Render's environment. It reports APP_KEY presence only, never the key, passwords, emails, session IDs or session payloads. Grant differences are review findings, not instructions to restore every configured permission. It does not migrate, seed or repair data. Do not point it at an unverified connection to work around TLS failure.

## Remaining evidence and deployment gate

Validation completed locally: **71 tests, 886 assertions passed**, covering AuthSecurityTest, RbacTest, RoleDashboardTest, EmployeeSecurityTest, WorkspaceTest, DeployedSessionTest, TrustedProxyCacheTest and InspectAccessTest. Tests use guarded SQLite `:memory:` databases. PHP Pint passed for all changed PHP files, and `git diff --check` passed. These are not hosted MySQL or browser acceptance results.

1. Obtain the current Render deployed SHA and effective, non-secret settings: APP_URL, SESSION_DRIVER, SESSION_DOMAIN, SESSION_SECURE_COOKIE, SESSION_SAME_SITE, SESSION_CONNECTION and TRUSTED_PROXIES. Compare the deployed dashboard route with this branch. Verify APP_KEY continuity privately without exposing its value.
2. Resolve the Clever Cloud certificate verification problem through a provider-authenticated connection route, or obtain a secure provider-authenticated schema/account export. Read the existing sessions schema and the four users' role mappings/grants. Do not disable certificate verification, overwrite records or rerun completed migrations.
3. Review any actual mapping/grant discrepancy before preparing a narrowly scoped correction. Preserve account IDs, passwords, status, custom grants/revocations and business records. Do not alias an unknown role automatically or grant Super Admin business access.
4. Only after verification and explicit deployment approval, apply the reviewed changes to the **existing** service. Expected settings are the verified HTTPS APP_URL, unchanged APP_KEY, SESSION_DRIVER=database, SESSION_DOMAIN=null, SESSION_SECURE_COOKIE=true, SESSION_SAME_SITE=lax and appropriate TRUSTED_PROXIES. The startup script already rebuilds the config cache. No new services or automatic seeding/migrations.
5. With approval, validate each approved account's login, intended dashboard and allowed/denied modules on Render; check logout and revocation with controlled test sessions. Check session survival through a natural sleep/wake and a separately approved restart, within the configured idle lifetime. Revocation intentionally ends sessions; a form whose session has expired must still reject its stale CSRF token.

No request to deploy is being made before these unresolved runtime/database checks are complete.
