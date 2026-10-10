# Veritas Core â€” final unified entity relationship diagram

**Source:** all 15 files in `database/migrations`, Laravel's configured migration repository, Eloquent models, and application services on `feature/increment-8`. The editable [single Mermaid ERD](diagrams/veritas-core-unified-erd.mmd) is the submission source. It contains **38 tables** (37 application-migration tables plus the framework-created `migrations` table), **55 database-enforced foreign key constraints**, and three clearly dotted logical references. It is one drawing, including the eight infrastructure tables.

## Reading the diagram

- Solid connectors represent actual FK constraints. Dotted connectors are application-level references with no FK. Connector labels identify the child column; a `+` joins columns of one composite FK. `0..1` on the parent side denotes a nullable child reference. `0..many` on the child side means a parent may have no children.
- `PK` and `FK` mark key columns. For `permission_role`, both columns form the composite primary key. The ERD lists all PK and FK columns and selected identifying or status fields; it is a relationship diagram, not a full data dictionary. Nullable FK columns are labelled `nullable`.
- Cardinalities describe what the schema permits, not a requirement that records must actually exist. The unique `vouchers.ledger_entry_id` limits each journal to zero or one voucher. The unique `requirement_documents.document_id` limits each document to zero or one requirement link.
- The composite FKs enforce same-client ownership: `accounting_periods(accounting_year_id, client_id)` â†’ `accounting_years(id, client_id)`; `ledger_entries(accounting_period_id, client_id)` â†’ `accounting_periods(id, client_id)`; `ledger_items(ledger_entry_id, client_id)` â†’ `ledger_entries(id, client_id)` and `(account_id, client_id)` â†’ `accounts(id, client_id)`; `journal_documents(ledger_entry_id, client_id)` â†’ `ledger_entries(id, client_id)` and `(document_id, client_id)` â†’ `documents(id, client_id)`; `vouchers(ledger_entry_id, client_id)` â†’ `ledger_entries(id, client_id)` and `(cash_account_id, client_id)` â†’ `accounts(id, client_id)`. `ledger_items.ledger_entry_id` also has its original standalone FK, so both constraints are drawn. Nullable composite members make those links optional where shown; `ledger_items` additionally has an ownership completeness check.

## Table inventory

| Area | Tables | Count |
| --- | --- | ---: |
| Identity and access | `users`, `roles`, `permissions`, `permission_role` | 4 |
| Clients, onboarding, documents | `clients`, `documents`, `document_requirement_templates`, `document_requirements`, `requirement_documents`, `document_follow_ups` | 6 |
| Accounting and vouchers | `account_templates`, `account_template_items`, `accounts`, `accounting_years`, `accounting_periods`, `ledger_entries`, `ledger_items`, `journal_documents`, `vouchers` | 9 |
| Compliance | `compliance_records`, `compliance_follow_ups` | 2 |
| Billing | `invoices`, `invoice_items`, `payments` | 3 |
| Knowledge and notices | `knowledge_articles`, `notices` | 2 |
| Workspace, notifications, audit | `settings`, `notifications`, `notification_events`, `audit_logs` | 4 |
| Laravel infrastructure | `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | 8 |
| **Total** | | **38** |

The framework's `migrations` repository table is created by Laravel's migrator, not by an application migration. Its `id`, `migration`, and `batch` fields come from `vendor/laravel/framework/src/Illuminate/Database/Migrations/DatabaseMigrationRepository.php`; its configured name is in `config/database.php`. No backup table appears in a migration. Backup artifacts are files under `storage/app/private/backups/` as documented in [BACKUP-AND-RECOVERY.md](BACKUP-AND-RECOVERY.md).

## Relationships requiring care

- `sessions.user_id` has an index but no FK. `password_reset_tokens.email` matches a user email logically but has no FK. `notifications.notifiable_type` and `notifiable_id` are Laravel polymorphic columns; the user notification target is a model convention, not a database FK. These are the three dotted connectors.
- `audit_logs.record_id` and `module` describe an audited record without an FK to a particular module table; no connector is drawn because the target varies. `audit_logs.user_id` and `client_id` **are** nullable FKs.
- `document_requirements.accounting_period_id` is a nullable FK, but its client match is checked by `RequirementController`, not a composite FK. `requirement_documents` likewise has separate FKs to requirement and document; the same-client rule is application validation. `vouchers.client_id` and `journal_documents.client_id` participate in composite FKs rather than separate client FKs.
- `ledger_items.account_id` and `client_id` remain nullable for legacy lines. The application requires active same-client accounts for new eligible journals; that stricter rule is not a universal FK guarantee.
- The General Journal is stored in `ledger_entries` and `ledger_items`. The General Ledger is calculated from posted journal lines, with no separate general-ledger table. Voucher types (`JV`, `CV`, `CR`, `CD`) are rows of `vouchers`, each attached to one journal; there is no separate voucher-transaction table.
- `accounting_years` and `accounting_periods` are schema entities and report/journal inputs. The web application has no route or controller for creating periods.

## Validation and rendering

The migration inventory was compared to every `Schema::create` call and the framework repository definition. Its 55 solid connectors match the 47 `foreignId(...)->constrained(...)` declarations and eight explicit composite `foreign([...])` declarations. PK/FK declarations and nullable markers were checked against the schema definitions. Static checks found 38 entities, 58 connectors, and no unknown connector endpoints. Mermaid ER syntax was checked against the [official syntax reference](https://mermaid.js.org/syntax/entityRelationshipDiagram.html), but no Mermaid renderer is installed locally; parser execution and PNG/SVG/PDF export were unavailable. No development database was queried or modified.


