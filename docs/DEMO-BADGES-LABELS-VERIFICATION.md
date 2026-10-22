# Additional badge and label updates

Only the shared badge presentation and isolated demonstration display labels were updated. Existing unfinished work was retained; no push or deployment was performed.

All shared status/readiness badges are 180 × 32 px with centered 12 px text and existing colors/radius. The additional 2 px of height accommodates the two-line “Submission Deadline Approaching” label without clipping. Text wraps within the fixed dimensions and badges do not shrink in flex layouts.

`DemoLabelCleanupSeeder` is guarded by `DemoDatabase::check()` and the existing workspace marker, and integrated into initial/repeat demo seeding. It cleans only an explicit field allowlist: client names/contacts, user display names, document and requirement titles, article and notice titles, and accounting year/period labels. Synthetic client addresses retain “Training Workspace Street”; workspace identification and record-level warnings remain. The original seeded data now uses clean names and descriptions.

Historical journal descriptions, voucher parties, evidence snapshots, review hashes and audit events are preserved exactly. A demo-only presentation helper removes redundant prefixes where these historical labels are rendered, including journal/voucher views, forms, financial audit current-record summaries, general-ledger views/print, and workspace search. Audit event text and training-document files are not rewritten. This avoids invalidating independent review or bypassing posted-record protections.

## Completed verification

- Focused PHP suite: **5 tests, 2,571 assertions passed**, covering demo seeding, cleanup guards, repeatability, credentials and unrelated field preservation.
- Chrome: **12 module pages at 1440 px and 390 px**, checking every rendered badge is exactly 180 × 32 px, centered, without clipped text or page-level overflow. Seven module detail pages, client/accounting searches and all notice status filters passed. No JavaScript exceptions.
- Screenshots: `storage/app/private/badges-labels-cfdaf31109fb493a93233a261839b5c4`.
- Pre-cleanup backup: `storage/app/private/labels-before-20261015-004447-ce7d6187.sqlite`.
- Cleanup changed display fields in six tables. Snapshots verified all protected fields across **40 tables**; **271 reviewed accounting payloads** remained byte-for-byte equivalent for digest calculation. Repeat cleanup made no changes. SQLite integrity and foreign keys passed.
- Post-browser population verification passed: 360 journals, 360 vouchers, 36 Knowledge Base articles, accounting balances/reports, assignments and evidence hashes remain valid.
- PHP formatting checks passed. Technical codes, emails, credentials, file paths, warnings, audit history, dates, statuses, amounts and relationships were preserved. `veritas_core_db` was not accessed or changed.

Reproduce with `php vendor/phpunit/phpunit/phpunit tests/Feature/DemoDataSeederTest.php tests/Feature/DemoLabelCleanupTest.php` and `powershell -NoProfile -ExecutionPolicy Bypass -File tests/verify-demo-labels.ps1`. The latter backs up and verifies the isolated database before opening the local browser.
