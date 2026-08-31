# Ultitech ERP � Full System Status

**Purpose:** Single reference for what the platform does today, what was recently completed, and what is still open � across all major modules, not only Stock Purchases.

**Audience:** Developers, project owners, finance/ops leads.

**Related docs:**

| Document | Scope |
|----------|--------|
| [README.md](README.md) | Databases, login, XAMPP setup, env files |
| [README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md](README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md) | PO ? GRN ? Vendor Bill ? Payment Voucher ? Supplier Payment (design phases) |
| [stock/modules/purchases/README.md](stock/modules/purchases/README.md) | Purchase Orders � detailed accomplished/remaining |
| [modules/expenses/README.md](modules/expenses/README.md) | Expense & Receipt Center |
| [erp/petty-cash/README.md](erp/petty-cash/README.md) | Petty cash float |
| [weekly_tasks/README.md](weekly_tasks/README.md) | Weekly performance tasks |
| [modules/performance/README.md](modules/performance/README.md) | Performance Management (role/module KPIs) |

**Local base URL:** `http://localhost/public_html/`  
**Companies:** Ultimate General Trading (`ultimate`), Roadmaster (`roadmaster`)

---

## 1. Platform overview

| Item | Detail |
|------|--------|
| Stack | PHP 8.x, MySQL/MariaDB, Apache (XAMPP local / StackCP production) |
| Architecture | **Control DB** (companies, global users, `user_company_index`, `company_settings`) + **tenant DBs** per company (vouchers, sales, stock, etc.) |
| Entry | `login.php` ? company module picker `select-module.php` or `/{company_slug}/select-module` |
| Config | `env.local.php` (local) ? `includes/config.php` ? PDO + session + tenant switch |
| Timezone | `Africa/Dar_es_Salaam` |

### Enabled modules (per company)

Configured in **Admin ? Company Settings ? Modules** (`company_modules`). Defaults include:

`payment_voucher`, `sales`, `stock`, `finance`, `accounting`, `payroll`, `attendance`, `revenue`, `logistics`

Each company can enable/disable and relabel modules.

---

## 2. Module status at a glance

| Module | Location | Mature / in use | Recent wins | Main gaps |
|--------|----------|-----------------|-------------|-----------|
| **Login & tenants** | `login.php`, `includes/functions.php` | Yes | `user_company_index` email login | Legacy fallbacks; sync after bulk imports |
| **Admin / companies** | `admin/` | Yes | Company settings, modules, numbering | Harden debug/diagnostic pages for prod |
| **Payment vouchers** | `employee/` | Yes | Create/edit/approve, signatures, bulk upload | Stock�voucher linking UX; posting consistency |
| **Stock & inventory** | `stock/` | Yes | PO view/edit, PDF, supplier enrichment, mobile footer | Supplier table sync; server-side PDF |
| **Purchases (PO)** | `stock/modules/purchases/` | Yes | See �3 below | Vendor bill phase; supplier master sync |
| **Vendor bills** | `stock/modules/purchases/vendor-bills/` | Partial | Create/list UI | Needs Phase 3B migration on some DBs |
| **Sales** | `modules/sales/`, `sales/` | Yes | Orders, invoicing (tenant-specific) | Full alignment with revenue ledger everywhere |
| **Revenue** | `includes/revenue_ledger.php`, revenue UI | Partial | Ledger schema + sync from sales | Complete UI/reporting parity |
| **Expenses** | `modules/expenses/` | Yes | Dashboard, payees, voucher sync | See expenses README |
| **Balances** | `modules/balances/` | Yes | Financial accounts, deductions | Cross-module posting rules documentation |
| **Accounting** | `modules/accounting/`, `accounting/` | Partial | Journal/trial balance areas exist | Full PO?bill?payment GL automation |
| **Petty cash** | `erp/petty-cash/` | Yes | Separate float from balances | � |
| **Payroll / HR** | `modules/payroll/`, `hr/` | Partial | Module shells + company enable | Depth varies by deployment |
| **Attendance** | `attendance/`, employee sign-in | Partial | Sign attendance, analytics hooks | Company-wide reporting |
| **Logistics** | `logistics/`, `deliveries/`, `dispatch/` | Partial | Dispatch/delivery pages | End-to-end with sales/stock |
| **CRM / customers** | `crm/`, `customers/` | Partial | Customer records | Unified with sales module |
| **Weekly tasks** | `weekly_tasks/` | Yes | Auto-weight scoring by department | Schema fix script if tables missing |
| **Meetings** | `meeting/`, `employee/meetings.php` | Yes | Basic meeting flows | � |
| **Stock UI (React)** | `stock/stock-ui/` | Prototype | Vite/React lists (purchases, products, etc.) | Not full replacement for PHP stock UI |
| **Order tracking** | `order-tracking/` | Partial | Tracking pages | Integration with sales/logistics |
| **Banking / outstanding** | `banking/`, `outstanding-invoices/` | Partial | Reporting entry points | � |
| **Todo / tasks (legacy)** | `todo/`, `tasks/` | Legacy | � | Prefer `weekly_tasks` for new work |

