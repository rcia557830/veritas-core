# Increment 8.1 — end-to-end integration test report

Date: 2026-10-11. Branch: `feature/increment-8`; baseline `ec62db6` includes merged Increment 7. No application code, `.env`, production configuration, real client data, or normal development MySQL database was changed. The pre-existing untracked `package-lock.json` was left alone.

## Isolation and runs

- PHPUnit used `phpunit.xml`, which forces SQLite `:memory:` with foreign keys. `tests/Support/TestDatabaseGuard.php` rejects unguarded database targets before migrations.
- Targeted feature run: **223 passed, 0 failed, 0 skipped; 1,708 assertions**. Command: `php artisan test --compact` with `ClientRegistrationTest`, `OnboardingReadinessTest`, `OnboardingActivationTest`, `DocumentRequirementTest`, `DocumentCompletenessTest`, `DocumentIntegrityTest`, `ChartOfAccountsTest`, `GeneralJournalTest`, `JournalPostingTest`, `GeneralLedgerTest`, `FinancialReportsTest`, `VoucherTest`, `ComplianceDeadlineTest`, `ComplianceMonitoringTest`, `ComplianceFollowUpTest`, `ComplianceNotificationTest`, `WorkspaceTest`, `RbacTest`, `DocumentSecurityTest`, and `AccountingSecurityTest`.
- Deadline unit run: **15 passed, 0 failed, 0 skipped; 28 assertions** (`DeadlineCalculatorTest`). Combined current PHPUnit execution: **238 passed, 0 failed, 0 skipped; 1,736 assertions**.
- Browser run: `tests/verify-accounting.ps1 -BrowserOnly` **passed** against a newly initialized, guarded disposable MySQL instance and synthetic fixtures. The chain covers `chart-of-accounts.mjs`, `general-journal.mjs`, `general-ledger.mjs`, `financial-reports.mjs`, `vouchers.mjs`, and `onboarding.mjs`; desktop and 390px mobile checks reported no JavaScript exceptions. Artifacts: ignored `storage/app/private/increment32-c544635ea4db4c46ae54a9be4b2ecceb/`.
- First browser attempt prepared fixtures but Node could not spawn Chrome in the sandbox (`EPERM`). The approved outside-sandbox rerun passed. This was an execution permission issue, not an application failure. The harness shut down its disposable web/MySQL listeners and removed its temporary PHPUnit config.
- Earlier Increment 7 reports record full SQLite and disposable MySQL regressions of 318 tests each; these are historical evidence, **not** a new full regression run for Increment 8.1.

## Workflow results

| Workflow | Current verification | Result and boundary |
| --- | --- | --- |
| A. Client onboarding | `ClientRegistrationTest`, `OnboardingReadinessTest`, `OnboardingActivationTest`, `DocumentRequirementTest`, `DocumentIntegrityTest`; browser registration/checklist/exemption/dashboard | **Passed** for registration, CBL/COR detection, requirement states, approval-derived readiness, authorized activation and blocked unverified activation. Browser covered exemption completion; the full positive upload → verify → activate sequence is assembled from HTTP tests, not one browser journey. |
| B. Document management | `DocumentRequirementTest`, `DocumentCompletenessTest`, `DocumentIntegrityTest`, `WorkspaceTest`, `DocumentSecurityTest`; browser monitoring | **Passed** for requirement configuration, upload/link, completeness/missing states, follow-up history, approval integrity and private download. |
| C. Accounting | `ChartOfAccountsTest`, `GeneralJournalTest`, `JournalPostingTest`, `GeneralLedgerTest`, `FinancialReportsTest`; accounting browser chain | **Passed** for COA initialization, synthetic period use/validation, draft/submit/independent review/posting, posted-only ledger and financial reports. **Period creation through the application is unverified/unavailable:** no period management route/UI exists; fixtures create periods directly. |
| D. Vouchers | `VoucherTest`; `vouchers.mjs` | **Passed** for JV, CV, CR and CD creation, review, posting, immutable posted records, print views and reconciliation in ledger/reports. |
| E. Compliance | `DeadlineCalculatorTest`, `ComplianceDeadlineTest`, `ComplianceMonitoringTest`, `ComplianceFollowUpTest`, `ComplianceNotificationTest`, `WorkspaceTest`; browser monitoring | **Passed** for submission-date calculation, official filing date, status/urgency, follow-ups, private/idempotent internal notifications. Live production scheduler operation was not tested. |
| F. Billing | `WorkspaceTest::test_invoice_calculation_partial_payments_and_pdf` | **Passed** for invoice issue, partial/final payments, balance and overpayment rejection through HTTP. Browser payment journey not run. |
| G. Knowledge management | `RbacTest::test_bookkeeper_articles_are_own_drafts_and_manager_can_publish`; `WorkspaceTest::test_knowledge_authorization_and_escaped_content` | **Passed** for draft create/update, authorized manager publication, private draft access and escaped content through HTTP. Browser authoring journey not run. |
| H. Security | `RbacTest`, `DocumentSecurityTest`, `AccountingSecurityTest`, `WorkspaceTest`; browser role transitions | **Passed** for role and client boundaries, unauthorized URLs/forms, document download protection, accounting review/posting permissions. Broader Increment 7 auth/audit/backup regression is historical evidence only. |

## Findings and limits

No reproducible application defect was found in the executed checks; **0 bugs fixed**. No additional tests or application code were added: existing suites cover the implemented HTTP/service behavior, while a test cannot exercise the absent period creation route/UI. That workflow gap is recorded rather than treated as a passing UI step.

Exact alignment with Chapter 3 remains pending the latest approved Chapters 1–3 manuscript. RBCIA must confirm CBL/COR applicability, the ten-day calendar/working-day deadline policy, accounting period/fiscal conventions, report acceptance rules and voucher numbering. Notifications are internal only; production scheduler, external delivery, real bank clearing, and certified statutory reporting were outside this isolated run. The browser chain does not currently cover full positive document approval/onboarding, billing, or knowledge authoring; their HTTP tests passed.

Recommended next steps within a later authorized increment: obtain the approved manuscript and resolve objective IDs; decide whether period creation needs an application workflow; add browser journeys for the remaining UI gaps if they are acceptance criteria; confirm business policies with RBCIA. Do not infer accounting validation, ERD approval, or final documentation sign-off from this Increment 8.1 report.
