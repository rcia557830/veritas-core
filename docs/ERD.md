# VERITAS CORE database relationships

The authoritative column definitions, indexes and delete rules are in `database/migrations/2026_10_01_000001_create_workspace_tables.php` and Laravel's initial system migrations. The diagram below focuses on domain keys and important attributes; timestamps and soft-delete columns are omitted for readability.

```mermaid
erDiagram
    ROLES ||--o{ USERS : classifies
    USERS ||--o{ CLIENTS : creates
    USERS o|--o{ CLIENTS : assigned_to
    CLIENTS ||--o{ DOCUMENTS : owns
    USERS ||--o{ DOCUMENTS : uploads
    CLIENTS ||--o{ LEDGER_ENTRIES : owns
    USERS ||--o{ LEDGER_ENTRIES : creates
    USERS o|--o{ LEDGER_ENTRIES : reviews
    LEDGER_ENTRIES ||--|{ LEDGER_ITEMS : contains
    CLIENTS ||--o{ COMPLIANCE_RECORDS : owns
    USERS ||--o{ COMPLIANCE_RECORDS : creates
    USERS o|--o{ COMPLIANCE_RECORDS : assigned_to
    CLIENTS ||--o{ INVOICES : billed
    USERS ||--o{ INVOICES : creates
    INVOICES ||--|{ INVOICE_ITEMS : contains
    INVOICES ||--o{ PAYMENTS : receives
    USERS ||--o{ PAYMENTS : records
    USERS ||--o{ KNOWLEDGE_ARTICLES : authors
    USERS o|--o{ AUDIT_LOGS : performs
    CLIENTS o|--o{ AUDIT_LOGS : concerns
    USERS ||--o{ NOTIFICATION_EVENTS : deduplicates
    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ SESSIONS : has

    ROLES {
        bigint id PK
        varchar name UK
    }
    USERS {
        bigint id PK
        bigint role_id FK "nullable during provisioning"
        varchar name
        varchar email UK
        varchar password "hashed"
        varchar status
        timestamp last_login_at
    }
    CLIENTS {
        bigint id PK
        varchar client_code UK
        varchar business_name
        varchar business_type
        varchar contact_person
        varchar email
        varchar phone
        varchar tin
        text address
        varchar registration_status
        varchar business_license_status
        varchar status
        text notes
        bigint created_by FK
        bigint assigned_to FK "nullable"
    }
    DOCUMENTS {
        bigint id PK
        bigint client_id FK
        varchar document_number UK
        varchar title
        varchar document_type
        varchar status
        date received_date
        date due_date
        varchar file_path "private storage path"
        varchar original_file_name
        varchar mime_type
        text notes
        bigint uploaded_by FK
    }
    LEDGER_ENTRIES {
        bigint id PK
        bigint client_id FK
        date transaction_date
        varchar reference_number
        varchar description
        varchar status
        text notes
        bigint created_by FK
        bigint reviewed_by FK "nullable"
        timestamp reviewed_at
    }
    LEDGER_ITEMS {
        bigint id PK
        bigint ledger_entry_id FK
        varchar account_name
        decimal debit
        decimal credit
    }
    COMPLIANCE_RECORDS {
        bigint id PK
        bigint client_id FK
        varchar agency
        varchar requirement
        varchar reporting_period
        date due_date
        varchar status
        date filed_date
        varchar reference_number
        text notes
        bigint assigned_to FK "nullable"
        bigint created_by FK
    }
    INVOICES {
        bigint id PK
        varchar invoice_number UK
        bigint client_id FK
        date invoice_date
        date due_date
        decimal tax
        varchar status "Draft, Open or Cancelled"
        text notes
        bigint created_by FK
    }
    INVOICE_ITEMS {
        bigint id PK
        bigint invoice_id FK
        varchar description
        decimal quantity
        decimal unit_price
    }
    PAYMENTS {
        bigint id PK
        bigint invoice_id FK
        date payment_date
        decimal amount
        varchar payment_method
        varchar reference_number
        text notes
        bigint recorded_by FK
    }
    KNOWLEDGE_ARTICLES {
        bigint id PK
        varchar title
        varchar slug UK
        varchar category
        longtext content
        json tags
        varchar status
        bigint author_id FK
    }
    SETTINGS {
        bigint id PK "application singleton id 1"
        varchar firm_name
        text firm_address
        varchar firm_email
        varchar contact_number
        varchar logo_path
        varchar currency
        smallint page_size
        boolean notifications_enabled
    }
    NOTIFICATIONS {
        uuid id PK
        varchar type
        varchar notifiable_type "Laravel polymorphic user type"
        bigint notifiable_id "logical user reference"
        text data "JSON event snapshot"
        timestamp read_at
    }
    NOTIFICATION_EVENTS {
        bigint id PK
        bigint user_id FK
        varchar event_key "unique with user_id"
    }
    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK "nullable system actor"
        bigint client_id FK "nullable"
        varchar action
        varchar module
        bigint record_id "logical reference within module"
        text description
        varchar ip_address
    }
    SESSIONS {
        varchar id PK
        bigint user_id "indexed logical reference"
        text payload
        int last_activity
    }
```

