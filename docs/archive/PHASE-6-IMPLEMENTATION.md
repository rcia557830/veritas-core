# Phase 6 — demo data and final verification

Date: 2026-10-13 (Asia/Manila). Branch: `feature/veritas-v2-phase6`, created from verified Phase 5 `ffd5b43`. Initial working tree was clean. Local and recorded remote `feature/veritas-v2` point to Phase 4 `91bb82c`; Phase 5 was not integrated there. Phase 1–5 history is retained. No branches were merged, no `main` changes were made, and nothing was pushed or deployed.

## Implementation

- Dedicated `DemoDataSeeder`, reusing the existing permission seeder, models, readiness/deadline services, journal/voucher/review/posting/evidence services and payment controller. `DatabaseSeeder` routes local demo use to this dataset; legacy test fixtures remain stable for existing regression/browser journeys. Legacy staff seeding no longer gives local users a shared hard-coded password, and local legacy seed entry points require the demo database guard.
- Local/testing plus explicit `VERITAS_DEMO_DATA` opt-in; uncached configuration; only the designated local `database/veritas_demo.sqlite`, SQLite in-memory tests, or independently identity/token-verified disposable MySQL tests. Other databases, including `veritas_core_db`, are refused. The launcher checks for an empty file or demo marker before migrations. No new migrations were added.
- Atomic database population with a completion marker written last. A completed dataset is a no-op on repeat runs, preserving passwords and demonstration edits. An existing unmarked dataset is refused. Generated fictional PDF files are deterministic by client/type, private, valid PDFs and retained with SHA-256 evidence; a failed seed may leave files for diagnosis but rolls back business rows.
- Four private initial accounts. Hidden password prompts enforce 12 characters/minimum and 72 bytes/maximum. An optional Windows launcher provisions random secrets and stores them with DPAPI encryption in ignored private storage; secrets are removed from the environment before serving. Employee invitations, self-service changes/reset, role grants, independent accounting review and client isolation retain their existing implementation.
- Thirty Philippine-themed fictional businesses across five supported business types, reserved `example.test` addresses and unmistakably synthetic contact/registration values (phone `+63 900 000 NNNN`, TIN `000-000-NNN`, both satisfying the existing client-update validation). Twenty-five clients belong to Bookkeeper, five to Office Manager; Owner prepares the latter's journals and Office Manager independently reviews/posts them.
- Missing documents remain calculated requirement states. Submitted, Under Review, Needs Clarification and Approved examples drive real readiness and dashboard calculations. Three clients are onboarded, three ready, and the others demonstrate pending/clarification/missing requirements.
- Each client has a classified chart, an open year/period, 12 balanced vouchers/journals and retained evidence. Eight transactions per client are posted only through authorized submission, independent review and posting; the remaining four demonstrate draft, pending review, reviewed and returned work. General Ledger/report totals derive from those journals once, regardless of voucher metadata. Firm billing is explicitly separate from client-entity books.
- Invoices use two real line items and payment-controller validation, including two receipts on fully paid examples. Compliance uses the existing valid statuses and ten-day deadline calculation, with illustrative labels and business-appropriate registry agencies; Professional businesses are filed under the generic PRC (Professional Regulation Commission) rather than a specific professional board. Eight searchable published procedures cover the requested knowledge-management topics.
- The sanitized production template now uses `MAIL_SCHEME`, matching `config/mail.php`, instead of an unused `MAIL_ENCRYPTION` setting. No live SMTP configuration was changed.

## Initial record counts

| Record | Count |
|---|---:|
| Users / business types | 4 / 5 |
| Clients | 30 |
| Document requirements / linked PDF documents | 210 / 204 |
| Document follow-ups | 6 |
| Accounts / accounting years / periods | 270 / 30 / 30 |
| Journals / vouchers / lines / evidence links | 360 / 360 / 720 / 360 |
| Posted / Draft / For Review / Reviewed / Needs Correction | 240 / 30 / 30 / 30 / 30 |
| Invoices / invoice items / payments | 30 / 60 / 22 |
| Compliance records / follow-ups | 120 / 24 |
| Published knowledge articles | 8 |

Every client initially has PHP 148,000 Trial Balance debits and credits and PHP 15,000 current earnings, with a zero Balance Sheet difference. Total firm billing outstanding is PHP 133,500: eight Open invoices, eight Partially Paid, seven Paid and seven Overdue. The amounts change normally when the presenter performs workflows.

