# UltiTech Accounting ? Full Module Guide

Guide to the **Accounting** area in UltiTech ERP: the hub, cash book, double-entry ledger, financial statements, settings, and how operational modules post money.

**Audience:** finance users and developers (Ultimate / Roadmaster and other tenants).  
**Local base:** `http://localhost/public_html/{company_slug}/` (example: `ultimate`)  
**Live base:** `https://ultitech.io/{company_slug}/`

**Related docs**

| Doc | Topics |
|-----|--------|
| [`accounting/SETTINGS-README.md`](accounting/SETTINGS-README.md) | GL mapping, periods, tax, journals |
| [`BALANCES-README.md`](BALANCES-README.md) | Cash book ops guide |
| [`modules/balances/README.md`](modules/balances/README.md) | Balances technical API |
| [`accounting/README.md`](accounting/README.md) | File map under `accounting/` + GL engine notes |
| [`.cursor/rules/pv-purpose-module-routing.mdc`](.cursor/rules/pv-purpose-module-routing.mdc) | General vs stock-purchase vouchers |
| [`.cursor/rules/local-first.mdc`](.cursor/rules/local-first.mdc) | Fix on localhost before live |

---

## 1. How accounting is structured

Accounting sits on **three layers**:

| Layer | What it is | Main code / tables |
|-------|------------|--------------------|
| **Cash book (sub-ledger)** | Bank / cash / mobile balances and movements | `modules/balances/` ? `financial_accounts`, `account_transactions` |
| **General ledger (GL)** | Append-only double-entry journals and formal reports | `includes/gl/` + `accounting/` ? `gl_journal_entries`, `gl_journal_lines`, `erp_accounts` |
| **Operational desks** | Day-to-day capture (expenses, purchases, revenue, payroll, sales) | `modules/expenses`, `modules/purchases`, revenue, payroll, sales |

```text
  Sales / Expenses / Purchases / Payroll / Revenue / Vouchers
                         |
                         v
              +----------------------+
              |  Balances (cash)     |  liquidity, transfers
              +----------------------+
                         |  gl_bridge sync
                         v
              +----------------------+
              |  GL (double-entry)   |  P&L, TB, BS, Cash Flow, Ledger
              +----------------------+
```

**Important:** Balances uses a **cash-book** convention (`credit` = money in, `debit` = money out). The GL uses full **double-entry** (debits = credits on every journal).

**Entry point:** Select module ? **Accounting** (Finance / Admin) ? React hub  
(`accounting.php` + `erp-laravel/app/Domains/Accounting/Nav.php`).

Access: **Admin** or **Finance** only.

---

## 2. Getting started

1. Sign in as Finance or Admin for the company (e.g. Ultimate).
2. Open **Select module** ? **Accounting**.
3. Use the hub cards, or open a report URL with `?module=accounting`.

```text
http://localhost/public_html/ultimate/accounting?module=accounting
```

Before relying on formal statements:

1. Open **Accounting Settings** and confirm GL mappings (Sales Revenue, AR, Tax, Inventory, etc.).
2. Open **Reconciliation** (GL) once and apply the ledger sync / enable autosync if prompted.
3. Check **Trial Balance** and **Balance Sheet** still balance.

---

## 3. Accounting hub modules

Cards from `erp-laravel/app/Domains/Accounting/Nav.php`:

| Module | Path | Purpose |
|--------|------|---------|
| **Balances** | `modules/balances/index` | Liquidity dashboard, accounts, transfers, transaction ledger |
| **Revenue** | `revenue_entries` | Income entries, credit notes, collections |
| **Expenses** | `modules/expenses/index` | General payment vouchers and expense receipts |
| **Purchases** | `modules/purchases/index` | Stock-purchase payment vouchers |
| **Journal Entries** | `accounting/journal-entries` | Create / review journals |
| **VAT Control** | `accounting/vat-control` | Output / input VAT by period |
| **Accounting Settings** | `accounting/settings` | GL posting defaults, periods, tax, journals |
| **Financial Statements** | (children below) | Trial Balance, P&L, Balance Sheet, Cash Flow, General Ledger |
| **Reconciliation** | `accounting/reconciliation` | Bank statement vs system (and GL sync tools) |

Financial statement children:

| Report | Path |
|--------|------|
| Trial Balance | `accounting/trial-balance` |
| Profit & Loss | `accounting/profit-loss` |
| Balance Sheet | `accounting/balance-sheet` |
| Cash Flow | `accounting/cash-flow` |
| General Ledger | `accounting/ledger` |