---

## 3. Recently accomplished (cross-cutting & Stock PO)

Work completed in recent development cycles (especially **Stock ? Purchases ? View PO**):

### Stock � Purchase Orders

- PO loads with correct **currency** / **exchange rate** on view and edit.
- **Company block** on PO from Admin **Company Settings** (control DB `companies` + `company_settings`).
- **Supplier block**: name, contact person, phone, email, address � merged from `stocks_suppliers`, legacy `suppliers`, parsed `contact_details`, and **payment voucher** payees (multi-PDO lookup).
- **Terms & conditions** on create/edit; shown on printed PO.
- **Download PDF** (html2canvas + jsPDF); **single-page fix** (removed forced min-height, improved pagination).
- **Mobile footer** works under `/ultimate/stock/` paths.
- Shared helpers in `stock/modules/purchases/purchase_workflow.php` (`stockPurchaseLoadPoForView`, `enrichPurchaseOrderSupplierDisplay`, etc.).

Details: [stock/modules/purchases/README.md](stock/modules/purchases/README.md)

### Platform (existing, documented in README.md)

- Three-database local setup (control + Ultimate tenant + Roadmaster tenant).
- Indexed login via `user_company_index`.
- Company slug URLs (`/ultimate/login`, `/ultimate/select-module`).
- Local dev uses `root` MySQL credentials regardless of production `companies.db_user`.

---

## 4. What is accomplished � by area

### 4.1 Authentication & multi-company

- [x] Email-first login with `user_company_index`
- [x] Per-company tenant database connection after login
- [x] Company slug routing (`.htaccess`)
- [x] Session: `company_id`, `company_slug`, role
- [x] Admin sync tool: `admin/sync-user-company-index.php`
- [x] Duplicate-email prevention on user create

### 4.2 Admin & company setup

- [x] Company profile, branding, logo
- [x] Finance defaults (currency, payment terms)
- [x] Module enable/disable per company
- [x] Document numbering sequences
- [x] Employee invite / registration modes
- [x] Company settings used on PO print (via `resolveStockPurchaseCompanyProfile()`)

### 4.3 Payment vouchers (employee module)

- [x] Create, edit, view, delete vouchers
- [x] Approval workflow, signatures (`employee/view-voucher.php`, etc.)
- [x] Bulk upload template
- [x] Link fields for stock PO (`linked_stock_po_id`, payee) � used by purchase enrichment
- [x] Mark paid / posted flows

### 4.4 Stock & procurement

- [x] Products, categories, catalogue, stock levels
- [x] Domestic PO create (`domestic_create.php`)
- [x] PO list (desktop + mobile list includes)
- [x] PO view, edit, approve, cancel, receive flows
- [x] Supplier master (add/edit) � `stock/modules/suppliers/`
- [x] Shipments, shippers, statements, reports
- [x] Import vs domestic purchase types
- [x] Optional supplier-link procurement workflow (`purchase_workflow.php`)
- [x] Vendor bills UI (where migration applied)
- [x] Ultimate URL aliases: `ultimate/stock/...` ? `stock/...`

