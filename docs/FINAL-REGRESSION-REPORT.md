# Increment 8.4 — final system regression

Date: 2026-10-11. Branch: `feature/increment-8`; starting commit `df1adc9`. The pre-existing untracked `package-lock.json` was left untouched. This report covers code review and isolated synthetic tests. It does not certify RBCIA's live data, policies, hosting, or disaster recovery.

## Environments and exact results

| Run | Environment | Passed | Failed | Skipped | Assertions |
| --- | --- | ---: | ---: | ---: | ---: |
| Full PHPUnit, final code | PHP 8.4.25, SQLite `:memory:`, `phpunit.xml`, guarded test database | 306 | 0 | 14 | 2,067 |
| Disposable MySQL full regression, final code | PHP 8.4.25, MySQL 8.4.3 on a temporary loopback port/database, `tests/verify-accounting.ps1 -SkipBrowser` | 319 | 0 | 1 | 2,168 |
| Disposable MySQL voucher subset, final code | Same harness, `--filter Voucher` | 22 | 0 | 0 | 235 |

The voucher subset overlaps the full run and is not added to its total. The SQLite skips are MySQL-only concurrency/backup cases; the MySQL skip is `BackupTest::test_database_backup_command_fails_cleanly_for_in_memory_sqlite`. The test guard checks the disposable MySQL database identity before the browser fixture script runs `migrate:fresh`. The normal local database and actual client records were not modified. The final MySQL JUnit result is in the ignored `storage/app/private/increment32-6d74851b01d345fa81d5988d5a22a1af/regression.xml` artifact.

## Browser results

`tests/verify-accounting.ps1 -BrowserOnly` used fresh disposable MySQL fixtures, headless Chrome, desktop 1440px and mobile 390px. Final chain **passed**, including no recorded JavaScript exceptions and no horizontal overflow on checked mobile pages. Screenshots remain in ignored `storage/app/private/increment32-e141f98846da428eabed0e81404659cc/browser/`.

| Journey | Result | Boundary |
| --- | --- | --- |
| Login, dashboard, client registration, onboarding list/checklist | PASS | Positive activation used a documented exemption. |
| Chart of Accounts, template initialization, journal creation, independent review/post, immutable posted view | PASS | Synthetic accounts and dates. |
| JV, CV, CR, CD creation, review/post, print; General Ledger and four financial reports | PASS | Synthetic voucher and accounting fixtures. |
| Requirements and compliance monitoring | PASS for page navigation and mobile display | Creating follow-ups and updating compliance in the browser were **NOT TESTED**. Feature tests cover those writes. |
| Knowledge article publish and search/retrieval | PASS | Owner desktop authoring and retrieval; mobile list rendering. |
| Billing invoice create, issue, partial payment | PASS | Owner desktop transaction; mobile list rendering. |
| Bookkeeper access to `/admin/users` | PASS, denied with HTTP 403 page | Other role/record restrictions were exercised by feature tests, not a complete browser matrix. |
| Document upload → validation → approval → requirement link → activation in one UI journey | **NOT TESTED** | Separate HTTP tests cover each step. |
| Backup/restore through browser | **NOT APPLICABLE** | Operator Artisan commands only. |

The first extended browser attempt failed because its new assertion expected `Payment recorded.` while the controller correctly returns `Payment recorded successfully.`. The assertion was corrected; the final browser chain passed. This was a test expectation error, not an application defect.

## Module and integrity coverage

The full PHPUnit suites include authentication, RBAC, client registration/onboarding, document upload/security/completeness/follow-ups, Chart of Accounts, years/period schema and validation, journals/review/posting, General Ledger, vouchers, Trial Balance, Income Statement, Balance Sheet, compliance deadlines/notifications, billing, knowledge, audit, database/file backup and guarded restore. MySQL runs include concurrency checks skipped on SQLite. `IndependentAccountingReconciliationTest` checks a separately calculated synthetic book and cross-client/date/draft exclusions (see [accounting validation](FINAL-ACCOUNTING-VALIDATION.md)). These tests establish behavior for their fixtures; they are not a substitute for real-data reconciliation or user acceptance.

## Defect corrected

**Medium — client-name/period-label disclosure.** `RequirementController::index` and `MissingDocumentsController::index` scoped requirement rows and client choices but loaded accounting-period filter choices without client scope. A Bookkeeper could see names and period labels for another assigned staff member's client in both rendered select menus. Both period queries now restrict to clients visible through `Access::query(Client::class)`. `DocumentRequirementTest::test_bookkeeper_requirement_filters_hide_other_clients_periods` verifies both pages. The existing requirement suite passed with 16 tests and 89 assertions after correction. No accounting calculation or posting logic changed.

## Security and data integrity review

| Severity | Finding and evidence | Status |
| --- | --- | --- |
| High, operational | Fresh clients cannot get an accounting year/period through web routes; `JournalEligibility` requires an open same-client period, while `routes/web.php` has no year/period management route. | Open production blocker; see readiness report. |
| Medium | Cross-client period metadata in two requirement filter menus, above. | Fixed and regression tested. |
| Medium, operational | Production posting grants are conditional in `PermissionSeeder`/`config/rbac.php`; actual `permission_role` data must be checked. | Open deployment check. |
| Medium, operational | Backup commands and guarded restore have automated tests, but no evidence of an encrypted offsite copy or timed restore on the actual host. | Open deployment check. |
| Medium, policy | Compliance lead-day/working-day rules, CBL/COR applicability, fiscal conventions and statement treatment lack RBCIA approval. | Open acceptance decisions. |
| Informational | `AuthController` regenerates sessions on login, invalidates on logout, throttles login/reset, and resets user sessions; production HTTPS/cookie settings are supplied by `.env.production.example`. | Code control present; live configuration unverified. |
| Informational | Web mutation routes use Laravel CSRF middleware; document inputs use MIME/size limits and private `local` disk with policy-gated download. | Code/test control present; no penetration test performed. |
| Informational | Reviewed posting checks permissions, independent reviewer, open period, balanced lines and review digest under a client transaction; model/database guards protect posted evidence. | Passed feature, MySQL concurrency and browser checks. |
| Informational | Reviewed raw SQL uses static expressions or migration-owned identifiers; user sort fields are allowlisted in `Records::query`. No user-controlled SQL interpolation was found in the reviewed paths. | Targeted code review only. |
| Informational | Audit events record state changes and posting; database audit rows are not cryptographically tamper evident. | Operational limitation. |

## Documentation and acceptance gaps

Increment 8.1's [traceability](FINAL-REQUIREMENTS-TRACEABILITY.md) maps candidate objectives but the approved Chapters 1–3 manuscript was not present; exact wording and acceptance IDs remain unverified. Increment 8.2's [reconciliation](FINAL-ACCOUNTING-VALIDATION.md) supports its synthetic arithmetic findings, not certified financial statements. Increment 8.3's [ERD](FINAL-UNIFIED-ERD.md) and [use cases](FINAL-USE-CASE-DIAGRAM.md) match the reviewed schema/routes at a structural level, but no compatible Mermaid CLI or PlantUML renderer was available locally. **Actual parser execution, SVG/PNG export and visual readability remain NOT VERIFIED.** The former Increment 8.1 browser gaps were partly closed here for billing and knowledge; the full positive document/onboarding chain remains open. Historical Increment 1–7 reports were reviewed as implementation context, not counted as new test executions.