Also under `accounting/` (supporting screens): voucher category map, exchange rates, chart of accounts, FA?GL sync, posting approvals, VAT control UI.

---

## 4. Critical rule: payment voucher purpose ? desk

| PV purpose (form / DB) | Accounting module | Notes |
|------------------------|-------------------|--------|
| **`general`** (General Payment) | **Expenses** | Receipts + general PVs |
| **`stock_purchase`** (Stock Purchases) | **Purchases** | Often linked to POs |

- Do **not** show stock-purchase PVs on the Expenses desk.
- Do **not** show general-payment PVs (or expense receipts) on the Purchases desk.

Helpers: `normalizePaymentVoucherPurpose()`, `buildPaymentVoucherStockPurchasePurposeWhereSql()`.

---

## 5. Double-entry ledger (GL)

### 5.1 Engine location

| Piece | Path |
|-------|------|
| Schema, posting, system accounts | `includes/gl/gl_core.php` |
| Sync from operations ? ledger | `includes/gl/gl_bridge.php` |
| Trial balance / P&L / BS / cash flow | `includes/gl/gl_reports.php` |
| Year-end close | `includes/gl/gl_year_end.php` |
| FX helpers | `includes/gl/gl_fx_auto.php` |
| Report pages (ledger mode) | `accounting/gl-views/*.php` |
| Report entry routers | `accounting/{trial-balance,profit-loss,balance-sheet,cash-flow,ledger}.php` |

When `gl_report_source($pdo) === 'ledger'`, those routers load `accounting/gl-views/?`. Pass `?legacy=1` only if you must force the old `erp_journal_*` views.

### 5.2 Core tables

| Table | Role |
|-------|------|
| `erp_accounts` | Chart of accounts (`code`, `name`, `type`, `system_key`) |
| `gl_journal_entries` | Header: date, description, `source_type`, unique `source_key`, `entry_kind` |
| `gl_journal_lines` | Debit/credit lines (base currency); optional FX columns |
| `gl_source_links` | Idempotent link from a source row ? entry (`fingerprint`, `active`) |
| `gl_period_locks` | Books closed through date |
| `gl_category_map` | Payment-voucher budget category ? expense GL account |
| `gl_exchange_rates` | Foreign currency rates vs base |

Posting API: `gl_post_entry($pdo, $header, $lines)` ? refuses unbalanced entries; supports reversals.

### 5.3 System accounts (created when needed)

Resolved by `gl_system_account($pdo, $key)` using settings ? `system_key` ? matching code+name ? create.

| Key | Default code | Name |
|-----|--------------|------|
| `accounts_receivable` | 1200 | Accounts Receivable |
| `short_term_investments` | 1150 | Short-Term Investments |
| `inventory` | 1300 | Inventory / Stock Purchases |
| `prepaid_expenses` | 1400 | Prepaid Expenses |
| `fixed_assets` | 1500 | Fixed Assets |
| `accumulated_depreciation` | 1501 | Accumulated Depreciation |
| `deferred_tax_asset` | 1600 | Deferred Income Tax Asset |
| `transfer_clearing` | 1950 | Inter-Account Transfers Clearing |
| `suspense` | 1999 | Suspense |
| `accounts_payable` | **2000** | Accounts Payable |
| `long_term_loans` | 2100 | Loans Payable |
| `vat_payable` | 2200 | Tax Payable |
| `accrued_salaries` | 2300 | Accrued Salaries and Wages |
| `unearned_revenue` | 2400 | Unearned Revenue |
| `deferred_tax_liability` | 2600 | Deferred Income Tax Liability |
| `owners_capital` | 3100 | Owner's Capital |
| `retained_earnings` | 3200 | Retained Earnings |
| `opening_balance_equity` | 3900 | Opening Balance Equity |
| `sales_revenue` | 4002 | Sales Revenue |
| `fx_gain_loss` | 5990 | Foreign Exchange Gain/Loss |

Note: **Accounts Payable is 2000**. Code **2100** is reserved for **Loans Payable** on many tenants; the resolver will not treat Loans Payable as AP.

### 5.4 Bridge sync (`gl_bridge_sync_all`)

Idempotent sync steps (order matters):

