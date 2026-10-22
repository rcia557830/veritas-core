# V2 unified workspaces

Implemented on `feature/veritas-v2`, 22 October 2026 (Asia/Manila), following the Phase 1 audit and the user's explicit preservation requirements.

## Employee-facing changes

- Documents, Accounting and Compliance now open directly from the sidebar. Their internal views appear within each workspace rather than repeating all operations in sidebar submenus.
- Documents offers All Documents, Needs Validation (authorized reviewers), Missing Requirements and Follow-Ups. Switching views retains the selected client. The document table displays linked requirements, receipt date, validation status and a next action. Upload/register, client checklists, onboarding and requirement templates remain available. Document titles, receipt dates and statuses remain sortable.
- Missing-document monitoring now filters by client. Its secondary filters are expandable. Requirement links open the specific requirement in the client's checklist. Both document and compliance follow-ups support updating their existing status, assignee, date and remarks through the existing authorized endpoints.
- Accounting has six primary views: General Journal, Accounting Vouchers, General Ledger, Financial Reports, Chart of Accounts and Accounting Settings. Billing, operational exports and financial audit remain in a secondary section. Journal queues remain local filters.
- The journal form displays Total Debit, Total Credit and absolute Difference using integer cents in JavaScript. It distinguishes valid balanced totals, unbalanced drafts, invalid amounts, zero-only lines and lines with both debit and credit. Notes expand when present or invalid. Save Draft is explicit. Voucher-specific messages retain the stricter requirement to balance before saving.
- Compliance uses the same list for `/compliance` and the retained `/compliance/monitoring` route. All Requirements, Upcoming, Overdue and Completed views share the existing deadline predicates. Client, agency, status and date filters remain available. Follow-ups are directly accessible on each compliance record.
- Compliance rows retain the existing workflow/warning colors. Agency and reporting period sit beneath the requirement; separate columns show client submission and official filing deadlines. A next action remains visible on desktop, with horizontal table scrolling on narrow screens.

## Preserved behavior

No schema migration, role grant, posting service, document-protection rule, deadline formula, notification calculation or production data was changed. Existing routes remain available. Uploading and validating remain separate operations; requirement templates, completeness, clarification/incomplete states, follow-up history, onboarding and retained accounting evidence remain intact.

General Journal drafts may be unbalanced but must still satisfy the existing backend line/account validation. Voucher drafts remain subject to their stricter balance/period rules. Neither form creates counterpart lines or chooses classifications automatically. Independent review, correction, posting authorization, immutable posted records, private evidence and financial reports retain their existing services.

Upcoming means either deadline is approaching, with no currently overdue action. Overdue means either existing submission-overdue or filing-overdue predicate matches. Completed includes Filed and Completed, the existing terminal statuses. Legacy `status=Overdue` continues to mean filing overdue. These are presentation filters; they do not change saved workflow status or deadline calculations. Filtering happens before paginator totals are computed.

The manuscript-versus-RBAC and metadata discrepancies identified in `V2-SIMPLIFICATION-PHASE-1.md` are not silently resolved by this UI change.

## Verification

- Full isolated SQLite regression: **396 passed, 17 skipped, 5,973 assertions**. The skipped tests require guarded disposable MySQL execution; a complete MySQL PHPUnit run was not performed for this presentation change.
- Added `UnifiedWorkspaceTest` coverage for deadline-view boundaries, pagination, legacy monitoring compatibility, date filters, client isolation, contextual document links and direct follow-up updates. Updated navigation coverage checks both sidebar and internal destinations for all roles.
- PHP Pint passed for changed PHP files; `node --check public/app.js` passed.
- Existing guarded disposable MySQL/Chrome workflow checks exercise client registration, document upload/link/approval, onboarding, period creation, compliance targets/follow-ups, journal balancing and correction, independent review/posting, all four voucher types, ledger/report retrieval, billing and knowledge. Desktop and 390px mobile checks include screenshots and JavaScript exception detection. Additional browser assertions cover exact difference totals, invalid two-sided lines, direct compliance follow-up updates and client-preserving document views.

No deployment was performed. Browser/test databases and files are synthetic and isolated from the application's normal database and uploads.
