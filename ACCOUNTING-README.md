# UltiTech Accounting — Modules Guide

Guide to the **Accounting** area and the modules that post money, invoices, and journals in UltiTech ERP.

**Audience:** finance users and developers working on Ultimate / Roadmaster tenants.  
**Local base:** `http://localhost/public_html/{company_slug}/…` (example: `ultimate`)  
**Live base:** `https://ultitech.io/{company_slug}/…`

---

## 1. How accounting is structured

Accounting sits on **three layers**:

| Layer | What it is | Main code / tables |
|-------|------------|--------------------|
| **Cash book (sub-ledger)** | Bank / cash / mobile money balances and movements | `modules/balances/` ? `financial_accounts`, `account_transactions` |
| **General ledger (GL)** | Double-entry journals and formal reports | `accounting/` ? `erp_accounts`, `erp_journal_entries`, `erp_journal_items` |
| **Operational desks** | Day-to-day capture (expenses, purchases, revenue, payroll, sales payments) | `modules/expenses`, `modules/purchases`, `revenue_entries.php`, `modules/payroll`, `modules/sales`, … |

```text
  Sales / Expenses / Purchases / Payroll / SPPD
                    ?
                    ?
         ???????????????????????
         ?  Balances (cash)    ?  ? liquidity, transfers
         ???????????????????????
                    ?  optional sync
                    ?
         ???????????????????????
         ?  GL Journals        ?  ? P&L, TB, BS, VAT
         ???????????????????????
```

**Entry point:** Module launcher ? **Accounting** (Finance / Admin) ? React hub (`accounting.php` + `erp-laravel/app/Domains/Accounting/Nav.php`).

---

## 2. Getting started

1. Sign in as Finance or Admin for the company (e.g. Ultimate).
2. Open **Select module** ? **Accounting**.
3. Use the hub cards (Balances, Revenue, Expenses, …) or the sidebar with `?module=…`.

Example hub URL (local):

```text
http://localhost/public_html/ultimate/accounting?module=accounting
```

---

## 3. Accounting hub modules

Cards defined in `erp-laravel/app/Domains/Accounting/Nav.php`:

| Module | Path | Purpose |
|--------|------|---------|
| **Balances** | `modules/balances/index` | Liquidity dashboard, accounts, transfers, transaction ledger |
| **Revenue** | `revenue_entries` | Income entries, credit notes, collections |
| **Expenses** | `modules/expenses/index` | General payment vouchers & expense receipts |
| **Purchases** | `modules/purchases/index` | Stock-purchase payment vouchers (procurement) |
| **Journal Entries** | `accounting/journal-entries` | Create / review GL journals |
| **VAT Control** | `accounting/vat-control` | Output / input VAT by period |
| **Accounting Settings** | `accounting/settings` | Default GL accounts (e.g. sales revenue) |
| **Journal Configuration** | `accounting/journal-configuration` | Posting rules |
| **Trial Balance** | `accounting/trial-balance` | Period trial balance |
| **Reconciliation** | `accounting/reconciliation` | Bank statement vs system |

Also under `accounting/` (not always on the hub cards): **Profit & Loss**, **Balance Sheet**, **Cash Flow**, **Chart of Accounts**, **Ledger**, **Posting Approvals**.

---

## 4. Module catalogue (detail)

### 4.1 Balances (`modules/balances/`)

Operational **cash book**.

- Liquidity dashboard, transfers between accounts, transaction history  
- Core write API: `recordTransaction()` / `balancesRecordTransaction()`  
- Fed by: sales payments, revenue collections, expense posts, paid vouchers, stock supplier payments  

**Docs:** `modules/balances/README.md`

### 4.2 Expenses (`modules/expenses/`)

**General Payment** vouchers and expense receipts (React desk).

- List / create / edit / import / smart insights  
- Posts into Balances when paid  
- **Does not** show stock-purchase PVs  

**Docs:** `modules/expenses/README.md`

### 4.3 Purchases (`modules/purchases/`)

Thin desk that **reuses the Expenses UI** with purchases mode:

- `__EXPENSES_DESK_MODE__ = purchases` / `desk=purchases`  
- Only **Stock Purchases** (`stock_purchase`) payment vouchers  
- Create link typically: `employee/create-voucher.php?…&purpose=stock_purchase`  

### 4.4 Finance & stock PO payments (`modules/finance/`)

| Feature | Path | Notes |
|---------|------|--------|
| **Stock Purchase Payment Desk (SPPD)** | `modules/finance/stock-purchase-payment-desk.php` | Pay purchase orders ? `supplier_payments`, Balances, optional GL |
| Budgets | `modules/finance/budgets/` | Plans vs actuals (Finance / Admin) |

**Docs:** `modules/finance/README.md`, `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`

### 4.5 Revenue

- Main desk: `revenue_entries.php` (Laravel bridge)  
- Supporting: customers, payments, credit notes, import  
- Sales invoices sync into the revenue ledger on post / payment  

### 4.6 Sales (AR touchpoints)

Not “Accounting-only”, but drives receivables:

- Invoice post ? revenue sync (and target auto JE)  
- Record payment ? Balances credit + revenue sync (`modules/sales/payments/`)  

