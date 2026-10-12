# Increment 8.2 — independent accounting validation

Validated 2026-10-11 on `feature/increment-8`. This is an isolated synthetic-book check, not certification of RBCIA's actual accounting policies or records. No normal development or production database was migrated, reset, or changed. Source reviewed: Chart of Accounts, year/period models and validation, General Journal review/posting, voucher writer, General Ledger, and four financial-report types; prior Stage 2.1–2.5 and Increment 3.1–3.2 reports were used for their documented conventions.

## Synthetic transactions and independent calculation

All amounts below are pesos; the test asserts integer cents. One client has Cash (Asset), Receivables (Asset), Payables (Liability), Capital (Equity), Revenue, Expense, and an unused Retained Earnings account. The period under test is 2026-04-01 through 2026-04-30 in a configured fiscal year beginning 2025-07-01. Each current-period voucher is saved, submitted by a Bookkeeper, independently reviewed, and posted by a Manager through the real journal workflow.

| Date | Source | Debit | Credit |
| --- | --- | --- | --- |
| 2025-06-30 | Prior-year journal | Cash 100.00 | Revenue 100.00 |
| 2026-04-01 | Journal Voucher | Cash 1,000.00 | Capital 800.00; Payables 200.00 |
| 2026-04-02 | General Journal | Receivables 300.00 | Revenue 300.00 |
| 2026-04-03 | Cash Receipt | Cash 120.00 | Receivables 120.00 |
| 2026-04-05 | Cash Disbursement | Expense 50.00 | Cash 50.00 |
| 2026-04-06 | General Journal | Expense 20.00 | Payables 20.00 |
| 2026-04-07 | Check Voucher | Payables 40.00 | Cash 40.00 |

The fixture also posts a 2026-05-01 journal and another client's April journal, and saves an unposted April draft. All three are deliberately excluded from the expected April results. The fixture and assertions are in `tests/Feature/IndependentAccountingReconciliationTest.php`; expected amounts were calculated from the table above, not copied from another Veritas report.

## Expected versus actual

| Measure | Independent expectation | Veritas result | Difference |
| --- | ---: | ---: | ---: |
| Trial Balance debit total | 1,380.00 | 1,380.00 | 0.00 |
| Trial Balance credit total | 1,380.00 | 1,380.00 | 0.00 |
| Cash closing debit | 1,130.00 | 1,130.00 | 0.00 |
| Receivables closing debit | 180.00 | 180.00 | 0.00 |
| Payables closing credit | 180.00 | 180.00 | 0.00 |
| Capital closing credit | 800.00 | 800.00 | 0.00 |
| Revenue cumulative credit | 400.00 | 400.00 | 0.00 |
| Expense cumulative debit | 70.00 | 70.00 | 0.00 |
| April Income Statement revenue | 300.00 | 300.00 | 0.00 |
| April Income Statement expense | 70.00 | 70.00 | 0.00 |
| April net income | 230.00 | 230.00 | 0.00 |
| Balance Sheet assets | 1,310.00 | 1,310.00 | 0.00 |
| Balance Sheet liabilities | 180.00 | 180.00 | 0.00 |
| Balance Sheet posted capital | 800.00 | 800.00 | 0.00 |
| Prior unclosed earnings | 100.00 | 100.00 | 0.00 |
| Current fiscal-year earnings | 230.00 | 230.00 | 0.00 |
| Liabilities + capital + prior + current earnings | 1,310.00 | 1,310.00 | 0.00 |

General Ledger and Accounts Ledger independently match Cash opening 100.00, April debits 1,120.00, April credits 90.00, and closing 1,130.00. The four April running balances are 1,100.00, 1,220.00, 1,170.00, and 1,130.00. The Receivables and Payables balances show that the test checks more than cash. The prior-year revenue remains in the Trial Balance's cumulative Revenue balance but is excluded from April Income Statement revenue and is shown as **prior unclosed earnings** on the Balance Sheet. The unposted draft, May journal, and other client's posting do not change April results.

## Defects, fixes and accounting-period management

No calculation defect was reproduced by this dataset or the accounting regression. **No application accounting code was changed.** One focused reconciliation test was added to close the cross-module coverage gap.

The year/period workflow is an **operational blocker for a fresh client**: migrations seed no fiscal calendar, `LedgerController` lists only existing open periods, and `JournalEligibility` requires an open same-client period before submission/review/posting. The models and `PeriodValidation` support safe manual creation through code, but `routes/web.php` has no staff year/period creation or closing endpoints. Periods in existing tests are fixture-created. This is an interface gap, not evidence that posted books are inaccurate.

**Minimal proposed interface, pending RBCIA decisions:** an explicitly permissioned, audited client-scoped page to create a fiscal year with inclusive dates; create named periods inside that year; list status and referenced journals; close a period with confirmation and `closed_by`/`closed_at`. Reuse `AccountingYear`, `AccountingPeriod`, `PeriodValidation`, and client-lock transactions. Keep date overlap/containment checks, prevent moving referenced periods, preserve closed-period posting rejection and posted-entry immutability. Do not generate opening balances, closing journals, or automatic fiscal calendars. Before implementation, RBCIA must approve fiscal-year boundaries, period cadence and labels, who may create/close/reopen, and any reopen policy. No interface was implemented in this increment because those decisions remain unresolved.

## Test execution and remaining risks

- New isolated SQLite reconciliation test: **1 passed, 9 assertions**.
- Focused isolated SQLite accounting suite (new test plus report, ledger, voucher, schema, migration and posting suites): **91 passed, 657 assertions**. Scoped Pint check passed.
- Disposable MySQL via the guarded `tests/verify-accounting.ps1 -SkipBrowser` harness: voucher subset **22 passed, 235 assertions**; full regression **319 tests, 318 passed, 1 expected SQLite-only backup test skipped, 0 failed, 2,160 assertions**. The new reconciliation test passed on MySQL with 9 assertions. JUnit evidence is in ignored `storage/app/private/increment32-d184276fe53340738900fdaddd5d3481/regression.xml`; the harness stopped its server and removed its temporary PHPUnit configuration.

The report conventions remain provisional: no approved opening-balance import, formal closing workflow, statutory statement certification, reversal/adjustment policy, or bank/check clearing is established. The Balance Sheet carries unclosed earnings as a disclosed development convention. This scenario tests exact expected values, boundaries and voucher inclusion; it does not validate historical real records or business-policy correctness. Do not interpret a balanced Trial Balance alone as proof of completeness or correct account classification.
