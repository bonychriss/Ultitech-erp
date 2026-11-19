# 03 — Complete Initial Database Schema (Milestone 01)

**Engine:** InnoDB  
**Charset:** `utf8mb4` / `utf8mb4_unicode_ci`  
**Prefix:** `acc_`  
**Money:** `DECIMAL(19,4)` (amounts), `DECIMAL(18,8)` (FX rates)  
**IDs:** `BIGINT UNSIGNED` auto-increment  
**Timestamps:** `created_at`, `updated_at`; soft deletes only where business-safe (masters), **never** on posted journals

## Conventions

- Company-owned tables: `company_id` NOT NULL + index + FK → `acc_companies`
- Unique business keys are composite with `company_id` (and `book_id` where required)
- Posted journals: `status = posted` immutable via application rules (+ optional DB triggers later)
- No FLOAT/DOUBLE for money

---

## A. Identity & access

### `acc_users`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| uuid | CHAR(36) UK | public identifier |
| name | VARCHAR(150) | |
| email | VARCHAR(191) UK | |
| password | VARCHAR(255) | hashed |
| is_active | TINYINT(1) | default 1 |
| last_login_at | TIMESTAMP NULL | |
| created_at / updated_at | TIMESTAMP | |

### `acc_roles`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT NULL FK | NULL = system role template |
| code | VARCHAR(50) | e.g. `admin`, `accountant`, `viewer` |
| name | VARCHAR(100) | |
| is_system | TINYINT(1) | |
| created_at / updated_at | TIMESTAMP | |
| UK | (company_id, code) | |

### `acc_permissions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| code | VARCHAR(100) UK | e.g. `journals.post` |
| name | VARCHAR(150) | |
| module | VARCHAR(50) | |

### `acc_role_permissions`
| Column | Type | Notes |
|---|---|---|
| role_id | BIGINT FK | |
| permission_id | BIGINT FK | |
| PK | (role_id, permission_id) | |

### `acc_user_company_access`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | BIGINT FK | |
| company_id | BIGINT FK | |
| role_id | BIGINT FK | |
| is_default | TINYINT(1) | |
| status | VARCHAR(20) | active/suspended |
| UK | (user_id, company_id) | |

Laravel Sanctum personal access tokens use framework `personal_access_tokens` table (no `acc_` prefix; framework-owned).

---

## B. Company & books

### `acc_companies`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| uuid | CHAR(36) UK | |
| code | VARCHAR(30) UK | |
| legal_name | VARCHAR(200) | |
| trading_name | VARCHAR(200) NULL | |
| country_code | CHAR(2) | |
| base_currency_code | CHAR(3) | FK logical → currencies |
| timezone | VARCHAR(64) | |
| status | VARCHAR(20) | active/inactive |
| created_at / updated_at / deleted_at | | soft delete OK for company master |

### `acc_company_settings`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT UK FK | |
| settings_json | JSON | date/number formats, defaults |
| created_at / updated_at | | |

### `acc_branches`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| code | VARCHAR(30) | |
| name | VARCHAR(150) | |
| is_active | TINYINT(1) | |
| UK | (company_id, code) | |

### `acc_currencies`
| Column | Type | Notes |
|---|---|---|
| code | CHAR(3) PK | ISO 4217 |
| name | VARCHAR(100) | |
| symbol | VARCHAR(10) | |
| decimal_places | TINYINT | |
| is_active | TINYINT(1) | |

### `acc_exchange_rates`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| from_currency | CHAR(3) | |
| to_currency | CHAR(3) | |
| rate_date | DATE | |
| rate | DECIMAL(18,8) | |
| source | VARCHAR(50) | manual/import |
| created_by | BIGINT NULL | |
| UK | (company_id, from_currency, to_currency, rate_date) | |

### `acc_frameworks`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| code | VARCHAR(30) UK | `IFRS`, `IFRS_SME` |
| name | VARCHAR(100) | |
| description | TEXT NULL | |

### `acc_jurisdictions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| code | VARCHAR(20) UK | `TZ`, `INTL` |
| name | VARCHAR(100) | |
| default_currency | CHAR(3) | |
| metadata_json | JSON NULL | |

### `acc_accounting_books`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| code | VARCHAR(30) | |
| name | VARCHAR(150) | |
| framework_id | BIGINT FK | |
| jurisdiction_id | BIGINT FK | |
| functional_currency_code | CHAR(3) | |
| is_primary | TINYINT(1) | one primary per company |
| status | VARCHAR(20) | |
| UK | (company_id, code) | |

### `acc_fiscal_years`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| name | VARCHAR(50) | e.g. FY2026 |
| start_date | DATE | |
| end_date | DATE | |
| status | VARCHAR(20) | open/closed |
| UK | (company_id, name) | |

