# Veritas Core V2 — Phase 2 implementation

Date: 2026-10-12 (Asia/Manila). Branch: `feature/veritas-v2`.

## Scope

Four-role authorization, employee invitations, self-service password security, session revocation and technical security logs are implemented. Existing Owner accounts retain their IDs, roles, password hashes, authorship and client assignments. No live database migration, reset, deployment, push or change to `main` was performed. The previously staged Phase 1 work is preserved.

Accounting services, posting prerequisites, evidence digests, financial migrations, Financial Audit Trail storage/UI and client isolation rules are unchanged. The sidebar received only the links/visibility changes required by technical role separation. No expanded demo dataset was added.

## Permission matrix

The explicit action lists are in `config/rbac.php`; database permissions and existing record policies still gate each action. Technical routes additionally require the Super Admin role. Shared search uses `Access` queries; notifications recheck record policies. The technical dashboard does not query or render financial summaries.

| Capability | Super Admin | Owner | Office Manager | Bookkeeper |
| --- | --- | --- | --- | --- |
| Employee invitation/details/role/status/reset-link/session administration | Yes | No | No | No |
| Workspace configuration | Yes | No | No | No |
| Technical security events | Yes | No | No | No |
| Financial Audit Trail | No | Existing access | No | No |
| Client records | No | All, existing management actions | All, onboarding/assignment/activation | Assigned clients; existing create/update rights |
| Documents | No | Existing management actions | Validation/approval/rejection and operations | Assigned-client upload/update/download, existing lifecycle restrictions |
| Requirements/compliance | No | Existing management actions | Management, filing and monitoring | Assigned-client preparation/follow-up, no filing/assignment override |
| Journal preparation/vouchers | No | Existing preparation rights | View and independent review; no original-entry creation/edit | Assigned-client preparation, supporting documents, submission |
| Journal review/approval/posting | No | Independent, authorized entries only | Independent, authorized entries only | No |
| Chart/templates | No | Existing chart/initialization/template grants | Existing chart maintenance grants | Assigned-client read |
| Billing/collections | No | Existing full business grants | Existing billing oversight/operations | Existing assigned-client billing operations retained |
| Reports/exports/print | No | All authorized business records | All authorized business records | Assigned-client reports only |
| Knowledge Base | No | Manage/publish/archive | Manage/publish/archive | Published articles and own drafts |
| Notices | No | Existing management grants | Existing management grants | No |
| Own profile/password | Yes | Yes | Yes | Yes |

Business grants were deliberately retained from Phase 1, including Bookkeeper's assigned-client billing/report permissions. This phase does not silently narrow working accounting workflows. Posting remains subject to independent preparation/review, open periods, evidence, balancing, and existing explicit production grants.

Super Admin cannot change its own role, convert any technical identity into a business identity, or promote an existing business identity into a technical identity. A separate invitation is required. Admins cannot edit existing employee email addresses: otherwise changing an email and sending a reset link would bypass the password boundary. Employees can change their own email with current-password confirmation; this revokes other sessions and invalidates reset tokens. Both the last active Owner and the last active Super Admin are protected; role-row locks serialize administrative changes. There is no account deletion route.

## Invitation workflow

1. Super Admin enters employee name, email and one of the four roles. Admin password fields are absent, and even empty crafted credential fields on account save are rejected.
2. A new account is stored as **Pending Invitation** with an unrecoverable random password placeholder. No shared/default password is delivered. Normalized duplicate email checks prevent overwriting existing accounts.
3. A cryptographically random 256-bit token is generated. Only its SHA-256 digest is stored, alongside normalized email, intended role, inviter, expiry and acceptance/revocation timestamps.
4. Mail is sent after the transaction commits, using environment-configured Laravel mail. `INVITATION_EXPIRY_HOURS` defaults to 24. Logging transports are rejected, including logging members of failover transports; array delivery is allowed only in testing.
5. The employee opens the link, confirms the invited email, and chooses/confirms a password of at least 12 characters. A 72-byte upper bound prevents bcrypt truncation.
6. Acceptance locks the account and invitation, rechecks status/email/role/token/expiry and activates the account once. The used token cannot be replayed. No automatic login occurs.
7. Resending revokes all previous unaccepted invitations. Changing a pending employee's role issues a new bound invitation. Deactivation cancels outstanding invitations. A cancelled pending employee must be reinvited, not directly activated.

Delivery failure preserves the pending account, records a fixed security event, and shows a safe retry message. No mail exception payload is logged by these services. Invitations are delivered synchronously after commit; there are no token-bearing queue jobs. A process failure between commit and delivery is recoverable by resend.

## Password and session workflow

