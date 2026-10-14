# Local demo population — 14 October 2026

The Apache application at `http://127.0.0.1:8080` serves this checkout through the Laragon junction. Its local, ignored `.env` now selects `database/veritas_demo.sqlite` with demo seeding enabled. The original `veritas_core_db` was inspected read-only (6 journals, 0 vouchers, 2 articles); no demo records were inserted there. Work remains on `feature/veritas-v2`.

Before supplementation, the SQLite demo was backed up with `VACUUM INTO`; the backup passed SQLite integrity and record-count checks. The original `.env` is retained privately in `storage/app/private/backups/env-before-demo-population.txt`. The database snapshot is `storage/app/private/backups/demo-before-population-20261014-142927.sqlite`. Neither file belongs in source control. Restoring the original environment configuration selects the business database again; do not seed that database.

## Data

- Reused all 360 journals and their 360 linked vouchers. Existing financial records already exceeded the requested approximate counts, so no extra financial records were generated.
- Demo Bookkeeper has 300 My Journals entries across 25 assigned clients. The remaining five clients are excluded.
- Voucher types: JV 180, CV 30, CR 60, CD 90. JV amounts come from journal lines by design; cash/check amounts are stored and validated against their journal movements.
- Added 28 articles, preserving the original eight: 36 published articles overall, 14 Internal Procedure results, eight template results and eight guideline results. Two requested procedures were reused by title/slug. Existing articles were not renamed, overwritten or republished.
- The existing `DemoDataSeeder` now invokes `DemoKnowledgeSeeder` for both new and already-prepared demos. It skips matching titles/slugs, including archived records, and does not reset credentials or workflow states.

## Verification

The focused SQLite suite passed 71 tests and 3,080 assertions, including seeder repeatability, actual query totals, journal preparation/review/posting, vouchers and isolation. The live demo integrity verifier checks all journals, accounts, periods, evidence hashes, voucher relationships and amounts, independent review/posting, all 30 clients' Trial Balances and Balance Sheets, article contents and duplicate titles.

The browser workflow covers the exact six navigation destinations with genuine demo-account logins, detail links, filters, pagination when present, all voucher types, client isolation and Super Admin denial. It also saves demo voucher/journal #9, submits it, returns it with a correction note, resubmits it, and independently reviews/posts it. This deliberately advances that fictional draft without changing amounts or evidence; other historical financial records are preserved.

All navigation and workflow browser checks passed on port 8080. After verification there are 241 Posted journals, 29 Draft, and 30 each For Review, Reviewed and Needs Correction. Journal #9's PHP 1,000 service adjustment now contributes to client #1's posted reports. All 30 client reports still reconcile. Repeat browser runs preserve that posted journal.

```powershell
php artisan demo:seed --confirm
php tests/Support/verify-demo-population.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-demo-population.ps1
```

The browser runner uses the existing Windows-encrypted demo credential vault and an isolated headless Chrome profile. It does not print passwords. Private screenshots are stored under `storage/app/private/population-browser-*`.

Voucher navigation is `/vouchers`, resolved from Laravel routes. This existing screen supports client, type and status filters, but has no free-text search control. Journal and knowledge search remain unchanged. Templates and guidelines each fit on one page at the current page size; lists with more results expose functioning pagination.

No production deployment, push, schema change, new module, permission relaxation or search-engine modification was performed.
