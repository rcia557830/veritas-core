# VERITAS CORE V2 - Phase 2 handoff

Confirmed by the user on 2026-10-12 (Asia/Manila). This is an implementation contract, not activated permissions. Phase 1 retains all current permissions and existing accounts.

## Exactly four roles

| Role | Primary responsibility | Required boundary |
| --- | --- | --- |
| Super Admin | Technical administration, user management, role assignments, system settings, security logs | Separate account from Owner. No implicit access to confidential accounting records or financial audit detail. Cannot set, view or change employee passwords. |
| Owner | Business oversight, financial reports, financial audit trail, billing, compliance monitoring | Existing Owner accounts remain Owner accounts. Business oversight must not implicitly inherit technical administration. |
| Office Manager | Client onboarding, document validation, compliance management, authorized accounting review/posting | Preserve independent preparation/review/posting restrictions and client authorization. |
| Bookkeeper | Journal entries, vouchers, supporting documents, accounting preparation | Preserve assigned-client isolation and prohibition on self-review/unauthorized posting. |

Super Admin is included in the four, not a fifth role. Role separation must apply server-side to lists, selectors, direct URLs, writes, search, notifications, exports, downloads, print/PDF and audit detail, not merely navigation. A technical administrator's ability to manage roles must not become an indirect self-grant of accounting access; define allowed role changes and protect Super Admin/Owner separation explicitly.

## Authentication and invitations

Current entry points: `AuthController::reset` and `updateProfile`, `UserController::save` and `reset`, `User` hashed cast, `config/auth.php`, `routes/web.php`, auth/admin Blade forms and AuthSecurity/Rbac tests. Current new-password minimum is 10; keep this unchanged in Phase 1. No public registration route exists.

Phase 2 must:

1. Replace administrator-entered passwords with invitation creation. Users choose their own passwords. Reject password fields at technical user-management endpoints and remove them from admin forms, requests and audit payloads.
2. Store only a digest of a cryptographically random invitation token. Bind it to normalized email and intended role; include inviter, expiry, accepted/revoked timestamps and resend invalidation. Do not store or log plaintext passwords or invitation secrets.
3. Make acceptance transactional and single-use under concurrent requests. Reject expired, revoked, reused, wrong-email and tampered invitations, duplicate accounts and unauthorized role changes. Invited accounts must have no authenticated access before acceptance. An invitation must not silently replace an existing Owner or overwrite an existing account.
4. Route all newly created/changed passwords through one server-side policy requiring **at least 12 characters**, including invitations, reset and profile change. Update matching UI text. Preserve existing password hashes and login compatibility during migration; do not bulk-reset accounts or enforce a new minimum during login.
5. Keep password hashing, confirmation, reset token expiry/throttling, generic reset responses, CSRF, login throttling, session regeneration and session/remember-token invalidation. Users change their own passwords with current-password confirmation or a valid reset link. Super Admin can request a reset link, never choose the new password.
6. Deliver invitations/resets through configured mail. Queue only after committed invitation state and support safe resend after delivery failure. Avoid leaking tokens through audit/security logs, analytics, exceptions or public URLs beyond the intended acceptance flow.

## Migration and authorization work

- Add invitation schema only through new forward migrations; inspect existing data and backup/restore readiness first. Preserve all financial migrations, composite ownership keys, triggers, review digests, evidence hashes, money arithmetic, locks and rollback guards.
- Create an explicit idempotent permission rollout. `PermissionSeeder` currently grants Owner every configured permission; do not use that behavior to roll out technical permissions. Development seeders are not a production migration mechanism.
- Review `config/rbac.php`, role middleware in `routes/web.php`, `Access`, every policy, `UserController` last-active-owner locking, `ActiveAccount`, sidebar, search, notifications and audit scoping. New roles should fail closed until grants are explicit.
- Provision the first Super Admin through a controlled invitation/bootstrap procedure without converting an existing Owner or exposing a default/shared password. Protect the last active technical administrator as well as required business ownership.
- Separate security and financial audit permissions and payloads. Technical logs must not embed confidential journal/client/financial details. System settings and backup privileges need review because exports, credentials and recovery tools can expose accounting data indirectly.
- Confirm the action-level grant matrix (including assignment, billing print/export and notice access) against these agreed responsibilities before rollout. Do not infer additional business rights from technical administration.

## Acceptance gates

- Existing Owner IDs, role membership, passwords and financial authorship remain unchanged; rerunning the rollout does not expand privileges or duplicate invitations.
- Positive and negative tests for all four roles, including Super Admin denied accounting/report/download/search/notification/financial-audit access and Owner denied technical administration.
- Invitation expiry/revocation/replay/race/duplicate-email tests; role tampering and self-escalation denied; mail failure and resend covered without exposing secrets.
- Password boundaries: 11 rejected, 12 accepted, longer accepted, confirmation required; cover invitation, reset and profile paths. Administrator-supplied employee passwords rejected.
- Existing independent review, closed-period rejection, posted immutability, voucher identity, evidence retention, client reassignment isolation and reconciliation tests continue to pass.
- SQLite regression, guarded disposable MySQL concurrency checks and role-aware browser journeys, followed by SMTP delivery and recovery verification in an authorized staging environment.

Refer to [Phase 1 audit](PHASE-1-AUDIT.md) and [existing readiness blockers](FINAL-SYSTEM-READINESS.md). Phase 1 does not certify production readiness or authorize deployment.
