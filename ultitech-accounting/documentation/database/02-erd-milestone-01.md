# 02 — Database ER Diagram (Milestone 01)

Logical ERD for foundation tables only. All monetary amounts use `DECIMAL`. Every company-owned entity includes `company_id` unless it is a global reference table.

## Mermaid ERD

```mermaid
erDiagram
  acc_users ||--o{ acc_user_company_access : has
  acc_companies ||--o{ acc_user_company_access : grants
  acc_roles ||--o{ acc_user_company_access : assigns
  acc_roles ||--o{ acc_role_permissions : has
  acc_permissions ||--o{ acc_role_permissions : maps

  acc_companies ||--o{ acc_company_settings : has
  acc_companies ||--o{ acc_branches : has
  acc_companies ||--o{ acc_fiscal_years : has
  acc_fiscal_years ||--o{ acc_fiscal_periods : contains
  acc_companies ||--o{ acc_accounting_books : has
  acc_currencies ||--o{ acc_accounting_books : functional
  acc_frameworks ||--o{ acc_accounting_books : applies
  acc_jurisdictions ||--o{ acc_accounting_books : applies

  acc_companies ||--o{ acc_exchange_rates : owns
  acc_currencies ||--o{ acc_exchange_rates : from
  acc_currencies ||--o{ acc_exchange_rates : to

  acc_account_types ||--o{ acc_accounts : classifies
  acc_account_groups ||--o{ acc_accounts : groups
  acc_companies ||--o{ acc_accounts : owns
  acc_accounting_books ||--o{ acc_accounts : scoped
  acc_accounts ||--o{ acc_accounts : parent_of
  acc_accounts ||--o{ acc_account_openings : openings
  acc_fiscal_periods ||--o{ acc_account_openings : period

  acc_companies ||--o{ acc_journal_entries : owns
  acc_accounting_books ||--o{ acc_journal_entries : book
  acc_fiscal_periods ||--o{ acc_journal_entries : period
  acc_journal_sources ||--o{ acc_journal_entries : source
  acc_journal_entries ||--|{ acc_journal_lines : lines
  acc_accounts ||--o{ acc_journal_lines : account
  acc_journal_entries ||--o| acc_journal_reversals : reversed_by
  acc_journal_entries ||--o{ acc_journal_attachments : files
  acc_posting_batches ||--o{ acc_journal_entries : batches
  acc_posting_sequences ||--o{ acc_journal_entries : numbers

  acc_frameworks ||--o{ acc_standards : contains
  acc_standards ||--o{ acc_standard_versions : versions
  acc_companies ||--o{ acc_accounting_policies : owns
  acc_accounting_policies ||--o{ acc_policy_versions : versions

  acc_companies ||--o{ acc_period_locks : locks
  acc_fiscal_periods ||--o{ acc_period_locks : locked
  acc_companies ||--o{ acc_audit_logs : audits
  acc_companies ||--o{ acc_outbox_events : publishes
  acc_companies ||--o{ acc_idempotency_keys : dedupes
  acc_companies ||--o{ acc_document_sequences : sequences

  acc_companies {
    bigint id PK
    string code UK
    string name
    string base_currency_code
    string status
  }

  acc_accounting_books {
    bigint id PK
    bigint company_id FK
    string code
    string name
    bigint framework_id FK
    bigint jurisdiction_id FK
    string functional_currency_code
    boolean is_primary
  }

  acc_accounts {
    bigint id PK
    bigint company_id FK
    bigint book_id FK
    string code
    string name
    bigint parent_id FK
    string normal_balance
    boolean is_postable
    boolean is_system
    string status
  }

  acc_journal_entries {
    bigint id PK
    bigint company_id FK
    bigint book_id FK
    bigint period_id FK
    string journal_number
    string status
    datetime posted_at
    string source_hash
  }

  acc_journal_lines {
    bigint id PK
    bigint journal_id FK
    bigint account_id FK
    decimal debit
    decimal credit
    string currency_code
    decimal functional_debit
    decimal functional_credit
  }
```

## Relationship rules

1. **Company isolation:** Journals, accounts, openings, rates, policies, locks, audit rows are company-scoped.
2. **Book isolation:** Accounts and journals belong to one book; reports never mix books unless explicitly consolidated later.
3. **Period integrity:** Posted journals require an open (unlocked) fiscal period for that company/book.
4. **Immutability:** Posted `acc_journal_entries` / lines are not updated; reversals create new journals linked via `acc_journal_reversals`.
5. **Global catalogs:** `acc_currencies`, `acc_frameworks`, `acc_standards`, `acc_standard_versions`, `acc_jurisdictions`, `acc_account_types`, `acc_permissions` are system-shared references (no `company_id`).

## Future groups (not in M01 DDL)

Receivables, payables, banking, fixed assets, tax returns, budgets, webhooks delivery — reserved names documented in master plan; created in later milestones.
