# UltiTech Balances — Module Guide

Guide to the **Balances** cash book: liquidity, financial accounts, transfers, and the transaction ledger.

**Technical detail:** [`modules/balances/README.md`](modules/balances/README.md)  
**Accounting context:** [`ACCOUNTING-README.md`](ACCOUNTING-README.md)

**Audience:** finance users and developers on Ultimate / Roadmaster tenants.  
**Local base:** `http://localhost/public_html/{company_slug}/…` (example: `ultimate`)  
**Live base:** `https://ultitech.io/{company_slug}/…`

---

## 1. What Balances is

Balances is the operational **cash book** (sub-ledger): bank, cash, and mobile-money wallets and their movements.

| Layer | Role | Tables |
|-------|------|--------|
| **Balances** | Liquidity, transfers, cash history | `financial_accounts`, `account_transactions` |
| **GL / Journals** | Formal double-entry reports | `erp_accounts`, `erp_journal_*` under `accounting/` |

```text
  Sales / Revenue / Expenses / Purchases / SPPD / Vouchers
                         ?
                         ?
              ???????????????????????
              ?  Balances (cash)    ?  ? this module
              ???????????????????????
                         ?  optional gl_account_id link
                         ?
              ???????????????????????
              ?  GL Journals        ?  ? P&L, TB, BS
              ???????????????????????
```

**Cash-book convention:** `credit` = money in (raises balance), `debit` = money out (lowers balance). This is not full double-entry GL.

---

## 2. Getting started

1. Sign in as Finance or Admin for the company.
2. Open **Select module ? Accounting ? Balances**, or go straight to the liquidity URL below.
3. Keep `?module=balances` on links so the sidebar stays on Balances.

Example (local Ultimate):

```text
http://localhost/public_html/ultimate/modules/balances/index?module=balances
```

Hub card is defined in `erp-laravel/app/Domains/Accounting/Nav.php` ? `modules/balances/index?module=balances`.

---

## 3. Pages and sidebar

Sidebar (`sidebar.php` case `balances`):

| Sidebar item | Path | UI |
|--------------|------|-----|
| **Dashboard** | `modules/balances/index.php` | Liquidity dashboard (React) |
| **Account** | `modules/balances/accounts.php` | Chart of accounts / wallets (PHP) |
| **Transactions** | `modules/balances/transactions.php` | Transaction ledger (React) |
| **Internal Transfer** | `modules/balances/transfer.php` | Move cash between accounts (React) |
| **Reconciliation** | `accounting/reconciliation.php?module=balances` | Bank match (Accounting UI, Balances sidebar) |

Also under `modules/balances/` (COA maintenance):

| Page | Path |
|------|------|
| View account | `view_account.php` |
| View transaction | `view-transaction.php` |
| COA create / edit | `coa_create.php`, `coa_edit.php` |
| Categories | `account_categories.php`, `category_*.php` |
| Account types | `account_types.php`, `account_type_create.php` |

Stock purchase payment desk lives under **Finance / Purchases**, not the Balances sidebar.

---

## 4. React desks

Three Vite apps under `modules/balances/`:

| Desk | Folder | Shell PHP | API |
|------|--------|-----------|-----|
| Liquidity dashboard | `liquidity-dashboard-ui/` | `index.php` | `liquidity-dashboard-ui/api/index.php` |
| Transaction ledger | `transaction-ledger-ui/` | `transactions.php` | `transaction-ledger-ui/api/index.php` |
| Internal transfer | `transfer-ui/` | `transfer.php` | `transfer-ui/api/index.php` |

After UI changes:

```bash
cd modules/balances/liquidity-dashboard-ui && npm install && npm run build
cd modules/balances/transaction-ledger-ui && npm install && npm run build
cd modules/balances/transfer-ui && npm install && npm run build
```

Missing `dist/` ? HTTP 503 with a build hint.

---

## 5. Money in / money out

Canonical writers call `recordTransaction()` or `balancesRecordTransaction()` from `modules/balances/functions.php`.

| Source | Typical side | Typical `reference_type` |
|--------|--------------|--------------------------|
| Sales invoice payment | credit | `invoice_payment` |
| Revenue collection | credit | `revenue_collection` / `revenue_entry` |
| Expense / general PV paid | debit | `expense` / `payment_voucher` |
| Stock / supplier payment (SPPD) | debit | purchase/payment refs |
| Internal transfer | debit + credit | `transfer_out` / `transfer_in` |

**Balance formula:**

```text
current_balance = opening_balance + SUM(credits) ? SUM(debits)
```

`recalculateBalance($accountId)` recomputes from `account_transactions`. Prefer that over hand-editing balances.

---

## 6. Internal transfer

Moving cash between two wallets posts a **pair** of ledger rows (same amount):

1. Source ? `debit` + `transfer_out`  
2. Destination ? `credit` + `transfer_in`  

Implemented in `transfer-ui/tf-lib.php` (`tfCreateTransfer`).

---

## 7. Schema essentials

| Table | Role |
|-------|------|
| `financial_accounts` | Wallets / COA rows (`opening_balance`, `current_balance`, `type`, optional `parent_id`, `company_id`, `gl_account_id`) |
| `account_transactions` | Ledger (`type` credit\|debit, `reference_type` / `reference_id`) |
| `financial_account_types` | Type catalog |
| `financial_account_categories` | Reporting categories |

Liquidity buckets (`cash` / `bank` / `mobile`) come from `balancesAccountLiquidityBucket()`. Optional cash?GL link: `financial_accounts.gl_account_id` (`includes/fa_gl_linking.php`).

---

## 8. Access and company scope

- Login + finance/admin-style access (`ldRequireAccess` / `tlRequireAccess` / `tfRequireAccess` / balances guards).
- Tenant PDO via `balances_resolve_pdo()` when company scoping is on.
- Pass `company_id` into `balancesRecordTransaction()` from company-scoped modules.

---

## 9. Local URL examples (Ultimate)

```text
…/ultimate/accounting?module=accounting
…/ultimate/modules/balances/index?module=balances
…/ultimate/modules/balances/accounts.php?module=balances
…/ultimate/modules/balances/transactions.php?module=balances
…/ultimate/modules/balances/transfer.php?module=balances
…/ultimate/accounting/reconciliation.php?module=balances
```

Use `company_url('…')` in PHP so tenant prefixes stay correct.

---

## 10. Troubleshooting

| Symptom | Check |
|---------|--------|
| Liquidity / ledger / transfer blank or 503 | `npm run build` in the matching `*-ui/` folder |
| Payment did not move balance | `recordTransaction` ran? Correct `account_id` / `reference_type`? `recalculateBalance`? |
| Wrong company data | Company slug + `company_id` on inserts |
| Sidebar wrong module | Keep `?module=balances` |
| GL reports ? liquidity | Cash book vs journals; check `gl_account_id` if both are used |

---

## 11. Related docs

| Doc | Topics |
|-----|--------|
| `modules/balances/README.md` | Integrations, API snippets, directory map |
| `ACCOUNTING-README.md` | Hub modules, PV purpose split, reports |
| `modules/expenses/README.md` | Expenses ? Balances |
| `modules/finance/README.md` | SPPD ? Balances ? GL |
| `modules/sales/payments/README.md` | Invoice payments ? Balances |
| `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md` | PO ? PV ? supplier payment |
| `.cursor/rules/local-first.mdc` | Fix on localhost before live deploy |