- **Profile → Security → Change Password:** current-password check, 12-character minimum, confirmation, reuse rejection, secure Laravel hashing, five attempts/minute, session-ID regeneration, remember-token rotation and revocation of other sessions. Outstanding reset tokens are invalidated.
- Profile detail editing remains separately confirmed by current password and cannot set a password or change a role/status.
- **Forgot Password:** neutral response for unknown/ineligible emails and delivery failures; Laravel reset notification/broker; 60-minute token expiry, 60-second broker throttle and five HTTP attempts/minute. Pending/unaccepted invitations cannot bypass acceptance through reset.
- Reset verifies and consumes its token while holding the account lock, invalidates sessions and remember tokens, and preserves the account status. Resetting an inactive employee does not reactivate them.
- Super Admin can only **Send Password Reset Link**, never choose the employee's password.
- `session_version` is checked on every authenticated request, so revocation works for file/cache/cookie sessions as well as the default database sessions. Database session rows are also removed. Previously existing sessions at version zero remain compatible until explicitly revoked. Remember-me authentication still requires the current rotated token.
- Acceptance/reset pages send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`; validation does not flash tokens/passwords to old input.

## Migrations and rollout

New forward migrations:

- `2026_10_12_000001_add_employee_security.php`: user session version and invitation acceptance timestamp; `employee_invitations`; separate `security_events`.
- `2026_10_12_000002_separate_technical_roles.php`: explicit idempotent four-role creation and technical grant separation. Existing user `role_id` values and business grants are preserved. Owner is never converted to Super Admin. Permission seeding now uses explicit grants instead of giving Owner every configured permission.

Both migrations refuse automatic rollback because dropping security evidence or restoring Owner technical privileges is unsafe. Recovery requires a reviewed forward migration or tested restore, not a casual rollback.

Before an authorized deployment, inspect the target role inventory, normalized email collisions, active Owner count, current business/posting grants and existing backup/restore readiness. This development run does not certify live data. Apply normal forward migrations only; do not run development seeders or `migrate:fresh` on an existing installation.

Provision the first technical account from a trusted server console after migrations and real mail configuration:

```console
php artisan veritas:invite-super-admin technical-admin@example.com --name="Technical Administrator"
```

The command emails an expiring invitation and prints no token/password. It refuses existing business accounts and stops provisioning once an active Super Admin exists. To retry a pending bootstrap invitation, use the same address/name with `--resend`. Subsequent accounts use authenticated User Management. Use a distinct technical identity; never convert the Owner. There is no permanent bootstrap secret or production password.

## Verification

Final results are recorded below after the sequential verification runs. Do not run the SQLite and MySQL suites concurrently: existing accounting tests share Laravel's fake attachment directory.

## Remaining operational limits and Phase 3 handoff

- Verify actual SMTP/provider delivery, HTTPS `APP_URL`, trusted proxy settings, secure session cookies and persistent rate-limiter storage on the deployment target. Synthetic mail tests cannot certify inbox delivery.
- Configure web server/proxy/APM log redaction for invitation/reset URLs and form secrets. Application security events contain fixed action names, actor/subject IDs, IP and time only. Infrastructure URL logging is outside application code.
- The new security-event table is read-only through the web UI, but is not claimed to be tamper-proof against database/host administrators. Financial audit hardening remains Phase 4.
- A technical administrator remains trusted to provision business identities for authorized recipients. Separate identity enforcement prevents an in-place self-role escalation; it cannot establish who controls a newly supplied external mailbox. Organizational approval of privileged recipients remains necessary.
- Recovery/backup tools remain server-console responsibilities; no new browser backup/export access is granted to Super Admin. Host/database administrators remain outside application RBAC boundaries.
- Existing Phase 1 production NO-GO items remain open. For Phase 3, retain the accounting and isolation regression suite, repair documented compliance pagination/effective-value validation issues, and preserve this role/invitation contract. Defer Financial Audit Trail changes, major sidebar redesign and expanded demos to their assigned phases.

## Files changed for Phase 2

Application/configuration:

- `.env.example`, `bootstrap/app.php`, `config/rbac.php`, `config/security.php`, `routes/web.php`
- `app/Console/Commands/InviteSuperAdmin.php`
- `app/Http/Controllers/AuthController.php`, `DashboardController.php`, `UserController.php`, `InvitationController.php`, `SecurityLogController.php`, `SettingController.php`
- `app/Http/Middleware/ActiveAccount.php`, `app/Policies/UserPolicy.php`
- `app/Models/User.php`, `app/Models/EmployeeInvitation.php`, `app/Mail/EmployeeInvitationMail.php`
- `app/Services/AccountSecurity.php`, `EmployeeInvitations.php`, `SecurityMail.php`, `app/Support/PasswordPolicy.php`
- `database/seeders/PermissionSeeder.php` and the two forward migrations listed above

Views:

- `resources/views/admin/users/form.blade.php`, `index.blade.php`
- `resources/views/admin/overview.blade.php`, `security.blade.php`
- `resources/views/auth/profile.blade.php`, `reset.blade.php`, `invitation.blade.php`
- `resources/views/emails/employee-invitation.blade.php`, `resources/views/partials/sidebar.blade.php`

Verification/documentation:

- `tests/Feature/EmployeeSecurityTest.php`, `InvitationConcurrencyTest.php`, `AuditSecurityTest.php`, `RbacTest.php`, `WorkspaceTest.php`, `UsabilityTest.php`
- `tests/Support/SuperAdminFixtures.php`, `invitation-worker.php`
- `tests/Browser/employee-security.mjs`, `prepare.php`, `server.php`, `tests/verify-accounting.ps1`
- `docs/PHASE-2-IMPLEMENTATION.md`

The staged Phase 1 README, seeder separation, preparation/audit documents and backup-test isolation changes predate this implementation and are not represented as new Phase 2 work.