### 4.5 Sales & revenue

- [x] Sales orders / invoicing (tenant tables: `sales_orders`, `invoices`, etc.)
- [x] Revenue ledger table + sync helpers (`includes/revenue_ledger.php`, `revenue_sync.php`)
- [ ] Full revenue module UI/reporting on all tenants (varies)

### 4.6 Finance operations

- [x] Expenses module (direct receipts + voucher-sourced expenses) � [modules/expenses/README.md](modules/expenses/README.md)
- [x] Balances / financial accounts
- [x] Petty cash (separate from balances) � [erp/petty-cash/README.md](erp/petty-cash/README.md)

### 4.7 People & operations

- [x] Weekly task planning & weighted scoring � [weekly_tasks/README.md](weekly_tasks/README.md)
- [x] Employee dashboard, account settings, meetings, overtime pages
- [x] Attendance sign-in flows

### 4.8 Integrations & aliases

- [x] `app_url()`, `company_url()`, stock base path resolution
- [x] Mailer include for PO email
- [x] WhatsApp share hooks on PO view

---

## 5. What is remaining � prioritized

### 5.1 Critical / high (business correctness)

| # | Item | Notes |
|---|------|--------|
| 1 | **Supplier master sync** | `suppliers` vs `stocks_suppliers` � PO uses latter; Add Supplier UI may use former. Unify or sync on save. |
| 2 | **Stock purchase ? payment workflow** | Design in [README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md](README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md): Phases 2�8 (schema gap, vendor bill posting, supplier payment, GL). |
| 3 | **Vendor bills migration** | `vendor_bills` tables not on all DBs; UI shows migration message until Phase 3B applied. |
| 4 | **Accounting automation** | Auto journal entries from vendor bills and supplier payments (policy per company). |
| 5 | **Revenue ledger completeness** | Ensure all sales/payment paths sync to `revenue_ledger` with reconciliation reports. |

### 5.2 Medium (quality & ops)

| # | Item | Notes |
|---|------|--------|
| 6 | **Server-side PO PDF** | Replace or supplement browser html2canvas for email attachments. |
| 7 | **Remove debug artifacts** | e.g. `view_po_debug.log`, `debug_db_connections.php`, `employee/debug_approve.log` in production. |
| 8 | **Automated tests** | No broad PHPUnit coverage; add smoke tests for login, PO load, voucher approve. |
| 9 | **Public supplier PO link** | Align `loadStockPoByPublicToken()` with supplier enrichment. |
| 10 | **React stock-ui** | Decide: production path or stay on PHP modules. |

### 5.3 Lower / polish

| # | Item | Notes |
|---|------|--------|
| 11 | PO-level supplier override fields | Optional snapshot columns on `stocks_purchase_orders`. |
| 12 | Admin end-user guides | Where to set company, suppliers, PO terms. |
| 13 | Logistics ? sales ? stock | Single chain for order ? dispatch ? delivery ? invoice. |
| 14 | Consolidate duplicate folders | `purchasing/` vs `stock/modules/purchases/`, legacy `stocks/` vs `stock/`. |

### 5.4 Stock�payment workflow phases (from design doc)

| Phase | Status |
|-------|--------|
| Phase 1 � Documentation | Done (`README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`) |
| Phase 2 � Schema discovery / gap analysis | **Not done** |
| Phase 3 � Safe DB migrations (vendor bills, links) | **Partial** (vendor bills UI exists; not all DBs) |
| Phase 4 � Vendor Bill module | **Partial** |
| Phase 5 � Payment Voucher integration | **Partial** (manual links; enrichment uses vouchers) |
| Phase 6 � Supplier Payment posting | **Not done** |
| Phase 7 � Reports (AP aging, PO vs paid) | **Not done** |
| Phase 8 � End-to-end testing | **Not done** |

