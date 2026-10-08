# Three-role RBAC implementation

Project: `C:\laragon\www\veritas-core`.

## Roles and operational choices

| Capability | Owner | Bookkeeper | Office Manager |
|---|---|---|---|
| Client visibility | All | Assigned only | All |
| Create/edit client profiles | Yes | Assigned; new clients assigned to self | Yes |
| Assign/archive/restore clients | Yes | No | Yes |
| View/upload documents | Yes | Assigned clients | Yes |
| Final document validation/approval/rejection | Yes | No | Yes |
| Create/edit bookkeeping lines | Draft/correction states | Own draft/correction entries for assigned clients | No |
| Submit bookkeeping | Yes | Own entries | No |
| Final bookkeeping review/return | Yes, different creator | No | Yes, different creator |
| Compliance create/assign/file | Yes | No | Yes |
| Compliance preparation status/notes | Yes | Assigned, unfiled records only | Yes |
| Create/edit/publish/archive notices | Yes | No | Yes |
| Create/issue invoices and record payments | Yes | Assigned clients | Yes |
| Cancel unpaid invoices | Yes | No | No |
| Knowledge articles | Full | Read Published; create/edit own Draft | Full |
| Operational reports/export/print | All authorized data | Assigned/authorized data | All authorized data |
| Users, roles, settings and full audit log | Owner only | No | No |

Ambiguous “if allowed” options in the request use these defaults. Exact grants are in `config/rbac.php` and persisted to `permissions` / `permission_role`. No external permission package is required. Each user has one role via the existing `users.role_id` foreign-key mapping; no redundant many-role pivot was added. `roles.slug` is stable (`owner`, `bookkeeper`, `office-manager`).

Owner permissions do not bypass accounting invariants: issued invoices remain immutable; payments cannot exceed the balance; reviewed ledger entries cannot be freely rewritten; no one approves their own bookkeeping; client archiving replaces permanent deletion. Marking an invoice paid requires recording its outstanding payment rather than changing a status string.

Notices are posted inside the workspace for Owners and Office Managers. Publishing does not send an email/SMS or contact any client. Editing a posted notice returns it to Draft for explicit republication.

## Database upgrade and existing accounts

The migration `2026_10_02_000001_add_three_role_permissions_and_notices.php` renames Administrator to Owner and Staff to Bookkeeper while preserving role IDs and user associations, then adds permission tables and notices. Existing account IDs, emails, password hashes, client IDs and assignments were compared before/after the local upgrade and preserved. A private pre-upgrade SQL backup is under `storage/app/private/backups`; backups and business data are excluded from the source ZIP.

The local upgrade and seed have already been run. On another existing installation:

```powershell
cd C:\laragon\www\veritas-core
php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan optimize:clear
```

`PermissionSeeder` calls `RoleSeeder`, creates permissions and syncs the exact grants from `config/rbac.php`. Rerunning it replaces any hand-edited role grants with that configuration. These two seeders do not create default passwords. To add development accounts/sample data in a local/testing environment:

```powershell
php artisan db:seed
```

Do not use `migrate:fresh` against existing business records.

## Development accounts

| Role | Email | Initial password |
|---|---|---|
| Owner | owner@veritascore.local | password123 |
| Bookkeeper | bookkeeper@veritascore.local | password123 |
| Office Manager | manager@veritascore.local | password123 |

Development only: change passwords before deployment. Existing `admin@veritascore.local` and `staff@veritascore.local` accounts were preserved with their existing passwords and mapped to Owner/Bookkeeper respectively. Existing clients remain assigned to their previous employee, so a newly added Bookkeeper may have no clients until an Owner/Office Manager assigns them. Seeding does not silently transfer existing work or reset passwords.

## Enforcement layers

1. `bootstrap/app.php` registers active-account, role and permission middleware. All protected routes require an active authenticated user with one of the three known roles. Unknown/inactive roles do not gain dashboard access.
2. `User::hasRole`, `hasAnyRole`, `hasPermission` and `hasAnyPermission` use the role relation and database grants. Permission gates are registered in `AppServiceProvider`; no unconditional Owner bypass is used.
3. Per-module policies check the requested action, client visibility, ownership and workflow state. `UserPolicy` restricts all account administration to Owners. Sensitive controller methods authorize again, including after row locks on workflow/payment writes.
4. `RecordInput` checks privileged fields on ordinary CRUD requests. Forged document approval, client assignment/archive, compliance filing/deadline/assignment changes and article publication cannot bypass the dedicated workflows.
5. `Access` scopes list/search/report queries. Client profile relations and recent-activity queries respect module permissions too, so restricted notice titles do not leak through activity cards. Notifications reauthorize referenced records before displaying them.
6. Blade `@can` checks hide unavailable buttons and navigation; restricted form fields/options are removed. These visibility rules supplement backend checks rather than replacing them.
7. Owner account changes lock the Owner role row and user row, protect the current/last active Owner and invalidate sessions after role/status/password changes. Password-reset links use the existing mail configuration.
8. Audit events record actor, action, module, record, client where relevant, description, IP and timestamps. Events include account creation/activation/deactivation, role changes, document validation, bookkeeping reviews, compliance filing, payments and notice publication.

## Tests and source code

```powershell
php artisan test
php vendor/bin/phpunit --configuration phpunit.mysql.xml
```

`tests/Feature/RbacTest.php` covers Owner-only URLs, forged role and approval changes, independent ledger review, compliance restrictions, draft article ownership, notice posting/privacy, module permission revocation and role dashboards. Existing `WorkspaceTest` continues to cover authentication, uploads, CRUD, payment calculations and reports. Tests use isolated SQLite/MySQL databases.

`docs/RBAC-CODE.md` contains the complete final code grouped in implementation order, with exact file locations. `docs/ERD.md` includes the new tables and role mapping.
