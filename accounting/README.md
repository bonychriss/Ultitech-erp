# Accounting folder — technical map

Canonical product guide: **[`../ACCOUNTING-README.md`](../ACCOUNTING-README.md)**  
Settings detail: **[`SETTINGS-README.md`](SETTINGS-README.md)**

This folder holds Accounting screens (settings, journals, VAT, statement routers) and ledger-mode report UIs. The double-entry **engine** lives under `includes/gl/`.

---

## Layout

```text
accounting/
  settings.php                 Accounting Settings (tabs)
  journal-entries.php          Manual / listed journals
  journal-configuration.php    Journal profiles
  vat-control.php              VAT control desk
  reconciliation.php           Bank reconciliation entry
  gl-reconciliation.php        GL sync / autosync tools
  trial-balance.php            Router → gl-views or legacy
  profit-loss.php
  balance-sheet.php
  cash-flow.php
  ledger.php
  chart-of-accounts.php
  gl-category-map.php          PV budget category → expense GL
  exchange-rates.php
  fa-gl-sync.php               Financial account ↔ GL link tools
  posting-approvals.php
  settings-ui/                 React build for settings
  vat-control-ui/              React build for VAT
  gl-views/                    Ledger-mode statement pages
    trial-balance.php
    profit-loss.php
    balance-sheet.php          Comparative multi-year BS
    cash-flow.php
    ledger.php
    export-csv.php
    export-pdf.php
```

Report routers choose **ledger** vs **legacy** via `gl_report_source($pdo)`. Use `?legacy=1` only to force old `erp_journal_*` pages.

---

## GL engine (`includes/gl/`)

| File | Role |
|------|------|
| `gl_core.php` | Schema ensure, `gl_post_entry`, system accounts, period locks |
| `gl_bridge.php` | `gl_bridge_sync_all` — openings, AP accrual, bank tx, revenue, … |
| `gl_reports.php` | Trial balance, P&L, Balance Sheet, cash flow builders |
| `gl_year_end.php` | Close books → Retained Earnings |
| `gl_fx_auto.php` | FX rate helpers |
| `gl_page_style.php` / `gl_view_helpers.php` | Shared report chrome |

Primary tables: `gl_journal_entries`, `gl_journal_lines`, `gl_source_links`, `erp_accounts`.

---

## Balance Sheet (ledger mode)

- Multi-year comparative columns (`years=1..5`)
- Always shows ASSETS and LIABILITIES & EQUITY section shells
- Placeholder zero lines for template accounts (prepaid, fixed assets, AP, loans, capital, RE, …)
- On open: `gl_bridge_sync_accounts_payable()` so unpaid approved vouchers accrue to AP (code **2000**)

---

## Access

Admin or Finance. Prefer `company_url('accounting/…')` for tenant-safe links.

---

## Further reading

- [`../ACCOUNTING-README.md`](../ACCOUNTING-README.md) — full module guide  
- [`SETTINGS-README.md`](SETTINGS-README.md) — mappings, periods, year-end  
- [`../BALANCES-README.md`](../BALANCES-README.md) — cash book  
- [`../README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`](../README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md) — PO payment flow  
