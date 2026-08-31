# Balances Module

Liquidity and chart-of-accounts management for the OmmyERP staff portal. The module tracks **financial accounts** (cash, bank, mobile money, and COA-style types), **account transactions**, **internal transfers**, and links into **accounting reports** and **payment workflows**.

---

## Table of contents

1. [Overview](#overview)
2. [URLs and routing](#urls-and-routing)
3. [Architecture](#architecture)
4. [Directory structure](#directory-structure)
5. [Database schema](#database-schema)
6. [Pages and features](#pages-and-features)
7. [Sidebar navigation](#sidebar-navigation)
8. [Access control](#access-control)
9. [Core PHP API (`functions.php`)](#core-php-api-functionsphp)
10. [HTTP / AJAX endpoints](#http--ajax-endpoints)
11. [Integrations with other modules](#integrations-with-other-modules)
12. [Account types and code series](#account-types-and-code-series)
13. [Balance calculation](#balance-calculation)
14. [UI and layout conventions](#ui-and-layout-conventions)
15. [Bootstrap and dependencies](#bootstrap-and-dependencies)
16. [Maintenance and migration scripts](#maintenance-and-migration-scripts)
17. [Troubleshooting](#troubleshooting)
18. [Deployment checklist](#deployment-checklist)

---

## Overview

| Item | Value |
|------|--------|
| **Module key** | `balances` (query param `?module=balances`) |
| **Entry from app hub** | Select Module ? **Balances** |
| **Primary audience** | Finance staff and administrators |
| **Base currency** | TZS (per-account `currency` column; default `TZS`) |
| **Timezone context** | Dashboard copy uses server date; aligns with ERP `Africa/Dar_es_Salaam` where configured |

### What it does

- **Liquidity dashboard** — total liquidity, cash/bank/mobile splits, 30-day cash-flow chart, top accounts, AI/rule-based insights.
- **Account registry** — list, create (simple), edit, deactivate, or permanently delete accounts.
- **Chart of accounts (COA) create/edit** — full account setup with auto account codes, categories, reporting groups, and metadata stored in `financial_accounts`.
- **Account categories** — hierarchical reporting categories (Current Assets, COGS, etc.).
- **Account types** — configurable COA types with numeric code ranges (1000–5999 blocks).
- **Transaction ledger** — searchable, paginated history with drill-down.
- **Internal transfers** — paired debit/credit rows across two active accounts.
- **Bank reconciliation** — UI lives under `accounting/reconciliation.php?module=balances` (demo/workflow shell).
- **Accounting reports** (admin) — Profit & Loss, Trial Balance, Balance Sheet when `module=balances` is active.

---

## URLs and routing

### Local (XAMPP)

```
http://localhost/public_html/modules/balances/index.php?module=balances
```

### Multi-tenant (company slug)

Apache rewrite rules map slug-prefixed paths to the same files with `company_slug`:

```
https://{domain}/{company_slug}/modules/balances/index.php?module=balances
```

Example (Ultimate):

```
http://localhost/public_html/ultimate/modules/balances/coa_create.php?module=balances
```

Always preserve **`module=balances`** (and **`company_slug`** when using tenant URLs) on internal links so the sidebar stays on the Balances menu.

### Related accounting URLs (same module context)

| Page | Path |
|------|------|
| Reconciliation | `/accounting/reconciliation.php?module=balances` |
| Trial Balance | `/accounting/trial-balance.php?module=balances` |
| Profit & Loss | `/accounting/profit-loss.php` |
| Balance Sheet | `/accounting/balance-sheet.php` |

---

## Architecture

```
???????????????????????????????????????????????????????????????
?  Browser (Bootstrap 5 + Tailwind utilities + Chart.js)      ?
???????????????????????????????????????????????????????????????
                            ?
???????????????????????????????????????????????????????????????
?  modules/balances/*.php  (page controllers + inline JS/CSS) ?
???????????????????????????????????????????????????????????????
                            ?
        ?????????????????????????????????????????
        ?                   ?                   ?
??????????????????  ??????????????????  ????????????????????????
? config/        ?  ? includes/      ?  ? api/ai_insights.php  ?
? database.php   ?  ? header.php     ?  ? (JSON, OpenAI)       ?
?                ?  ? footer.php     ?  ????????????????????????
??????????????????  ??????????????????
        ?                   ?
        ?????????????????????
                  ?
???????????????????????????????????????????????????????????????
?  functions.php — schema, PDO resolution, COA helpers,       ?
?  balances, insights, deposit-account pickers                  ?
???????????????????????????????????????????????????????????????
                              ?
???????????????????????????????????????????????????????????????
?  includes/config.php + includes/functions.php (global ERP)  ?
?  Tenant switch via company_slug / session company_id        ?
???????????????????????????????????????????????????????????????
                              ?
???????????????????????????????????????????????????????????????
?  MySQL — financial_accounts, account_transactions,          ?
?  financial_account_categories, financial_account_types,     ?
?  erp_account_categories, erp_reporting_groups (optional)    ?
???????????????????????????????????????????????????????????????
```

### PDO resolution

`config/database.php` calls `balancesSyncGlobalPdo()` / `balances_resolve_pdo()` to pick the **best available connection** that actually contains `financial_accounts`:

1. Tenant DB from `companies.db_name` (control DB lookup)
2. `$GLOBALS['tenant_pdo']`
3. Global `$pdo` after tenant switch
4. Revenue PDO (`revenue_resolve_pdo()`)
5. `DATA_DB_NAME` / `SALES_DB_NAME` candidates via `connectToTenantDatabase()`

The connection with the **highest account row count** wins. This avoids empty dashboards when the control DB is connected but tenant data lives elsewhere.

On bootstrap, these run automatically:

- `ensureBalancesSchema()`
- `ensureCoaReferenceSchema()`
- `ensureFinancialAccountCategoriesSchema()`
- `balances_ensure_account_types_schema()` (on first use)

---

## Directory structure

```
modules/balances/
??? README.md                    ? this file
??? config/
?   ??? database.php             ? Module bootstrap + schema ensure
??? includes/
?   ??? header.php               ? HTML head + header_employee + sidebar
?   ??? footer.php               ? Closes layout, DataTables, Lottie toasts
?   ??? lottie-form-overlay.php  ? Success animation overlay
??? api/
?   ??? ai_insights.php          ? POST JSON — OpenAI liquidity insights
??? assets/
?   ??? lottie/
?       ??? voucher-success.json ? Reused for success overlay
??? functions.php                ? All shared module logic (~1400 lines)
??? index.php                    ? Liquidity dashboard
??? accounts.php                 ? Account list + simple CRUD modal
??? coa_create.php               ? Full COA account wizard
??? coa_edit.php                 ? Edit existing COA account
??? category_create.php          ? Account categories CRUD + list
??? category_edit.php            ? Edit category
??? category_view.php            ? View category
??? account_type_create.php      ? Account types + code ranges
??? transactions.php             ? Transaction ledger
??? view-transaction.php         ? Single transaction detail
??? view_account.php             ? Single account detail
??? transfer.php                 ? Internal transfer form
??? update_schema_type.php       ? One-off: type column ? VARCHAR(50)
??? update_schema_type_v2.php      ? Legacy migration helper
??? coa_create_probe.php         ? Dev diagnostic (not for production)
```

---

## Database schema

### `financial_accounts`

Core liquidity / COA account registry.

| Column | Type | Notes |
|--------|------|--------|
| `id` | INT PK | |
| `company_id` | INT NULL | Added when multi-company column exists; skipped on pure tenant DBs |
| `name` | VARCHAR(100) | COA accounts often stored as `{code} - {name}` |
| `type` | VARCHAR(50) | e.g. `cash`, `bank`, `mobile`, or COA-only `asset`, `liability`, … |
| `currency` | VARCHAR(3) | Default `TZS` |
| `opening_balance` | DECIMAL(15,2) | |
| `current_balance` | DECIMAL(15,2) | Updated by `recalculateBalance()` |
| `status` | ENUM | `active` / `inactive` |
| `created_at`, `updated_at` | TIMESTAMP | |

**Live balance** (not a column): computed in queries as:

`opening_balance + SUM(credits) - SUM(debits)` ? exposed as `live_balance`.

### `account_transactions`

| Column | Type | Notes |
|--------|------|--------|
| `id` | INT PK | |
| `company_id` | INT NULL | Optional scope |
| `account_id` | INT FK | ? `financial_accounts.id` |
| `transaction_date` | DATETIME | |
| `type` | ENUM | `credit` (inflow) / `debit` (outflow) |
| `amount` | DECIMAL(15,2) | Always positive; sign implied by type |
| `reference_type` | VARCHAR(50) | e.g. `payment_voucher`, `transfer_in`, `transfer_out` |
| `reference_id` | INT NULL | Linked document id when applicable |
| `description` | TEXT | |
| `created_by` | INT NULL | ? `users.id` |
| `created_at` | TIMESTAMP | |

### `financial_account_categories`

Rich category dimension for COA (separate from simple `erp_account_categories`).

| Column | Highlights |
|--------|------------|
| `code` | Unique short code (auto-generated) |
| `name` | Display name |
| `account_type` | Asset, Liability, Equity, Revenue, Expense |
| `reporting_group`, `financial_statement` | Reporting / statement mapping |
| `parent_id` | Optional hierarchy |
| `status` | Active / Inactive |

Seeded on first run via `coa_default_financial_account_categories()` in `functions.php`.

### `financial_account_types`

Configurable COA **type** list and **auto-code ranges**.

| Column | Notes |
|--------|--------|
| `slug` | Unique key, e.g. `bank`, `petty_cash` |
| `label` | UI label |
| `code_range_min`, `code_range_max` | Inclusive range for auto account codes |
| `status` | Active / Inactive |
| `display_order` | Sort order in dropdowns |

Default seed: asset, cash, bank, mobile, liability, equity, revenue, expense (see [Account types](#account-types-and-code-series)).

### Reference tables (optional COA metadata)

Created by `ensureCoaReferenceSchema()`:

- `erp_account_categories` — legacy/simple category names
- `erp_reporting_groups` — reporting group names linked to categories

Used by `coa_load_categories()` / `coa_load_reporting_groups()` with PHP fallbacks when tables are empty.

---

## Pages and features

### Liquidity dashboard — `index.php`

- KPIs: total liquidity, cash on hand, bank, mobile money
- Charts: 30-day credit/debit trend, account mix donut, top accounts bar chart
- **AI Insights** panel (rule-based + optional OpenAI via `api/ai_insights.php`)
- Operational alerts: negative balances, pending/approved payment vouchers
- Quick actions: create account, transfer, transactions (role-dependent)

### All accounts — `accounts.php`

- Grid/list of accounts with live balances
- Modal **create** (finance/admin): name, type, currency, opening balance
- **Edit** (admin only): update fields + `recalculateBalance()`
- **Deactivate** or **permanent delete** (admin; delete removes transactions first)
- Link to COA create, categories, view account

### New account (COA wizard) — `coa_create.php`

Full chart-of-accounts onboarding:

- Auto **account code** from type series (`?action=next_account_code` AJAX)
- Sections: General, Classification, Opening balance, Settings, Manual
- Persists to `financial_accounts` with `type` mapped to deposit type (`cash`/`bank`/`mobile`) or COA-only types
- Metadata compiled into description blob on the account row
- Links to **Manage categories** and **Manage account types**

**Access:** `isAdmin()` OR `isFinance()`.

### Edit account — `coa_edit.php`

Same field model as create; loads existing account by `?id=`.

### Account categories — `category_create.php`

- Create / edit / list categories
- Auto category code (`?action=next_category_code`)
- Account type: Asset, Liability, Equity, Revenue, Expense
- Preview card and searchable table

**Access:** admin or finance.

### Account types — `account_type_create.php`

- List types with code ranges
- Add custom type (slug, label, min/max code)
- Activate / deactivate types
- Drives COA create dropdown and `coa_compute_next_account_code()`

**Access:** admin or finance.

### Category view / edit — `category_view.php`, `category_edit.php`

Read-only and edit flows for a single `financial_account_categories` row.

### Transaction ledger — `transactions.php`

- Global search across account, description, reference, user, amount, dates
- KPI row: entries, inflows, outflows, net movement
- Pagination (`per_page` 5–100 or `all`)
- Preserves `module` and `company_slug` in URLs via `balancesQs()`
- Row click ? `view-transaction.php`
- Voucher references link to `view-voucher.php`

### View transaction — `view-transaction.php`

Detail page for one `account_transactions` row.

### View account — `view_account.php`

Account summary and related transaction snippet.

### Internal transfer — `transfer.php`

- Select from/to active accounts
- Creates **two** rows: `transfer_out` (debit), `transfer_in` (credit)
- Auto **transfer method** label from liquidity buckets (Cash/Bank/Mobile)
- Recalculates both account balances
- Reference number default: `ITR-{timestamp}`

### Bank reconciliation — `accounting/reconciliation.php`

Rendered with balances sidebar when `?module=balances`. Currently uses **demo/sample data** for bank vs system line matching (not wired to live bank feed API in this repo).

---

## Sidebar navigation

Active when URL path contains `/modules/balances/` or `?module=balances` on accounting pages.

Defined in root `sidebar.php` (primary) under `case 'balances':`

| Menu item | Target |
|-----------|--------|
| Dashboard | `modules/balances/index.php` |
| **Account** (parent) | |
| ? All Accounts | `modules/balances/accounts.php` |
| ? New Account | `modules/balances/coa_create.php?module=balances` |
| ? Account Category | `modules/balances/category_create.php?module=balances` |
| ? Account Type | `modules/balances/account_type_create.php?module=balances` |
| Stock Purchase Payments | `modules/finance/stock-purchase-payment-desk.php?module=balances` (finance/admin only) |
| Transactions | `modules/balances/transactions.php` |
| Internal Transfer | `modules/balances/transfer.php` |
| Reconciliation | `accounting/reconciliation.php?module=balances` |
| Profit & Loss | `accounting/profit-loss.php` (admin) |
| Trial Balance | `accounting/trial-balance.php?module=balances` (admin) |
| Balance Sheet | `accounting/balance-sheet.php` (admin) |

A flatter variant exists in `includes/sidebar.php` for older layouts.

---

## Access control

| Capability | Admin | Finance | Other roles |
|------------|-------|---------|-------------|
| View dashboard, accounts, transactions, transfer | ? | ? | ? (if logged in) |
| Simple account create (`accounts.php`) | ? | ? | ? |
| COA create / categories / account types | ? | ? | ? |
| Edit account (`accounts.php`) | ? | ? | ? |
| Delete / deactivate account | ? | ? | ? |
| Accounting reports in sidebar | ? | ? | ? |

Redirects use `$_SESSION['error']` and `redirect()` to `accounts.php` or login.

---

## Core PHP API (`functions.php`)

### Connection and schema

| Function | Purpose |
|----------|---------|
| `balances_resolve_pdo()` | Pick best PDO with `financial_accounts` data |
| `balancesSyncGlobalPdo($conn)` | Set `$GLOBALS['pdo']` for module pages |
| `ensureBalancesSchema()` | Create `financial_accounts`, `account_transactions` |
| `ensureFinancialAccountCategoriesSchema()` | Category table + seed |
| `ensureCoaReferenceSchema()` | ERP reference tables + seed |
| `balances_ensure_account_types_schema()` | Account types table + seed |

### Accounts and balances

| Function | Purpose |
|----------|---------|
| `balancesFetchAccountsWithLiveBalance($pdo, $activeOnly)` | Accounts with computed `live_balance` |
| `balancesFetchDepositAccounts($pdo)` | Active accounts suitable for payment deposit (excludes pure COA types) |
| `balancesAccountLiquidityBucket($type)` | Maps type ? `cash` \| `bank` \| `mobile` |
| `balancesCoaOnlyAccountTypes()` | `asset`, `liability`, `equity`, `revenue`, `expense` |
| `balancesIsDepositAccountType($type)` | Whether type can hold liquidity |
| `recalculateBalance($accountId)` | Recompute `current_balance` from opening + txs |
| `balancesUseCompanyScope()` | Whether `company_id` filtering applies |

### COA helpers

| Function | Purpose |
|----------|---------|
| `coa_load_financial_account_categories($pdo)` | Active categories for dropdowns |
| `coa_load_categories($pdo)` | Merged DB + default categories |
| `coa_load_reporting_groups($pdo)` | Reporting groups for dropdowns |
| `coa_ensure_account_category(...)` | Insert category by name if missing |
| `balances_fetch_account_types($pdo)` | Active account types for UI |
| `balances_account_type_code_range($pdo, $slug)` | Min/max code for a slug |

### Insights and alerts

| Function | Purpose |
|----------|---------|
| `balances_build_insights($metrics, $pdo)` | Rule-based highlights/suggestions/alerts |
| `balances_fetch_operational_alerts($pdo, $accounts)` | Vouchers, negative balances |
| `balances_ai_is_connected()` | OpenAI configured via `includes/ai_helpers.php` |

---

## HTTP / AJAX endpoints

| Endpoint | Method | Auth | Response |
|----------|--------|------|----------|
| `coa_create.php?action=next_account_code&account_type={slug}` | GET | Finance/Admin | JSON `{ success, next_code }` |
| `category_create.php?action=next_category_code&name=...` | GET | Finance/Admin | JSON `{ success, code }` |
| `api/ai_insights.php` | POST | Logged in + AI enabled | JSON insights or error |

AI endpoint checks company daily limits via `ai_check_company_limit()`.

---

## Integrations with other modules

| Module | Integration |
|--------|-------------|
| **Select Module** | Tile links to `modules/balances/index` |
| **Sales ? Record Payment** | `balancesFetchDepositAccounts()` populates deposit account dropdown |
| **Payment vouchers** | Dashboard alerts for pending/approved unpaid vouchers; transaction refs |
| **Finance** | Stock purchase payment desk in balances sidebar |
| **Accounting** | Shared reports and reconciliation under `?module=balances` |
| **Global ERP** | `recordTransaction()` / voucher flows may write `account_transactions` (see `includes/functions.php`) |

When adding payment recording elsewhere, prefer:

```php
require_once __DIR__ . '/modules/balances/functions.php';
$pdo = balances_resolve_pdo();
$accounts = balancesFetchDepositAccounts($pdo);
```

---

## Account types and code series

Default COA code blocks (configurable in **Account Type** screen):

| Slug | Label | Code range |
|------|-------|------------|
| asset, cash, bank, mobile | Asset / Cash / Bank / Mobile | 1000–1999 |
| liability | Liability | 2000–2999 |
| equity | Equity | 3000–3999 |
| revenue | Revenue | 4000–4999 |
| expense | Expense | 5000–5999 |

`coa_compute_next_account_code()` scans existing account **names** for leading numeric codes in the matching range and returns the next integer.

COA create maps UI type ? `financial_accounts.type` for liquidity:

| UI type | Stored `type` |
|---------|----------------|
| asset, liability, equity | bank |
| revenue, expense | cash |
| cash | cash |
| bank | bank |
| mobile | mobile |

Pure COA types (`asset`, `liability`, etc.) are **excluded** from deposit account pickers but appear in the chart.

---

## Balance calculation

```
current_balance = opening_balance
                + SUM(credits)
                - SUM(debits)
```

- **Credits** = money in (including transfer in).
- **Debits** = money out (including transfer out).
- Dashboard KPIs often use **live_balance** from SQL aggregation (same formula).
- After transfers or manual fixes, call `recalculateBalance($accountId)`.

---

## UI and layout conventions

- **Shell:** `includes/header.php` ? global `header_employee.php` + root `sidebar.php`
- **Layout class:** `stock-dash` / `bal-shell` on newer pages; COA uses `editor-shell` + section nav
- **CSS:** Bootstrap 5 + Tailwind 2.x utilities + Inter font
- **Charts:** Chart.js on dashboard
- **Success feedback:** Session `bal_lottie_success` consumed in footer Lottie overlay (suppresses default SweetAlert for success on mobile)
- **Query preservation:** Always pass `module=balances` and `company_slug` when building links

### Known UI caveat (transactions filters)

`transactions.php` scopes CSS so Bootstrap `.collapse` does not hide the filter panel (`#txFiltersCollapse`). Do not remove without retesting filters.

---

## Bootstrap and dependencies

| Dependency | Used for |
|------------|----------|
| `includes/config.php` | Session, tenant DB, `APP_BASE_PATH` |
| `includes/functions.php` | Auth, `requireLogin`, `isFinance`, flash messages |
| Bootstrap 5.3 | Grid, forms, collapse |
| Bootstrap Icons / Font Awesome | Icons |
| DataTables (footer) | Tables where `.datatable` is used |
| SweetAlert2 | Confirmations (header + pages) |
| Chart.js 4.x | Dashboard charts |
| Tailwind 2.2 (CDN) | Utility classes on dashboard/COA |

---

## Maintenance and migration scripts

| File | Purpose |
|------|---------|
| `update_schema_type.php` | Alters `financial_accounts.type` to `VARCHAR(50)` |
| `update_schema_type_v2.php` | Legacy follow-up migration |
| `coa_create_probe.php` | Development connectivity probe — **do not expose publicly** |

Schema is normally applied automatically via `config/database.php` on each request.

---

## Troubleshooting

### Dashboard shows zero accounts but data exists in another database

- Cause: wrong PDO selected on bootstrap.
- Fix: verify `companies.db_name` for the tenant points to the DB that contains `financial_accounts`; check `error_log` for `SUCCESS: Switched to tenant DB`.
- Run `coa_create_probe.php` locally (protected) to compare connection candidates.

### `current_balance` out of sync with transactions

- Run `recalculateBalance($id)` for the account or trigger via edit save on `accounts.php`.
- Inspect orphan transactions or manual SQL edits bypassing the module.

### COA code not auto-incrementing

- Confirm account type is **Active** in `account_type_create.php`.
- Ensure new accounts use naming pattern `{code} - {name}` so the scanner finds codes.
- Check browser network tab for `?action=next_account_code` JSON errors.

### 500 on COA create

- Read PHP `error_log`; `coa_create.php` registers an exception handler and logs bootstrap/header failures.
- Confirm `functions.php` is loaded via `config/database.php`.

### AI insights always offline

- Configure OpenAI in system settings (`balances_ai_is_connected()`).
- Check company AI daily limit in `api/ai_insights.php` response.

### Sidebar shows wrong module

- Add `?module=balances` to the URL.
- Path under `/modules/balances/` auto-sets `$_SESSION['active_module'] = 'balances'`.

---

## Deployment checklist

1. Deploy entire `modules/balances/` directory and dependent `accounting/*.php` pages.
2. Ensure `includes/config.php` tenant switching works for each `company_slug`.
3. Confirm MySQL user can `CREATE TABLE` (first visit runs DDL) or pre-create tables from [Database schema](#database-schema).
4. Seed is automatic; verify `financial_account_types` and `financial_account_categories` after first load.
5. Restrict `coa_create_probe.php` and migration scripts via web server or remove from production.
6. Test URLs:
   - Dashboard: `.../modules/balances/index.php?module=balances`
   - COA create: `.../modules/balances/coa_create.php?module=balances`
   - Transfer between two active accounts
   - Transaction list and detail drill-down

---

## Version and maintenance

- **Maintainers:** Finance/ERP team  
- **Primary config:** `modules/balances/config/database.php`, `modules/balances/functions.php`  
- **Sidebar:** `sidebar.php` ? `case 'balances'`  
- When adding a new page under this module, follow existing patterns: `require config/database.php`, `requireLogin()`, include `includes/header.php` / `footer.php`, preserve `module=balances` in links.

For questions about a single page, see inline comments in that PHP file or the legacy note at the bottom of the old transactions-only README (now merged above under **Transaction ledger**).