### `acc_fiscal_periods`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| fiscal_year_id | BIGINT FK | |
| period_number | TINYINT | 1–12/13 |
| name | VARCHAR(50) | |
| start_date | DATE | |
| end_date | DATE | |
| status | VARCHAR(20) | open/closed |
| UK | (fiscal_year_id, period_number) | |

### `acc_period_locks`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| period_id | BIGINT FK | |
| locked_at | TIMESTAMP | |
| locked_by | BIGINT | |
| reason | VARCHAR(255) NULL | |
| UK | (company_id, book_id, period_id) | |

---

## C. Chart of accounts

### `acc_account_types`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| code | VARCHAR(30) UK | asset/liability/equity/revenue/expense |
| name | VARCHAR(100) | |
| normal_balance | ENUM('debit','credit') | |
| fs_classification | VARCHAR(50) | BS/PL |

### `acc_account_groups`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| code | VARCHAR(30) | |
| name | VARCHAR(150) | |
| account_type_id | BIGINT FK | |
| UK | (company_id, book_id, code) | |

### `acc_accounts`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| account_type_id | BIGINT FK | |
| account_group_id | BIGINT NULL FK | |
| parent_id | BIGINT NULL FK | self |
| code | VARCHAR(40) | |
| name | VARCHAR(200) | |
| description | TEXT NULL | |
| normal_balance | ENUM('debit','credit') | |
| is_postable | TINYINT(1) | |
| is_system | TINYINT(1) | |
| allow_manual_entry | TINYINT(1) | |
| currency_code | CHAR(3) NULL | optional restriction |
| status | VARCHAR(20) | active/inactive |
| level | INT | hierarchy depth |
| path | VARCHAR(500) NULL | materialized path optional |
| UK | (company_id, book_id, code) | |

### `acc_account_mappings`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| mapping_key | VARCHAR(100) | e.g. `retained_earnings` |
| account_id | BIGINT FK | |
| UK | (company_id, book_id, mapping_key) | |

### `acc_account_openings`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| account_id | BIGINT FK | |
| period_id | BIGINT FK | opening period |
| debit | DECIMAL(19,4) | |
| credit | DECIMAL(19,4) | |
| posted_journal_id | BIGINT NULL | link after posting opening JE |
| UK | (company_id, book_id, account_id, period_id) | |

---

## D. General ledger

### `acc_journal_sources`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| code | VARCHAR(40) UK | `manual`, `opening`, `reversal`, `system` |
| name | VARCHAR(100) | |

### `acc_posting_sequences`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| sequence_key | VARCHAR(40) | `journal` |
| prefix | VARCHAR(20) | |
| next_number | BIGINT | |
| padding | TINYINT | |
| UK | (company_id, book_id, sequence_key) | |

### `acc_posting_batches`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| batch_number | VARCHAR(40) | |
| description | VARCHAR(255) NULL | |
| created_by | BIGINT | |
| created_at / updated_at | | |

### `acc_journal_entries`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| uuid | CHAR(36) UK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| period_id | BIGINT FK | |
| source_id | BIGINT FK | |
| batch_id | BIGINT NULL FK | |
| journal_number | VARCHAR(40) | |
| journal_date | DATE | |
| posting_date | DATE | |
| description | VARCHAR(500) | |
| reference | VARCHAR(100) NULL | |
| status | VARCHAR(20) | draft/posted/reversed |
| currency_code | CHAR(3) | transaction currency |
| exchange_rate | DECIMAL(18,8) NULL | to functional |
| total_debit | DECIMAL(19,4) | |
| total_credit | DECIMAL(19,4) | |
| source_type | VARCHAR(80) NULL | polymorphic source |
| source_id_ref | VARCHAR(80) NULL | external/source id |
| source_hash | VARCHAR(64) NULL | idempotency |
| posted_at | TIMESTAMP NULL | |
| posted_by | BIGINT NULL | |
| created_by | BIGINT | |
| created_at / updated_at | | **no deleted_at** |
| UK | (company_id, book_id, journal_number) | |
| UK | (company_id, book_id, source_hash) WHERE source_hash NOT NULL | app-enforced / partial unique if supported |

### `acc_journal_lines`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| journal_id | BIGINT FK | |
| company_id | BIGINT FK | denormalized for tenant queries |
| book_id | BIGINT FK | |
| line_no | INT | |
| account_id | BIGINT FK | |
| description | VARCHAR(255) NULL | |
| debit | DECIMAL(19,4) | txn currency |
| credit | DECIMAL(19,4) | txn currency |
| currency_code | CHAR(3) | |
| exchange_rate | DECIMAL(18,8) NULL | |
| functional_debit | DECIMAL(19,4) | book currency |
| functional_credit | DECIMAL(19,4) | book currency |
| dimensions_json | JSON NULL | future cost centre/project |
| created_at / updated_at | | |