---

## 6. Directory map (main code areas)

```
public_html/
??? includes/           # config.php, functions.php, revenue_ledger, mailer, mobile_footer
??? admin/              # Super admin, company-settings, user sync
??? company/            # Company admin, employees, register
??? employee/           # Payment vouchers (primary UI)
??? modules/            # sales, expenses, balances, accounting, finance, payroll, products
??? stock/              # Inventory, purchases, suppliers, shipments, reports
?   ??? modules/purchases/   # PO view/edit/create, vendor-bills, workflow
??? ultimate/           # Company slug aliases (e.g. ultimate/stock/...)
??? roadmaster/         # Roadmaster slug aliases
??? erp/                # petty-cash and ERP sub-apps
??? sales/              # Additional sales paths (legacy/parallel)
??? accounting/         # Accounting screens
??? logistics/          # Dispatch / delivery
??? weekly_tasks/       # Weekly plans & scoring
??? migrations/         # SQL migrations (e.g. user_company_index)
??? assets/             # CSS, JS, images, uploads
??? stock/stock-ui/     # React/Vite frontend (optional)
```

---

## 7. Key URLs (Ultimate, local)

| Area | Example URL |
|------|-------------|
| Login | `/public_html/login.php` |
| Module picker | `/public_html/ultimate/select-module` |
| Company settings | `/public_html/ultimate/admin/company-settings.php?company_slug=ultimate` |
| Payment vouchers | `/public_html/employee/my-vouchers.php` |
| Stock dashboard | `/public_html/ultimate/stock/` |
| Purchase orders | `/public_html/ultimate/stock/modules/purchases/` |
| View PO | `/public_html/ultimate/stock/modules/purchases/view_po.php?id=11` |
| Expenses | `/public_html/modules/expenses/index.php?module=expenses` |
| Petty cash | `/public_html/erp/petty-cash/index.php?module=petty_cash` |
| Weekly tasks | `/public_html/weekly_tasks/` |

---

## 8. Verification checklist (system smoke test)

**Platform**

- [ ] Login with company email ? correct tenant DB
- [ ] Module picker shows only enabled modules for company
- [ ] Company logo/name on documents matches Admin settings

**Payment vouchers**

- [ ] Create ? submit ? approve ? mark paid
- [ ] Payee visible on PO when voucher linked to PO

**Stock / PO**

- [ ] Create domestic PO ? view ? PDF single page
- [ ] Supplier contact matches Suppliers master (or voucher payee)
- [ ] Edit PO terms ? appears on view
- [ ] Mobile footer navigates on purchases pages

**Finance**

- [ ] Expense from receipt posts to balances (if used)
- [ ] Voucher-sourced expense appears on expenses dashboard

---

## 9. Known system limitations

- **Schema drift** � Tables/columns differ between Ultimate and Roadmaster DBs; code uses `SHOW COLUMNS` / `tableExists()` guards in many places.
- **Multi-PDO data** � Vouchers, suppliers, or stock may be read from tenant, ERP, or control connections; missing data if record only exists on one DB.
- **Client-side PDF** � PO PDF is a screenshot; quality and page breaks depend on browser.
- **Dual supplier tables** � `suppliers` and `stocks_suppliers` without guaranteed sync.
- **Legacy folders** � `stock_legacy_backup/`, `purchasing/`, parallel sales paths; not all are active in production.

---

## 10. Changelog pointer

| Date / period | Summary |
|---------------|---------|
| 2026 (recent) | PO view/edit: company profile, supplier enrichment, terms, PDF fix, mobile footer |
| 2026 | `user_company_index` login, company settings KV, multi-tenant docs in README.md |
| Design | Stock purchase payment workflow Phases 1�8 defined; implementation mostly Phase 1 + partial vendor bills |

---

*Last updated: June 2026. Update this file when closing a module milestone; keep module-specific detail in nested READMEs (e.g. purchases).*