**Docs:** `modules/sales/payments/README.md`, `accounting/README.md`

### 4.7 Payment Vouchers (approval)

- Employee / Admin voucher workflow (`?module=voucher`)  
- **Purpose** routes which accounting desk owns the voucher (see §5)  

### 4.8 Payroll (`modules/payroll/`)

- Salary runs, payslips  
- On pay: `postPayrollToLedger()` ? GL  

**Docs:** `modules/payroll/README.md`

### 4.9 Cash Book (`modules/petty-cash/`)

- Modern cash books (module id still `petty_cash`)  
- Bridge: `cashbook.php` ? Laravel CashBook domain  

**Docs:** `modules/petty-cash/README.md`

### 4.10 Analytics (`modules/analytics/`)

- Finance KPIs / charts; smarter P&L-style views vs basic `accounting/profit-loss.php`  

**Docs:** `modules/analytics/README.md`

### 4.11 Stock (costing adjacency)

- POs, receiving, weighted-average cost live under `stock/`  
- Paid via SPPD + stock-purchase PVs — not the Expenses desk  

---

## 5. Critical rule: payment voucher purpose ? desk

| PV purpose (form / DB) | Accounting module | Notes |
|------------------------|-------------------|--------|
| **`general`** (General Payment) | **Expenses** (`modules/expenses`) | Receipts + general PVs |
| **`stock_purchase`** (Stock Purchases) | **Purchases** (`modules/purchases`) | Often linked to POs |

Helpers: `normalizePaymentVoucherPurpose()`, `buildPaymentVoucherStockPurchasePurposeWhereSql()` in `includes/functions.php`.

- Do **not** show stock-purchase PVs on Expenses.  
- Do **not** show general-payment PVs (or expense receipts) on Purchases.  

Cursor rule: `.cursor/rules/pv-purpose-module-routing.mdc`

---

## 6. Stock purchase money flow (three documents)

1. **Vendor / supplier bill** — increases payable (no cash yet).  
2. **Payment voucher** (`purpose=stock_purchase`) — internal approval only while pending.  
3. **Supplier payment** (SPPD) — cash leaves Balances; optional GL journal.

Full design: `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`

---

## 7. Reports map

| Need | Open |
|------|------|
| Cash on hand / bank | **Balances** |
| Period TB | **Accounting ? Trial Balance** |
| Accrual P&L / BS / cash flow | `accounting/profit-loss.php`, `balance-sheet.php`, `cash-flow.php` |
| VAT in / out | **VAT Control** |
| Bank match | **Reconciliation** |
| Management KPIs | **Analytics** |

Revenue is recognised on **invoice post** (accrual), not only on cash receipt (`accounting/README.md`).

---

## 8. Data model cheat sheet

| Area | Tables (typical) |
|------|------------------|
| Cash book | `financial_accounts`, `account_transactions` |
| Expenses | `erp_expenses` (and related), payees |
| Payment vouchers | `payment_vouchers` (+ purpose) |
| Revenue | `revenue_entries`, credit notes |
| Stock PO pay | `stocks_purchase_orders`, `supplier_payments` |
| GL | `erp_accounts`, `erp_journal_entries`, `erp_journal_items` |

Optional link cash ? GL: `financial_accounts.gl_account_id`, `accounting/fa-gl-sync.php`.

---

## 9. Local URL examples (Ultimate)

```text
…/ultimate/select-module
…/ultimate/accounting?module=accounting
…/ultimate/modules/balances/index?module=balances
…/ultimate/modules/expenses/index?module=expenses
…/ultimate/modules/purchases/index?module=purchases
…/ultimate/revenue_entries?module=revenue
…/ultimate/accounting/journal-entries?module=accounting
…/ultimate/accounting/vat-control?module=accounting
…/ultimate/modules/finance/stock-purchase-payment-desk.php?module=balances
…/ultimate/modules/petty-cash/index?module=petty_cash
```

Use `company_url('…')` in PHP so tenant prefixes stay correct.

---

## 10. Further reading

| Doc | Topics |
|-----|--------|
| `README.md` | ERP overview, tenants, local paths |
| `README_DEVELOPER.md` | GL schema, `accounting/` map |
| `accounting/README.md` | Journals, P&L, sales auto-JE concepts |
| `modules/balances/README.md` | Cash book integrations |
| `modules/expenses/README.md` | Expenses desk & API |
| `modules/finance/README.md` | SPPD ? Balances ? GL |
| `modules/payroll/README.md` | Payroll ? ledger |
| `modules/petty-cash/README.md` | Cash Book |
| `accounting/vat-control-ui/README.md` | VAT sources & close |
| `erp-laravel/README.md` | Domain bridge / migration |
| `.cursor/rules/local-first.mdc` | Fix on localhost before live deploy |

---

## 11. Dev notes

- Many desks are **React** builds under `modules/*/frontend` or `*-ui/`; run `npm run build` in the relevant folder after UI changes.  
- Hub / revenue / cash book / payroll often load through **`erp-laravel/bootstrap/erp-bridge.php`**.  
- Git deploy does **not** run DB migrations — apply schema changes manually when needed.  
- Prefer **local** (`localhost` / XAMPP) for fixes; deploy to live only when asked.