Check constraint (app + DB if available): `(debit = 0 OR credit = 0)` and not both non-zero; both zero rejected.

### `acc_journal_reversals`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| original_journal_id | BIGINT FK | |
| reversal_journal_id | BIGINT FK | |
| reversed_at | TIMESTAMP | |
| reversed_by | BIGINT | |
| reason | VARCHAR(255) | |
| UK | (original_journal_id) | one full reversal |

### `acc_journal_attachments`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| journal_id | BIGINT FK | |
| disk | VARCHAR(50) | |
| path | VARCHAR(500) | |
| original_name | VARCHAR(255) | |
| mime_type | VARCHAR(100) | |
| size_bytes | BIGINT | |
| uploaded_by | BIGINT | |
| created_at / updated_at | | |

### `acc_posting_rules` (seed/skeleton)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT NULL | NULL = system default |
| book_id | BIGINT NULL | |
| code | VARCHAR(50) | |
| rule_json | JSON | |
| is_active | TINYINT(1) | |

---

## E. Standards (foundation seed)

### `acc_standards`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| framework_id | BIGINT FK | |
| code | VARCHAR(30) | IAS 1, IFRS 15, … |
| title | VARCHAR(200) | |

### `acc_standard_versions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| standard_id | BIGINT FK | |
| version_label | VARCHAR(50) | |
| effective_from | DATE | |
| effective_to | DATE NULL | |
| notes | TEXT NULL | |

### `acc_accounting_policies`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| book_id | BIGINT FK | |
| code | VARCHAR(50) | |
| name | VARCHAR(150) | |
| UK | (company_id, book_id, code) | |

### `acc_policy_versions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| policy_id | BIGINT FK | |
| version_no | INT | |
| effective_from | DATE | |
| policy_json | JSON | recognition/measurement/presentation stubs |
| status | VARCHAR(20) | draft/approved |
| approved_by | BIGINT NULL | |
| approved_at | TIMESTAMP NULL | |

### `acc_disclosure_templates` / `acc_reporting_templates`
Skeleton tables for M03/M05 (codes, framework_id, template_json, effective dates).

### `acc_compliance_rules`
Skeleton: jurisdiction_id, code, rule_json, effective_from/to.

---

## F. Governance & integration skeleton

### `acc_audit_logs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT NULL | |
| user_id | BIGINT NULL | |
| action | VARCHAR(50) | |
| entity_type | VARCHAR(80) | |
| entity_id | VARCHAR(80) | |
| before_json | JSON NULL | |
| after_json | JSON NULL | |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(255) NULL | |
| created_at | TIMESTAMP | indexed |

### `acc_document_sequences`
Generic numbering (invoices later); M01 uses for journals via `acc_posting_sequences`.

### `acc_idempotency_keys`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| key_hash | CHAR(64) | |
| request_path | VARCHAR(200) | |
| response_code | INT | |
| response_body | JSON NULL | |
| created_at | TIMESTAMP | |
| UK | (company_id, key_hash) | |

### `acc_outbox_events`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| company_id | BIGINT FK | |
| event_type | VARCHAR(100) | |
| aggregate_type | VARCHAR(80) | |
| aggregate_id | BIGINT | |
| payload_json | JSON | |
| status | VARCHAR(20) | pending/processed/failed |
| available_at | TIMESTAMP | |
| processed_at | TIMESTAMP NULL | |
| created_at | TIMESTAMP | |

---

## Indexes (mandatory highlights)

- `(company_id, book_id, journal_date)` on journals  
- `(company_id, book_id, account_id)` on lines  
- `(company_id, action, created_at)` on audit logs  
- `(status, available_at)` on outbox  
- Foreign keys on all relationships above  
- Composite uniques listed per table  

## Seed data (M01)

1. Currencies: TZS, USD, EUR, GBP, KES, UGX (extendable)  
2. Frameworks: IFRS, IFRS_SME  
3. Jurisdictions: TZ, INTL  
4. Account types: asset, liability, equity, revenue, expense  
5. Journal sources: manual, opening, reversal, system  
6. Permissions + system roles  
7. Representative standard codes (IAS/IFRS list) with version rows noting IFRS 18 / SME 3rd edition 2027 effective awareness  

Tax rates are **not** hardcoded seeds as binding statutory truth; tax engine arrives M05 with effective-dated rules.