## Normalization and derived values

Clients are referenced by `client_id`; business names are not duplicated in documents, ledger, compliance or invoices. Users and roles are separate entities. Ledger and invoice lines are child tables, with payments modeled separately from invoice items.

Invoice `subtotal`, `total_amount`, `amount_paid`, `balance` and item `amount` are derived accessors instead of duplicated stored totals. Line amounts round to cents; totals and payments are calculated in integer cents. Stored invoice lifecycle state is Draft/Open/Cancelled; Paid, Partially Paid and Overdue are derived from payments and dates. Compliance overdue and urgency are similarly calculated rather than storing a date-sensitive status that becomes stale.

The core financial/client relationships use normalized relational tables. Knowledge tags are a JSON list for simple article tagging; this is an intentional denormalized convenience rather than a separate tag catalog. Notifications and audit descriptions are event snapshots, so they can retain historical wording. This design does not claim strict 3NF for every ancillary field.

## Constraints and retention

- Foreign keys protect users and clients referenced by business records (`RESTRICT`). Payments also prevent physical deletion of their invoice.
- Invoice/ledger items cascade only on physical deletion of their parent. Normal application operations preserve history with soft deletion or lifecycle status changes.
- Clients, documents, ledger entries, compliance, invoices and knowledge articles have `deleted_at`. Client archiving uses `status=Archived` and can be reversed from the client profile. No application route physically deletes financial records.
- Invoice numbers, document numbers, client codes, article slugs, user emails and role names have unique indexes. Status, dates, foreign keys and commonly searched identifiers are indexed.
- `notification_events` has a unique `(user_id, event_key)` constraint for concurrent reminder deduplication. Its `user_id` has a real FK. Laravel's polymorphic `notifications.notifiable_id`, session user reference and generic audit `record_id` are logical references rather than SQL foreign keys; authorization checks the referenced record when serving notifications.
- Every invoice must have at least one item and every ledger entry at least two items, enforced by request validation and transactional writes. Submission additionally checks balanced, positive debit and credit totals. SQL foreign keys alone do not enforce these minimum child counts.
- There is no required one-to-one business relationship. Settings are a single application-level record, not a child of a specific user.

Laravel's `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` and `migrations` tables support authentication, throttling and framework operations. The application uses database-backed sessions/cache and synchronous notifications by default.


## Three-role RBAC extension

`roles.slug` is unique; the existing `users.role_id` remains the single-role FK mapping. The role/permission relation is many-to-many, enforced with a composite primary key and cascading foreign keys. Notices optionally belong to a client and always belong to a creator; their parent references restrict physical deletion.

```mermaid
erDiagram
    ROLES ||--o{ USERS : classifies
    ROLES ||--o{ PERMISSION_ROLE : grants
    PERMISSIONS ||--o{ PERMISSION_ROLE : assigned
    USERS ||--o{ NOTICES : creates
    CLIENTS o|--o{ NOTICES : concerns
    ROLES {
        bigint id PK
        varchar name UK
        varchar slug UK
    }
    PERMISSIONS {
        bigint id PK
        varchar name UK
    }
    PERMISSION_ROLE {
        bigint permission_id PK,FK
        bigint role_id PK,FK
    }
    NOTICES {
        bigint id PK
        bigint client_id FK
        bigint created_by FK
        varchar title
        text body
        varchar status
        timestamp published_at
        timestamp deleted_at
    }
```
