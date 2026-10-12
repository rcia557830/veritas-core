# Veritas Core — final UML use case diagram

The editable [PlantUML use case diagram](diagrams/veritas-core-use-case.puml) shows the three implemented web roles: Owner, Office Manager, and Bookkeeper. It was checked against `routes/web.php`, controllers, policies, `config/rbac.php`, and `database/seeders/PermissionSeeder.php`. Associations show role capability subject to authentication, active status, assigned-client scope, record state, and policy checks. A use case association does not grant access to every record.

## Permission matrix

**Legend:** ✓ role can use the feature under the stated policy conditions; — no applicable role grant or route. Owner is configured for the full permission catalog, subject to the production posting rollout exception below. Office Manager and Bookkeeper receive the named grants in `config/rbac.php`.

| Implemented web use case | Owner | Office Manager | Bookkeeper |
| --- | :---: | :---: | :---: |
| Login, logout, password reset, own profile | ✓ | ✓ | ✓ |
| View / create / edit clients | ✓ | ✓ | ✓¹ |
| Assign, activate/onboard, archive, restore clients | ✓ | ✓ | — |
| View / upload / edit / download documents | ✓ | ✓ | ✓¹² |
| Validate, approve, reject documents | ✓ | ✓ | — |
| Manage requirement templates and requirements; link documents | ✓ | ✓ | — |
| View requirements; manage document follow-ups | ✓ | ✓ | ✓¹ |
| View chart of accounts | ✓ | ✓ | ✓¹ |
| Manage client accounts | ✓ | ✓ | — |
| Manage account templates / initialize accounts | ✓ | — | — |
| View years / periods as accounting context | ✓ | ✓ | ✓¹ |
| Create, edit, submit journals | ✓ | — | ✓¹³ |
| Review and approve journals | ✓³ | ✓³ | — |
| Post journals | ✓³⁴ | ✓³⁴ | — |
| Create/edit JV, CV, CR, CD vouchers | ✓ | — | ✓¹³ |
| View / print vouchers | ✓ | ✓ | ✓¹ |
| View / print General Ledger and financial reports | ✓ | ✓ | ✓¹ |
| Monitor / update compliance; follow-ups | ✓ | ✓ | ✓¹² |
| Create, assign, file compliance records | ✓ | ✓ | — |
| Billing: view, create, edit, issue, record payment | ✓ | ✓ | ✓¹ |
| Cancel invoice | ✓ | — | — |
| Knowledge: view, draft, edit | ✓ | ✓ | ✓⁵ |
| Publish knowledge | ✓ | ✓ | — |
| View / mark own notifications read | ✓ | ✓ | ✓ |
| Manage notices | ✓ | ✓ | — |
| Generate / export module reports | ✓ | ✓ | ✓¹ |
| Manage users / roles and workspace settings | ✓ | — | — |
| View audit logs | ✓ | — | — |
| Create accounting years or periods in web UI | — | — | — |
| Run backup / restore in web UI | — | — | — |

¹ Bookkeeper record visibility is generally limited to assigned clients by `RecordPolicy` and `Access`; creation can also require a client selection within that scope. ² Bookkeepers cannot edit filed compliance records or documents outside allowed statuses. ³ Journals have state and ownership restrictions: creator-only edits for bookkeepers, creator/reviewer separation, no editing legacy or already reviewed/posted entries, and eligibility checks before posting. Owner has broader draft edit access but still cannot review or post their own entry. ⁴ `bookkeeping.post` seeding is provisional outside `local`/`testing`: an existing business installation retains the grant only if it already had it, so actual production posting access must be checked against its `permission_role` data. ⁵ Bookkeepers can see published articles and their own drafts and edit only their own drafts.

`JV` = Journal Voucher, `CV` = Check Voucher, `CR` = Cash Receipt, `CD` = Cash Disbursement. All four are implemented by the `vouchers` routes and `VoucherWriter`, each coupled to a journal. The diagram keeps the voucher types as distinct actor use cases. It omits `include` and `extend`: the current workflow does not warrant claiming one named user goal always invokes another as a UML sub-use-case.

## Scope and implementation notes

- Authentication includes guest login and password-reset routes; authenticated users can update their own profile and log out.
- The web UI permits selecting existing accounting periods for journals and reports. No accounting-year or accounting-period creation route exists, consistent with Increment 8.2.
- Backup and recovery are implemented as `veritas:backup:database`, `veritas:backup:files`, and `veritas:restore:verify` **Artisan operator commands**. No web route, permission, or named actor assignment exists, so they are not inside the web application use case boundary. [The recovery runbook](BACKUP-AND-RECOVERY.md) documents operator procedures.
- `notices` are distinct from the current user's database notifications. Office Manager and Owner can manage notices; all three roles can view and mark their own notifications read.
- Some permissions are seed configuration, not immutable role constants. The diagram represents the shipped role grants and policy rules; a live installation with altered `permission_role` rows can differ. This uncertainty is especially material for production journal posting.

## Validation and rendering

The use cases were traced to web routes and their controller/policy checks. `php artisan route:list --json` returned 147 routes, with no accounting-period mutation or backup/restore web route. Static delimiter and alias checks were performed, and syntax was compared with the [official PlantUML use case reference](https://plantuml.com/en/use-case-diagram). No PlantUML renderer is installed locally, so parser execution and PNG/SVG/PDF export were unavailable. No application, schema, development database, or lockfile was changed.
