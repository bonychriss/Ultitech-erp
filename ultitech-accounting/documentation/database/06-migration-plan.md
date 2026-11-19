# 06 — Initial Migration Plan (Milestone 01)

## Goals

Create a clean, ordered Laravel migration set that builds only foundation tables, with seeds for catalogs and a demo company for local development.

## Preconditions

1. Design approved  
2. Database created:

```sql
CREATE DATABASE IF NOT EXISTS ultitech_accounting_dev
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS ultitech_accounting_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

3. Laravel project scaffolded in `backend/`  
4. `.env` points **only** at `ultitech_accounting_dev`  
5. ERP databases remain untouched  

## Migration order

| # | Migration file (proposed) | Purpose |
|---|---|---|
| 01 | `create_acc_users_table` | users |
| 02 | `create_personal_access_tokens_table` | Sanctum |
| 03 | `create_acc_permissions_and_roles_tables` | permissions, roles, pivots |
| 04 | `create_acc_currencies_table` | currencies |
| 05 | `create_acc_frameworks_and_jurisdictions_tables` | standards catalogs |
| 06 | `create_acc_companies_tables` | companies, settings, branches, user_company_access |
| 07 | `create_acc_accounting_books_table` | books |
| 08 | `create_acc_exchange_rates_table` | FX |
| 09 | `create_acc_fiscal_calendar_tables` | years, periods, locks |
| 10 | `create_acc_chart_of_accounts_tables` | types, groups, accounts, mappings, openings |
| 11 | `create_acc_general_ledger_tables` | sources, sequences, batches, journals, lines, reversals, attachments |
| 12 | `create_acc_standards_and_policies_tables` | standards, versions, policies, templates skeletons |
| 13 | `create_acc_governance_integration_tables` | audit_logs, idempotency, outbox, posting_rules, document_sequences |

Each migration is reversible (`down()` drops in reverse dependency order).

## Seeder plan

| Seeder | Data |
|---|---|
| `CurrencySeeder` | TZS, USD, EUR, GBP, KES, UGX |
| `FrameworkSeeder` | IFRS, IFRS_SME |
| `JurisdictionSeeder` | TZ, INTL |
| `AccountTypeSeeder` | 5 primary types |
| `JournalSourceSeeder` | manual, opening, reversal, system |
| `PermissionSeeder` | M01 permission catalog |
| `RoleSeeder` | system templates: Admin, Accountant, Viewer |
| `StandardCatalogSeeder` | IAS/IFRS codes + version placeholders (incl. IFRS 18 / SME 2027 notes) |
| `DemoCompanySeeder` (local only) | demo company, primary book (TZ + IFRS), FY + periods, sample CoA, admin user |

`DatabaseSeeder` orchestrates; Demo seeder guarded by `app()->environment('local')`.

## Data type rules in migrations

- Money: `decimal(19, 4)`
- Rates: `decimal(18, 8)`
- Indexes on all FKs and tenant composite keys
- Prefer explicit FK constraints
- No float/double

## Rollback strategy

- Dev: `php artisan migrate:fresh --seed`
- Never run destructive refresh against non-dev DBs
- Schema changes after first release use additive migrations only

## Verification checklist after migrate

- [ ] All M01 tables exist with `acc_` prefix (plus Sanctum table)
- [ ] FKs succeed
- [ ] Seeds load
- [ ] Demo login works
- [ ] Can post balanced journal in demo book
- [ ] Trial balance runs without error
- [ ] Second company cannot read first company journals (test)

## Outbox / queues

- M01: `QUEUE_CONNECTION=database` (or `sync` for simplest local)
- Redis deferred until installed

## ERP safety gate

Migrations and seeders must not reference ERP table names or ERP connection names. Single default MySQL connection only for M01.