1. **openings** ? opening balances of financial accounts  
2. **payable** ? accrue approved **unpaid** payment vouchers to AP  
3. **transactions** ? post `account_transactions` (bank movements)  
4. **reclass** ? re-map voucher expenses when category mapping changes  
5. **revenue** ? invoice / AR postings  
6. **unbanked** ? historical paid vouchers / receipts before bank records (cutoff meta)

Autosync runs after requests when GL meta `autosync = 1` (usually enabled from Reconciliation after first backfill).

**Accounts Payable accrual**

- Approved + unpaid + no bank movement ? `Dr` expense/inventory, `Cr` Accounts Payable  
- Later bank payment ? clears AP (`Dr` AP, `Cr` bank) instead of expensing again  
- Source table: `payment_vouchers_payable` in `gl_source_links`  
- Balance Sheet runs a payable-only sync when opened so unpaid vouchers stay current  

---

## 6. Financial statements

Report UIs live under `accounting/gl-views/`. Shared chrome: `includes/gl/gl_page_style.php`, helpers in `gl_view_helpers.php`.

### 6.1 Trial Balance

- Period from / to, account search  
- Opening, period debit/credit, closing debit/credit  
- Export CSV / PDF  

### 6.2 Profit & Loss

- Period presets (this month, quarter, year, ?) and comparison modes  
- Sections: Income, Cost of Sales, Operating Expenses, Income Tax  
- Tax estimate when no income-tax expense is posted  
- Export CSV / PDF  

### 6.3 Balance Sheet

Comparative statement layout (company name + **BALANCE SHEET** header, year columns):

- **As of** date + **Years** (1?5 comparative columns; same month/day in prior years)  
- Sections always shown:  
  - Assets ? Current / Fixed / Other  
  - Liabilities & equity ? Current / Long-term / Owner's Equity  
- Light-blue subtotals, stronger blue grand totals; negatives in red parentheses  
- Zero-balance template accounts still listed (Prepaid, Fixed Assets, AP, Loans, Capital, Retained Earnings, ?)  
- **Retained Earnings (current earnings)** = profit not yet closed by year-end  
- Export CSV / PDF / Print  
- Balanced pill when Assets = Liabilities + Equity  

Classification: `gl_bs_classify()` in `gl_reports.php` (by account type, name, and code ranges).

### 6.4 Cash Flow

Direct-method view of movements on money financial accounts (bank / cash / mobile), grouped by cause (customers, expenses, stock, transfers, openings).

### 6.5 General Ledger

Per-account statement with opening, lines, and closing for a date range.

---

## 7. Module catalogue (operational)

### 7.1 Balances

Operational cash book: liquidity dashboard, accounts, transfers, transaction ledger.  
**Docs:** `BALANCES-README.md`, `modules/balances/README.md`

### 7.2 Expenses

**General Payment** vouchers and expense receipts. Posts to Balances when paid. Does not list stock-purchase PVs.  
**Docs:** `modules/expenses/README.md`

### 7.3 Purchases

Stock-purchase payment vouchers only (`purpose = stock_purchase`). Reuses the expenses desk UI in purchases mode.

### 7.4 Revenue

`revenue_entries` desk: income, credit notes, collections. Sales invoices sync into revenue / AR on post and payment.

### 7.5 Sales (AR)

Invoice post ? revenue + AR (+ VAT). Customer payment ? Balances credit + AR clear in GL.

### 7.6 Payment vouchers

Employee / Admin voucher workflow (`?module=voucher`). Purpose routes to Expenses or Purchases. Approved unpaid vouchers appear on the Balance Sheet as AP.

### 7.7 Payroll

Salary runs; on pay, `postPayrollToLedger()` posts to the GL.  
**Docs:** `modules/payroll/README.md`

### 7.8 Cash Book

Modern cash books (`modules/petty-cash/`, module id `petty_cash`).  
**Docs:** `modules/petty-cash/README.md`

### 7.9 VAT Control

Output / input VAT by period, drill-down, month close.  
**Docs:** `accounting/vat-control-ui/README.md` (if present)

### 7.10 Stock purchase payments (SPPD)

`modules/finance/stock-purchase-payment-desk.php` ? pay POs ? Balances (+ optional GL).  
**Docs:** `modules/finance/README.md`, `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`

---

## 8. Accounting Settings

URL: `/{company}/accounting/settings?module=accounting`  
Access: Admin / Finance.

Full detail: [`accounting/SETTINGS-README.md`](accounting/SETTINGS-README.md).

Tabs (summary):

