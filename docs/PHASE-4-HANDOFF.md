# Veritas Core V2 - Phase 4 handoff

Date: 2026-10-12 (Asia/Manila). Continue from the verified Phase 3 local commit on `feature/veritas-v2`. Read `PHASE-3-IMPLEMENTATION.md` and the Phase 1/2 reports first.

Phase 4 is Financial Audit Trail hardening and usability, not a new journal, payment, document or authentication architecture. Preserve the four-role boundary: Owner business audit access; Super Admin technical security events only. Keep independent journal review/posting, posted evidence, voucher identities, client isolation, invitations and user-controlled passwords.

Phase 3 adds calendar audit events (`year.created`, `period.created`, `period.closed`) under `accounting-periods`, with the relevant client, actor and record ID. Year and period entities must be distinguished by action if a future audit detail link is added. Existing document validation/reopening/replacement, requirement linking, compliance deadline changes, onboarding, invoice and payment events remain available. Do not reinterpret historical IDs or erase events during redesign.

Plan and verify a forward-only audit evolution: explicit financial versus technical event classes, client/actor/entity correlation, complete before/after evidence appropriate to each action, transactional writes, append-only enforcement and retention/recovery policy. Several older follow-up/template actions still persist their record and audit separately; include their atomicity in that audit scope. Do not claim tamper evidence merely because the web UI is read-only. Host/database administrators remain outside application RBAC.

Preserve payment submission UUIDs, upload hashes, retained original attachment paths, posted journal review digests and existing database triggers. Never place passwords, invitation/reset tokens or private file contents in audit payloads. Calendar data has only the existing Open/Closed states; reopening and correction policies remain undecided and are not implied by this handoff.

Use the isolated test guard, run SQLite then disposable MySQL sequentially, rerun independent accounting reconciliation and both business/security browser workflows, and verify database changes. Do not reset or seed `veritas_core_db`. Apply new schema only by forward migration to an authorized target; no push or deployment is authorized by this handoff. Full sidebar/visual redesign remains Phase 5; expanded fictional data remains Phase 6.
