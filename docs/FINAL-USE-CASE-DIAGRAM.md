# VERITAS CORE V2 - Essential use cases

[View diagram (PNG)](diagrams/veritas-core-use-case.png) | [Scalable diagram (SVG)](diagrams/veritas-core-use-case.svg) | [PlantUML source](diagrams/veritas-core-use-case.puml)

This simplified diagram describes implemented user goals in the web application, based on routes, policies, and configured permissions. It is not a claim that the current hosted deployment has all migrations or grants installed.

## Actors and essential actions

| Actor | Essential use cases |
| --- | --- |
| Super Admin | Manage employee accounts and role assignments; configure workspace; view system activity and security logs. |
| Owner | Maintain client records; complete onboarding; upload/access documents; manage requirements and validate documents; manage accounts and periods; manage account templates and initialize accounts; prepare/submit journals and vouchers; review/post others' journals; view ledger/financial audit trail and generate reports; monitor compliance/follow-ups; manage billing/payments; read/draft/publish knowledge content and manage notices. |
| Office Manager | Maintain client records and onboarding; upload/access and validate documents; manage requirements, accounts, and periods; review/post others' journals; view ledger/financial audit trail and generate reports; monitor compliance/follow-ups; manage billing/payments; read/draft/publish knowledge content and manage notices. |
| Bookkeeper | Maintain authorized client records; upload/access documents; prepare/submit journals and vouchers; view authorized ledger/financial audit trail and generate reports; monitor compliance/follow-ups; manage permitted billing/payments; read published knowledge content and maintain own drafts. |

## Rules necessary to interpret the diagram

- Users must be logged in and active. Login/logout, password recovery, profile editing, dashboards, search, and notifications are supporting functions, omitted from this overview to keep the main goals visible.
- Associations indicate participation, not unrestricted permission to every operation in a group. Record permissions, assigned-client scope, workflow state, and ownership still apply. For example, Bookkeepers cannot publish knowledge content or review/post journals; invoice cancellation is Owner-only.
- Owner and Office Manager can create accounting years/periods and manage client accounts. Only Owner can manage account templates and initialize accounts from templates.
- A journal creator cannot review or post their own journal. Posting also requires the actual `bookkeeping.post` grant, which is not automatically granted by production seeding.
- Super Admin handles technical administration, not financial records. Business roles access the financial audit trail; system activity/security log pages are Super Admin-only.
- The four voucher types (JV, CV, CR, CD) are grouped under one preparation use case. Articles, procedures, templates, and guidelines are grouped as Knowledge Base content.

## What does not belong in this diagram

- Database tables, columns, JSON, triggers, indexes, migrations, or API endpoints: these are implementation details.
- Render, GitHub, Clever Cloud, phpMyAdmin, deployments, or manual exports: these are hosting/administration tools, not application user goals.
- A Client actor: clients are managed records; the current application has no client self-service portal.
- A separate use case for every button, filter, sort option, or voucher subtype.
- `include`/`extend` arrows added merely to show sequence. This overview uses actor associations only; workflow order belongs in an activity diagram.

## Source and validation

Checked against `routes/web.php`, `config/rbac.php`, `database/seeders/PermissionSeeder.php`, `AccountPolicy`, `BookkeepingPolicy`, and the account-period and audit/security controllers. This replaces the older three-role diagram and its obsolete claim that accounting years/periods cannot be created through the UI.

The diagram follows the supplied reference: a tall rectangular system boundary, a single column of gray oval use cases, section headings, and stick-figure actors on both sides. The SVG and 2400-by-3440 PNG were generated locally from the PlantUML labels and associations with a fixed layout, then visually inspected. PlantUML is the logical source; its automatic layout may differ from the supplied SVG. All 16 use cases, 4 actors, and 34 associations were retained. No application code, permissions, or database records were changed.