## Verification

The new integration test verifies counts, all journal balances, same-client account/document links, evidence hashes, independent posting metadata and audit records, exact report results for all 30 clients, readiness states, PDF signatures, deadline arithmetic, billing totals, client isolation, random credentials, idempotence, environment/flag refusal, business-database rejection and refusal to overwrite an existing installation. Initial focused run: four tests, 2,545 assertions passed, including a check that every demo client's phone and TIN satisfy the existing client-update validation.

The local `database/veritas_demo.sqlite` was actually prepared using the guarded launcher and repeated successfully. All 19 existing migrations report Ran. Independent PDO inspection confirmed every documented table count, zero foreign-key violations and `PRAGMA integrity_check = ok`. All four DPAPI-decrypted initial credentials were privately verified against the stored hashes. Initial credentials are encrypted at `storage/app/private/phase6-demo-credentials.xml`; neither credentials nor generated data/PDFs are tracked by Git.

- Full SQLite: **388 tests, 5,407 assertions, 17 expected MySQL-only skips**, no failures/errors. Evidence: `storage/app/private/phase6-sqlite.xml`.
- Disposable MySQL voucher preflight: **25 tests, 264 assertions**, passed. Full MySQL: **388 tests, 5,529 assertions, one expected SQLite-only skip**, no failures/errors. Evidence: `storage/app/private/increment32-c4c5d65e75534936bfe165a6ae8366e7/{vouchers,regression}.xml`. The full database suites ran sequentially.
- The initial sandboxed Chrome launch returned `spawn EPERM` after both database suites passed. Browser-only reruns use approved elevated execution with fresh guarded disposable databases; no assertions were weakened or removed.
- Existing business Chrome journey: passed, including actual database verification of client creation, upload/hash/link/approval, onboarding, accounting calendar, compliance target/follow-up, billing, all four vouchers, independent review/posting, retained evidence, General Ledger, financial reports and Financial Audit Trail. Evidence: `storage/app/private/increment32-ba1e98e860fe49218fb0c49a08040532/browser/`.
- Security and four-role dashboard Chrome journey: passed. Invitation acceptance, login/logout, self-service password change/reset, replay rejection and administration/business separation were checked. The included role journey independently compares dashboard cards to database counts, follows every visible navigation/task link, checks direct denials and Bookkeeper isolation, and covers desktop and 390px mobile navigation for Super Admin, Owner, Office Manager and Bookkeeper. Evidence: `storage/app/private/increment32-760be23af0b5402bb89b4b531754c8c4/browser/`. A separate identical role run was unnecessary because the security journey includes it.
- Browser harness fix: the existing mobile Escape check timed out when headless Chrome lacked keyboard focus after capture. The journey now brings the page forward, enables focus emulation, explicitly asserts page focus and sends real Escape key-down/key-up events. The original navigation-close and focus-return assertions remain intact; the complete business journey subsequently passed. Application navigation code was unchanged.
- Demo dataset Chrome journey (`tests/Browser/demo-data.mjs` + `tests/Browser/verify-demo.php`): passed. Four role logins (Super Admin, Owner, Office Manager, Bookkeeper), desktop and 390px dashboards, module pages and boundaries, Bookkeeper client isolation, search, PDF download, fictional client editing (phone/TIN change persisted in MySQL), partial then full invoice payment, overpayment rejection, compliance completion, General Ledger/Financial Audit Trail and financial reports — each browser action independently re-checked against the disposable MySQL database. Evidence: `storage/app/private/increment32-190a48c112944709860364f0db40f0bc/browser/`.

## Production readiness and limits

No real database was queried, seeded, reset or migrated. Its pending migration state and host-level production configuration remain unverified. Demo readiness is distinct from production authorization: SMTP delivery, HTTPS/trusted proxies, secure sessions, backup/restore rehearsal, durable private storage, scheduler/worker supervision, logging/alerts and approved accounting grants still require target-environment acceptance. The current sanitized template and existing 07:00 notification schedule were inspected; no deployment was performed.

See [FINAL-DEMO-GUIDE.md](FINAL-DEMO-GUIDE.md) for startup, account retrieval, the 13-minute presentation, expected outcomes, troubleshooting and production checks. No measured processing-time improvement is claimed. Dates are relative to initial seeding and remain fixed on repeat runs; start a new isolated dataset for a future dated presentation. Browser checks use installed Chrome and mobile emulation, not a physical-device/cross-browser certification.
