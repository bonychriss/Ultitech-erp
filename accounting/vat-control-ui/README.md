# VAT Control

VAT Control is the Accounting module for reviewing **output VAT** and **input VAT**, drilling into monthly activity, and reconciling or closing VAT periods per company.

## Access

- **URL:** `accounting/vat-control.php?module=accounting`  
  (tenant path example: `/ultimate/accounting/vat-control?module=accounting`)
- **Roles:** Admin or Finance only
- **Nav:** Accounting hub ? **VAT Control**

## What it tracks

| Category (UI) | Source data | Amount used |
|---|---|---|
| **Revenue** (output) | `revenue_entries` | `vat_amount` by `entry_date` |
| **Purchases** (input) | `stocks_purchase_orders` | `tax_amount` by purchase/order date |
| **Expenses** (input) | `erp_expenses` | `tax_amount` by `date` (posted rows only when `is_posted` exists) |

**Net VAT** for a month:

```text
net = output ? (purchases + expenses)
```

**Position:**

- `payable` � net &gt; 0 (VAT owed)
- `credit` � net &lt; 0 (input exceeds output)
- `nil` � net ? 0

Opening / closing balances roll forward from the previous period�s closing balance (`erp_vat_periods`).

## Screens

Primary drill-down:

1. **Dashboard** � YTD / all-time KPIs, category cards, month history  
2. **Year** � months for a calendar year  
3. **Month** � breakdown + reconcile / close actions  
4. **Transactions** � line items for Revenue, Purchases, or Expenses in that month  

Legacy category-first URLs (`vat_view=category&category=�`) still work.

Query params (kept across navigation): `module`, `company_slug`, plus `vat_view`, `year`, `ym`, `category` / `source`.

## Period workflow

Stored in `erp_vat_periods` (auto-created on first API use). Statuses:

| Status | Meaning |
|---|---|
| `open` | Live totals only; not snapshot-saved |
| `reconciled` | Totals + opening/closing saved; can still re-reconcile |
| `closed` | Final for the period; cannot change |

- **Reconcile** � snapshots current live totals and marks the period reconciled  
- **Close** � snapshots totals and marks the period closed (implies reconciled if not already)

## Architecture

```text
accounting/vat-control.php          PHP shell (auth + loads built SPA)
accounting/vat-control-ui/
  src/                              React + TypeScript (Vite)
  dist/                             Built assets (committed / deployed)
  api/index.php                     JSON API
  vat-lib.php                       Bootstrap, access, totals, period CRUD
```

### API (`vat-control-ui/api/index.php`)

| Action | Method | Purpose |
|---|---|---|
| `init` | GET | Dashboard payload |
| `category_years` | GET | Years for a category |
| `year_months` | GET | Months for a year (optional category) |
| `month_detail` | GET | Month totals + period status |
| `transactions` | GET | Lines for `ym` + `source` |
| `reconcile_period` | POST | Reconcile `ym` |
| `close_period` | POST | Close `ym` |

Shell injects `window.__VAT_API_BASE__` and `window.__VAT_COMPANY_SLUG__`.

## Local development

```bash
cd accounting/vat-control-ui
npm install
npm run build          # production assets ? dist/
npm run dev            # Vite on http://0.0.0.0:3004
npm run lint           # tsc --noEmit
```

Open the ERP shell URL above after `npm run build`. If `dist/` is missing, the shell shows a build instruction page.

## Deploy notes

- Ship `accounting/vat-control.php`, `vat-control-ui/` (including `dist/`, `api/`, `vat-lib.php`).
- Shared app code under `modules/`, `includes/`, etc. lives on the site root (`/public_html/�`), not only under the company folder.
- Ensure Accounting nav includes VAT Control (`erp-laravel` Accounting `Nav` entry).

## Related files

- `erp-laravel/app/Domains/Accounting/Nav.php` � hub link  
- `ultimate/.htaccess` � tenant rewrites for accounting routes  