| Tab | Content |
|-----|---------|
| **GL Mapping** | Sales, Stock, Receipts, Payables, System account pickers |
| **Periods & tax** | Books closed through, fiscal year start, year-end close, base currency, FX, VAT |
| **Journals** | Journal profiles (General, Sales, Purchase, ?) |
| **Manual** | Plain-language year-end guide |

Year-end close posts `entry_kind = closing` into Retained Earnings; P&L ignores closing entries when measuring a period.

---

## 9. Data model cheat sheet

| Area | Tables |
|------|--------|
| Cash book | `financial_accounts`, `account_transactions` |
| Chart of accounts | `erp_accounts` (`system_key` for stable roles) |
| Double-entry GL | `gl_journal_entries`, `gl_journal_lines`, `gl_source_links` |
| Period lock / FX / maps | `gl_period_locks`, `gl_exchange_rates`, `gl_category_map` |
| Payment vouchers | `payment_vouchers`, `voucher_items` (+ `purpose`) |
| Sales | `invoices`, `sales_orders`, `sales_payments` |
| Stock PO pay | `stocks_purchase_orders`, `supplier_payments` |
| Settings | `company_settings` keys `accounting.*` |

Optional cash ? GL link: `financial_accounts.gl_account_id`, tools under `accounting/fa-gl-sync.php`.

---

## 10. Local URL examples (Ultimate)

```text
/ultimate/select-module
/ultimate/accounting?module=accounting
/ultimate/modules/balances/index?module=balances
/ultimate/modules/expenses/index?module=expenses
/ultimate/modules/purchases/index?module=purchases
/ultimate/revenue_entries?module=revenue
/ultimate/accounting/settings?module=accounting
/ultimate/accounting/journal-entries?module=accounting
/ultimate/accounting/trial-balance?module=accounting
/ultimate/accounting/profit-loss?module=accounting
/ultimate/accounting/balance-sheet?module=accounting
/ultimate/accounting/cash-flow?module=accounting
/ultimate/accounting/ledger?module=accounting
/ultimate/accounting/vat-control?module=accounting
/ultimate/accounting/gl-reconciliation?module=accounting
/ultimate/modules/finance/stock-purchase-payment-desk.php?module=balances
```

Use `company_url('?')` in PHP so tenant prefixes stay correct.

---

## 11. Troubleshooting

| Symptom | Check |
|---------|--------|
| Reports empty / legacy layout | Is ledger mode on? `gl_report_source` / Reconciliation sync. Avoid `?legacy=1` unless intentional. |
| Balance Sheet out of balance | Unclassified suspense, incomplete sync, or mixed legacy journals. Run Reconciliation sync; check Suspense 1999. |
| AP missing on Balance Sheet | Need approved **unpaid** vouchers; open Balance Sheet (triggers payable sync) or run full GL sync. |
| Invoice missing from P&L | Invoice must be posted; check invoice date vs P&L period. |
| Payment does not hit bank | Balances account required; transaction in `account_transactions` with `reference_type = payment_voucher`. |
| Wrong expense account on PV | Map budget category under **Voucher Categories** (`gl-category-map.php`). |
| FX error on sync | Add rate under Exchange Rates / Settings for that currency and date. |
| Cannot post dated entry | Books may be locked through that date (Periods & tax). |

---

## 12. Developer notes

- Hub / many desks load through **`erp-laravel/bootstrap/erp-bridge.php`**.  
- React desks: build with `npm run build` in the relevant `frontend` / `*-ui` folder after UI changes.  
- GL schema is created/updated by `gl_ensure_schema()` ? no separate migration runner for these tables.  
- Prefer **local** (`localhost` / XAMPP) for fixes; deploy to live only when asked ([`local-first`](.cursor/rules/local-first.mdc)).  
- Do not invent GL postings on the live server during debugging; use local DB copies.

### Key source files

```text
accounting.php                          Hub entry
erp-laravel/app/Domains/Accounting/     Hub Nav + domain
accounting/*.php                        Screens & report routers
accounting/gl-views/*.php               Ledger-mode report UIs
includes/gl/gl_core.php                 Posting + system accounts
includes/gl/gl_bridge.php               Ops ? ledger sync
includes/gl/gl_reports.php              TB / P&L / BS / CF math
includes/accounting_settings.php        GL role mappings
includes/accounting_journals.php        Journal profiles
modules/balances/                       Cash book
modules/expenses/                       General PVs
modules/purchases/                      Stock-purchase PVs
```
