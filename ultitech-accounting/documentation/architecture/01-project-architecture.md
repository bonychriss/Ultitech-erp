# 01 — Project Architecture (Milestone 01)

## 1. Product positioning

UltiTech Accounting is a **standalone** multi-company accounting platform. Milestone 01 delivers the financial core: tenancy, books, chart of accounts, general ledger posting, reversals, and audit.

Later milestones add AR/AP, banking, assets, tax/standards depth, governance, and ERP integration. Integration remains contract-only until Milestone 08.

## 2. High-level topology

```text
┌─────────────────────────┐     HTTPS/REST /api/v1     ┌──────────────────────────────┐
│  frontend (React 19 SPA)│ ─────────────────────────► │  backend (Laravel API)         │
│  Vite + Tailwind        │ ◄───────────────────────── │  Domain modules + Posting Eng. │
└─────────────────────────┘     JSON + Sanctum tokens  └──────────────┬───────────────┘
                                                                      │
                                                         ┌────────────▼────────────┐
                                                         │ MySQL: ultitech_accounting_dev │
                                                         │ Tables: acc_*                  │
                                                         └────────────┬────────────┘
                                                                      │
                                                         ┌────────────▼────────────┐
                                                         │ Queue: database (M01) / │
                                                         │ Redis (later)           │
                                                         │ Outbox events           │
                                                         └─────────────────────────┘
```

## 3. Backend modular structure (DDD-lite)

Laravel application under `backend/` with domain folders (not premature microservices):

```text
backend/app/
  Domain/
    CompanyManagement/
    AccountingBooks/
    ChartOfAccounts/
    GeneralLedger/
    ForeignCurrency/          # rates only in M01
    AccountingStandards/      # framework/book config in M01
    UserManagement/
    AuditManagement/
    Integrations/             # outbox skeleton in M01
  Application/
    Services/
      JournalPostingService.php
      JournalValidationService.php
      JournalReversalService.php
      AccountBalanceService.php
      AccountingPeriodService.php
      ExchangeRateService.php
      AccountingPolicyService.php   # stub policies M01
      SourceEventService.php
      AuditTrailService.php
  Http/
    Controllers/Api/V1/
    Middleware/
    Requests/
  Models/                     # Eloquent models mapped to acc_* tables
  Policies/
  Support/
    Money.php
    TenantContext.php
```

**GL is authoritative.** No other module writes financial balances except through posting services.

## 4. Multi-tenancy model

- **Strategy:** Shared database, strict tenant isolation.
- **Tenant key:** `company_id` on every company-owned row.
- **Runtime context:** Authenticated user selects an active company; middleware injects `TenantContext`.
- **Enforcement:** Global scopes + explicit policy checks + query constraints. Never trust client-supplied company IDs without membership verification.
- **Books:** A company may have multiple `acc_accounting_books` (e.g. IFRS primary + SME/statutory parallel). Postings always target exactly one book.

## 5. Accounting books & standards (M01 scope)

- Seed frameworks: `IFRS`, `IFRS_SME`.
- Seed jurisdictions: `TZ` (Tanzania), `INTL` (International baseline).
- Each book references: framework, jurisdiction, functional currency, fiscal calendar.
- Policy engine is **config + version tables** only in M01; deep IAS/IFRS rule evaluation expands in M05.

## 6. Posting engine (core)

`JournalPostingService` pipeline:

1. Resolve company + book + period  
2. Validate accounts (active, posting-allowed, same company/book mapping rules)  
3. Validate currency + optional FX conversion to book functional currency  
4. Build immutable debit/credit lines (`DECIMAL`)  
5. Assert Σ debit = Σ credit (exact scale)  
6. Persist journal + lines in one DB transaction  
7. Enforce idempotency via source identity / idempotency key  
8. Write audit log + outbox event  
9. Reject any mutation of posted journals (reversal-only corrections)

## 7. Frontend architecture (M01)

SPA under `frontend/`:

- Auth screens (login)
- Company switcher
- Chart of Accounts browser/editor
- Journal entry create / list / detail / reverse
- Fiscal year/period admin
- Currency & rate admin
- Books & framework settings (basic)
- Design system foundations (layout, tables, forms, empty/loading/error)

Stack: React 19, TypeScript, Vite, Tailwind, React Router, TanStack Query, React Hook Form, Zod, Recharts (dashboard stub only).

Visual direction: clean enterprise SaaS, neutral surfaces, **UltiTech purple accent**, professional financial tables.

## 8. Environments

| Env | Purpose | DB |
|---|---|---|
| local/dev | XAMPP development | `ultitech_accounting_dev` |
| testing | Automated tests | `ultitech_accounting_test` (SQLite in-memory or MySQL test DB) |
| production | Later (M09) | dedicated cloud DB — not configured now |

Git from day one after scaffold. Separate `.env` files; never commit secrets.

## 9. Explicit non-goals for Milestone 01

- AR/AP, banking, assets, inventory, tax returns, budgets, consolidation, leases
- ERP sync / webhooks delivery workers (outbox table only)
- Claiming statutory compliance completeness
- Modifying UltiTech ERP or reusing its DB

## 10. Acceptance principle

A feature is complete only when database, API validation, permissions, posting integrity, and automated tests all pass — UI alone is insufficient.
