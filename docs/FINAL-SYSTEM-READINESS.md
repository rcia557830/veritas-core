# Increment 8.4 — final system readiness

Date: 2026-10-11. Decision is based on [final regression](FINAL-REGRESSION-REPORT.md), [requirements traceability](FINAL-REQUIREMENTS-TRACEABILITY.md), [independent reconciliation](FINAL-ACCOUNTING-VALIDATION.md), Increments 1–7, and current routes/controllers/models/migrations. The approved Chapters 1–3 manuscript and RBCIA acceptance decisions were not available, so exact academic objective wording remains pending reconciliation.

## Three Statements of the Problem

| Problem stated in Increment 8.1 | Implementation evidence | Remaining acceptance boundary |
| --- | --- | --- |
| Document validation and processing | `DocumentController`, `RequirementController`, `DocumentCompleteness`, private attachment disk, onboarding readiness; document, requirement and onboarding feature suites; browser registration/checklist/monitoring. | RBCIA must approve CBL/COR applicability and document types. Full positive upload → approve → link → activate browser chain is not tested. |
| Manual bookkeeping and financial record keeping | Chart of Accounts, accounting year/period schema, journal review/posting, JV/CV/CR/CD, General Ledger and financial reports; independent 8.2 reconciliation and final SQLite/MySQL/browser passes. | Staff cannot create/close periods in the web app. Opening balance, year-end closing, reversal, bank clearing, voucher numbering and statement conventions require policy decisions. |
| Compliance monitoring and deadline management | `ComplianceController`, `DeadlineCalculator`, `Notify`, scheduled `veritas:notify`, monitoring/checklist and follow-up feature tests; monitoring browser page. | Ten-calendar-day default/holiday handling need RBCIA approval. Production scheduler and notification receipt must be tested on the target host. |

The implemented module inventory also includes billing, knowledge, RBAC, audit and backup/restore commands. The final browser run adds positive knowledge publication/retrieval and billing issue/partial-payment coverage. Production behavior depends on installation-specific configuration and data.

## Accounting-period blocker and minimal proposal

Migrations create `accounting_years` and `accounting_periods` but no calendar rows. Test fixtures create periods directly. `LedgerController` selects existing open periods; `JournalEligibility::validate` rejects submission, review and posting without an open same-client period containing the transaction date. `PeriodValidation` checks overlap, year containment, immutable ownership and referenced-period movement. `AccountingPeriod` has status/closure metadata in the schema, but no staff route/controller/service offers create, close or reopen. For a fresh client, journal/voucher posting is therefore blocked without a privileged manual database action. That is unsuitable as the normal RBCIA workflow.

**Minimal proposed next authorized change, after RBCIA policy approval:** an owner/office-manager permissioned, client-scoped, audited year/period page. Permit explicit year and period date/label entry with `PeriodValidation`, transaction/client locking and overlap/containment checks. Show open/closed state and journal references. Close only with confirmation and actor/time; retain rejection of posting to closed periods and posted-entry immutability. Reopen only if RBCIA explicitly authorizes it, with reason, audit and safeguards. Avoid automatic calendars, opening balances or closing entries until their policies are approved. This is a proposal; no fiscal policy or period-management feature was implemented in 8.4.

## RBCIA decisions and UAT

RBCIA must decide fiscal-year boundaries, period cadence/labels, roles allowed to create/close/reopen, reopen criteria, opening-balance import, closing/reversal treatment and report approval basis. It must approve document CBL/COR requirements and exemptions, compliance lead time and working/holiday calendar, voucher numbering/check handling, retention/RPO/RTO and offsite backup custody. The principal should confirm production posting grants and appoint backup/recovery operators.

UAT should use named staff in each role and representative **non-production** client records. Acceptance must cover a fresh client's complete onboarding and period setup; document upload/approval/link/activation; journal and voucher review/posting with rejected wrong-role, wrong-client and closed-period attempts; report reconciliation against approved source figures; compliance follow-ups and scheduled notifications; invoice issue/partial/full payment; knowledge publication; backup, encrypted offsite copy and timed disposable restore. Record defects, business approvals and signatures. Do not use live client data for an unapproved demonstration.

## Completion and decisions

**Development completion:** Increments 1–7 and 8.1–8.4 have implementation and automated evidence for the listed modules. The period management UI, exact manuscript trace, full positive document browser journey, policy approvals and deployment operations are incomplete. Diagram structure was reviewed but visual rendering was not verified.

**A. Academic demonstration — CONDITIONAL GO.** Use disposable fixtures and explain the period setup precondition, provisional policies and unverified diagram rendering. Show the tested login, onboarding exemption, accounting, vouchers, reports, billing and knowledge journeys. Do not portray seeded periods or synthetic reconciliation as live operational readiness. Before formal capstone acceptance, reconcile the approved Chapters 1–3 wording and render/inspect both diagrams.

**B. Actual RBCIA production deployment — NO-GO.** Fresh-client accounting-period setup is absent; RBCIA policies, UAT, production grants, HTTPS/hosting, scheduler, backup custody and target-host restore evidence are unresolved. A passed regression does not remove these blockers. Reassess against [deployment checklist](FINAL-DEPLOYMENT-CHECKLIST.md) after they are completed.

Increment 8 stops here. Implementing period management, accepting RBCIA policy, deployment, merging or committing requires separate authorized work.
