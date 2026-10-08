# Browser acceptance checklist

The automated feature tests and live HTTP checks are complete. The following visual/interaction checks remain manual because the browser automation helper was unavailable in this environment.

1. Open the local URL, sign in as admin, and verify the navy/gold layout and Manrope font load.
2. Open Add Client in its modal; submit an invalid email and confirm the full form shows errors and preserves values; save a valid client assigned to staff.
3. Open its profile, upload a small PDF, download it, and change document review status.
4. Add/remove ledger lines; verify live totals and the imbalance warning. Save a balanced draft as staff, submit, then review as admin.
5. Create an invoice with two items; check live totals, issue, record a partial payment and download/print its PDF. Attempt an overpayment and inspect the error.
6. Edit compliance to Filed; enter today's filing date and a reference; confirm the overdue label disappears.
7. Open Ctrl+K, enter a client name, navigate results with arrows and Enter, and close with Escape. Open notifications and mark one/all read.
8. Filter a report; export CSV and use Print page. Confirm only authorized records are included.
9. At narrow mobile width, open/close the sidebar and scroll wide tables. Check modal scrolling, keyboard focus and labels.
10. Upload a firm logo in Workspace and confirm it appears in the sidebar. Sign in as staff and verify administrator navigation is absent and direct administrator URLs return 403.

Use temporary test records for these checks. Seeded accounts and business records are examples.


## Three-role and usability checks

- Sign in with each development role from `RBAC.md`; verify Office Manager review actions, Bookkeeper draft-only knowledge and preparation-only compliance fields, and Owner-only user management.
- On lists with more than ten matching records, select 10/25/50 rows, apply search/status/sort and click Next/Previous. Confirm the URL and range retain the filters.
- On Billing note all four totals, search by client/invoice/payment reference, and apply an empty search result. The table changes; totals do not. Sign in as Bookkeeper and verify totals only represent assigned clients.
- Inspect the 15px table/body/sidebar text and muted text contrast at desktop and narrow mobile widths. Ensure pagination wraps without horizontal page overflow; wide tables should scroll within their wrapper.
