# Data Analysis & Reports Module

**Module name:** Data Analysis & Reports  
**Description:** KPIs, charts & business insights  
**Entry URL:** `{company_base}/modules/analytics/index.php?module=analytics`  
(Also registered in `select-module.php` as `modules/analytics/index?module=analytics`.)

This document describes the **existing implementation only**, based on source-code inspection. Nothing here assumes features that are not present in the codebase.

---

## Table of Contents

1. [Purpose & Access](#1-purpose--access)
2. [Module Structure](#2-module-structure)
3. [Architecture Overview](#3-architecture-overview)
4. [User Interface](#4-user-interface)
5. [KPIs](#5-kpis)
6. [Charts](#6-charts)
7. [Business Insights & AI](#7-business-insights--ai)
8. [Filters](#8-filters)
9. [Backend & Data Flow](#9-backend--data-flow)
10. [API Endpoints](#10-api-endpoints)
11. [Database Tables](#11-database-tables)
12. [SQL & Calculations](#12-sql--calculations)
13. [JavaScript / Frontend Logic](#13-javascript--frontend-logic)
14. [Permissions & Security](#14-permissions--security)
15. [Export & Reporting](#15-export--reporting)
16. [Error Handling](#16-error-handling)
17. [Performance Considerations](#17-performance-considerations)
18. [Dependencies](#18-dependencies)
19. [Feature Matrix](#19-feature-matrix)
20. [Known Issues & Limitations](#20-known-issues--limitations)
21. [Maintenance Guide](#21-maintenance-guide)
22. [Example User Workflow](#22-example-user-workflow)
23. [Architecture Summary](#23-architecture-summary)
24. [Business Reports & AI Report Engine](#24-business-reports--ai-report-engine)

---

## 1. Purpose & Access

### Purpose

The module aggregates ERP operational data into dashboards for:

- **Financial overview** ù sales, expenses, profit, receivables, inventory alerts
- **Sales & revenue** ù invoice totals, collections, customer rankings, invoice detail
- **Finance & expenses** ù income vs expense, voucher tracking, expense breakdown
- **Weekly mission performance** ù team completion rates, leaderboard, mission status
- **Business Reports hub card** ó links to the multi-domain report editor (`modules/sales-reports/`)
- **Advanced Sales Analytics** (`smart_report_sales.php`) ù deep sales drill-down with monthly matrices, targets, pipeline, AR aging, and a data-verification gate
- **Rep detail** (`smart_report_rep_detail.php`) ù per-sales-employee quotations/invoices with rule-based or AI insights

### Who can access it

| Check | Implementation |
| ----- | -------------- |
| Authentication | `requireLogin()` in `analytics_bootstrap()` (`includes/layout.php`) and all API files |
| Module-specific role | **Not implemented** ù any logged-in user who can open the module picker sees this module |
| Admin-only | **No** ù listed for all users in `select-module.php` |
| Company context | Multi-company routing via company slug URL + session `company_id`; queries use `analytics_append_company_scope()` where wired |

### Business problem solved

Provides managers and staff a single place to monitor sales performance, cash flow, expenses, receivables, inventory risk, and weekly mission completion without building ad-hoc SQL reports.

---

## 2. Module Structure

```
modules/analytics/
??? index.php                          # Overview dashboard (main entry)
??? sales.php                          # Sales & Revenue Analysis
??? finance.php                        # Finance & Expense Analysis
??? performance.php                    # Weekly Mission Performance
??? smart_report_sales.php             # Advanced Sales Analytics (not in tab nav)
??? smart_report_rep_detail.php        # Sales rep drill-down (React via CDN)
??? debug_pdo_tables.php               # Dev/debug script (hard-coded company slug)
??? README.md                          # This file
??? api/
?   ??? export.php                     # CSV export for main dashboards
?   ??? customer_invoices.php          # JSON: customer invoice drill-down (Sales Analytics)
?   ??? rep_ai_insights.php            # JSON: rep AI/rule insights
?   ??? sales_data_verify.php          # JSON: re-run data verification
??? includes/
?   ??? layout.php                     # Bootstrap, nav, Chart.js, page shell
?   ??? analytics_helpers.php          # Core KPI/chart query helpers
?   ??? analytics_company_scope.php    # Multi-company SQL scoping
?   ??? filters.php                    # Shared filter form (main pages)
?   ??? smart_report_sales_helpers.php # Large Sales Analytics engine (~3,800 lines)
?   ??? smart_report_sales_filters.php # Date presets for Sales Analytics
??? js/
?   ??? smart_report_rep_detail.jsx    # React UI for rep detail page
??? (styles)                           # assets/css/analytics.css (project root)

Related (linked, not inside this folder):
??? modules/sales-reports/             # Multi-domain AI business report writer (Sales + Procurement + Finance + Fleet + Store/Warehouse)
??? todo/includes/weekly_mission_helpers.php
??? modules/sales/functions.php        # Company scope helpers
??? includes/ai_helpers.php            # OpenAI integration
```

### File purposes (summary)

| File | Role |
| ---- | ---- |
| `includes/layout.php` | Session bootstrap, `requireLogin`, loads weekly-mission helpers, renders header/nav/footer, Chart.js injection |
| `includes/analytics_helpers.php` | Filter parsing, money formatting, all main-dashboard SQL aggregations |
| `includes/analytics_company_scope.php` | Delegates to `salesAppendCompanyScope()` for tenant isolation |
| `includes/smart_report_sales_helpers.php` | Sales Analytics matrices, targets, verification, AI insights |
| `assets/css/analytics.css` | Shared `.da-*` dashboard styling |

---

## 3. Architecture Overview

### Main dashboards (Overview, Sales, Finance, Performance)

```
User (logged in)
    ?
GET *.php?module=analytics&filters...
    ?
analytics_bootstrap() ? requireLogin(), analytics_parse_filters()
    ?
Server-side PHP queries (analytics_helpers.php)
    ?
PDO ? MySQL tables
    ?
HTML rendered with embedded KPI values + Chart.js config JSON
    ?
Browser renders charts (no AJAX for main KPIs/charts)
```

### Sales Analytics sub-system

```
GET smart_report_sales.php
    ?
smart_report_sales_drilldown() + matrix builders
    ?
smart_report_sales_verify_displayed_data()  ? blocks UI if inaccurate
    ?
If verification.accurate === true ? render matrices/sections
    ?
Optional AJAX: customer_invoices.php on matrix row click
```

### Rendering model

- **Primary:** Server-side PHP (SSR), not SPA
- **Charts:** Chart.js  (CDN), initialized via inline `<script>` from `analytics_render_chart_script()`
- **Sales Analytics matrices:** HTML tables generated in PHP
- **Rep detail:** React 18 + Tailwind (CDN) + Babel-transpiled JSX in `smart_report_rep_detail.jsx`

---

## 4. User Interface

### Global shell (all main tab pages)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| Employee header | Page title, subtitle, Modules button | `$employeeHeaderTitle`, `$employeeHeaderSubtitle` | `analytics_page_start()` in `layout.php` |
| Tab nav (`.da-nav`) | Switch Overview / Sales Reports / Weekly Missions / Sales & Revenue / Finance | Static links | `analytics_nav()` |
| Export CSV button | Download current section | Passes `section` + filter query params | `analytics_export_url()` ? `api/export.php` |
| Filter bar | Date, department, employee (+ optional week/module/status) | `$filters`, `$departments`, `$employees` | `includes/filters.php` |

**Note:** `.da-nav` / `.da-shell` classes are output in PHP but **no CSS rules for `.da-nav` exist** in `assets/css/analytics.css` ù tabs may appear minimally styled.

---

### Overview (`index.php`)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| Sales Reports hub card | Link to Sales Reports module | Report count, draft count | `salesReportsList()` from `modules/sales-reports/` |
| KPI grid (7 cards) | Cross-module summary | See [KPIs](#5-kpis) | `analytics_overview_kpis()` |
| Monthly Top Performer highlight | Best employee this month | Name, department, completion %, points | `analytics_monthly_top_performer()` |
| Sales Trend chart | Daily invoice revenue | Labels + amounts | `analytics_sales_trend()` |
| Income vs Expense chart | Monthly collected vs approved vouchers | Monthly buckets | `analytics_income_vs_expense()` |
| Employee Performance chart | Completion % by employee | Leaderboard names/rates | `analytics_employee_performance_chart()` ? `wm_leaderboard()` |
| Mission Status chart | Mission status counts | Completed / In Progress / Pending / Delayed | `analytics_mission_status_chart()` |

**Empty states:** Charts render with empty datasets if tables missing. Hub card shows `0 reports` if Sales Reports schema fails silently.

**Loading states:** None ù full page SSR.

---

### Sales & Revenue (`sales.php`)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| KPI grid (4) | Total sales, collected, outstanding, invoice count/avg | Invoice aggregates | `analytics_sum_invoices()`, row count |
| Sales Trend chart | Daily sales line chart | Same as overview trend | `analytics_sales_trend()` |
| Top Customers table | Top 10 by revenue | Customer name, invoices, revenue, collected, outstanding | Inline SQL in `sales.php` |
| Invoice Detail table | Up to 200 invoices | Invoice fields + customer | `analytics_sales_rows()` |

**Empty state:** `.da-empty` message when no rows.

---

### Finance & Expenses (`finance.php`)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| KPI grid (4) | Income, expenses, net profit, pending vouchers | Sums + count | `analytics_sum_invoices`, `analytics_sum_expenses`, inline voucher count |
| Income vs Expense chart | Monthly bar chart | Monthly income/expense | `analytics_income_vs_expense()` |
| Expense Breakdown chart | Doughnut by voucher description prefix | Top 8 categories | Inline SQL on `payment_vouchers` |
| Transactions table | Vouchers + ERP expenses | Up to 200 merged rows | `analytics_finance_rows()` |

---

### Weekly Missions (`performance.php`)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| Extended filters | Week, module category, status | `$showWeekFilter`, `$showModuleFilter`, `$showStatusFilter` | `performance.php` sets flags before `filters.php` |
| Monthly Top Performer | Same as overview | Leaderboard winner | `analytics_monthly_top_performer()` |
| Employee Performance chart | Bar chart of completion % | Filtered leaderboard | `analytics_employee_performance_chart()` |
| Mission Status chart | Doughnut | Status distribution | `analytics_mission_status_chart()` |
| All Users table | Per-employee mission stats | Completed/pending/delayed, %, points, streak | `analytics_performance_rows()` |
| Leaderboard table | Ranked list | Rank, completion %, points | `wm_leaderboard()` |

**Empty state:** `.da-empty` when no performance data.

---

### Sales Analytics (`smart_report_sales.php`)

**Not linked from main tab navigation** ù reachable only via direct URL  
`modules/analytics/smart_report_sales.php?module=analytics`.

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| Date filters + quick presets | YTD, This month, Last month, Last 30 days | `start_date`, `end_date` | `smart_report_sales_filters.php` |
| AI Data Checker banner | Blocks all analytics until verification passes | Issue list, AI/rules summary | `smart_report_sales_verify_displayed_data()`, `smart_report_sales_ai_verify_analysis()` |
| Sales Revenue & Volume section | Revenue KPIs + territory matrix | Summary, COGS, margin | `smart_report_sales_drilldown()`, `smart_report_sales_revenue_matrices()` |
| Performance vs. Targets | Team/rep targets and achievement | `sales_targets`, invoice totals by `created_by` | `smart_report_sales_team_performance_data()` |
| Customer & Product Ranking | Monthly matrices | Top customers, dormant products | `smart_report_ranking_matrices()` |
| Order Fulfillment | **Coming soon placeholder** | N/A | `smart_report_render_coming_soon_panel()` |
| Sales Pipeline | Open deals KPIs + matrix | Open order statuses | `smart_report_pipeline_matrices()` |
| AR Aging | Aging buckets | Outstanding by due date | `smart_report_sales_drilldown()` ? `ar_aging` |

**Loading state:** Customer row drill-down shows `.sa-drill-loading` while fetching `customer_invoices.php`.

**Blocked state:** If verification fails, main content is not rendered; user sees checker issues only.

---

### Rep Detail (`smart_report_rep_detail.php`)

| Component | Purpose | Data | Source |
| --------- | ------- | ---- | ------ |
| KPI cards (4) | Quote/invoice counts and values | Aggregates | PHP snapshot + React |
| AI Suggestions | Achievements + recommendations | Rules or OpenAI | `smart_report_rep_build_insights()` / `rep_ai_insights.php` |
| Quotations table | Rep's quotes in period | `sales_orders` | `smart_report_rep_quotations()` |
| Invoices table | Rep's invoices | `invoices` | `smart_report_rep_invoices()` |

---

## 5. KPIs

Currency format: **`TSh {amount}`** with zero decimals via `analytics_fmt_money()`.

### Overview dashboard

| KPI | Description | Formula | Data Source | Tables / Fields |
| --- | ----------- | ------- | ----------- | --------------- |
| Total Sales | Invoice revenue in period | `SUM(total_amount)` | `analytics_sum_invoices(..., 'total_amount')` | `invoices.total_amount`, `invoice_date`, `status != 'cancelled'` |
| Total Expenses | Approved spending in period | Sum of payment vouchers (+ fallbacks) | `analytics_sum_expenses()` | `payment_vouchers.total_amount`, `status = 'approved'`; fallback `erp_expenses`, `expenses_requests` |
| Net Profit | Cash-based profit | `SUM(amount_paid) - total_expenses` | `analytics_overview_kpis()` | `invoices.amount_paid` |
| Pending Payments | Outstanding receivables | `SUM(balance_due)` all non-cancelled invoices (overrides period calc) | `analytics_overview_kpis()` | `invoices.balance_due` |
| Low Stock Alerts | Products at/below reorder | Count where `stock.quantity <= products.reorder_level` | `analytics_low_stock_count()` | `products`, `stock` |
| Employee Performance | Avg mission completion in date range | `AVG(completion_rate)` for weeks in range | `performance_points.completion_rate`, filtered by optional employee | `performance_points` |
| Mission Completion | Current week team average | `AVG(completion_rate)` for `week_start` | `analytics_week_start($filters)` | `performance_points` |

### Sales page

| KPI | Formula | Query |
| --- | ------- | ----- |
| Total Sales | `SUM(total_amount)` in date range | `analytics_sum_invoices()` |
| Collected | `SUM(amount_paid)` | `analytics_sum_invoices(..., 'amount_paid')` |
| Outstanding | `totalSales - totalCollected` | PHP calculation |
| Invoices | `COUNT(*)` | `analytics_sales_rows()` count |
| Avg invoice | `totalSales / invoiceCount` | PHP |

### Finance page

| KPI | Formula | Query |
| --- | ------- | ----- |
| Total Income | `SUM(amount_paid)` on invoices | `analytics_sum_invoices(..., 'amount_paid')` |
| Total Expenses | Same as overview expenses | `analytics_sum_expenses()` |
| Net Profit | `totalIncome - totalExpenses` | PHP |
| Pending Vouchers | Count vouchers not approved/rejected/cancelled | Inline SQL on `payment_vouchers` |

### Performance page

No dedicated KPI cards ù data is in tables/charts. Top Performer highlight shows name, department, points, completion %.

### Sales Analytics KPIs (smart_report_sales)

| KPI | Section | Formula / Source |
| --- | ------- | ---------------- |
| Invoices | Revenue | `COUNT(*)` non-cancelled in period |
| Revenue | Revenue | `SUM(total_amount)` |
| COGS | Revenue | `SUM(qty * product cost column)` from `sales_order_items` |
| Gross profit | Revenue | `revenue - cogs` |
| Margin % | Revenue | `(gross_profit / revenue) * 100` |
| Company/Team target | Performance | `sales_targets` (`user_id = 0` for company, or summed rep targets) prorated by months in range |
| Team sales | Performance | `SUM(invoice total_amount)` |
| Overall achievement | Performance | `(team_actual / team_target) * 100` |
| Reps on target | Performance | Count reps with `achievement_pct >= 100` and sales department |
| Open Deals | Pipeline | Count open-status `sales_orders` |
| Pipeline Value | Pipeline | `SUM(total_amount)` open orders |
| AR aging buckets | AR Aging | `SUM(balance_due)` grouped by days overdue vs `due_date` or `invoice_date` |

---

## 6. Charts

All main-dashboard charts use **Chart.js** (loaded from CDN in `layout.php`). Data is embedded at page render time ù **no chart API**.

| Chart | Page | Type | X-Axis | Y-Axis / Series | Data Function |
| ----- | ---- | ---- | ------ | --------------- | ------------- |
| Sales Trend | Overview, Sales | Line | Invoice dates (`M j`) | `SUM(total_amount)` per day | `analytics_sales_trend()` |
| Income vs Expense | Overview, Finance | Bar | Months (`M Y`) | Income (paid), Expenses (approved vouchers) | `analytics_income_vs_expense()` |
| Employee Performance | Overview, Performance | Bar (horizontal if >6 labels) | Employee names | Completion % (0ù100) | `analytics_employee_performance_chart()` ? `wm_leaderboard()` |
| Mission Status | Overview, Performance | Doughnut | Status labels | Count per status | `analytics_mission_status_chart()` |
| Expense Breakdown | Finance | Doughnut | First 40 chars of description/payee | `SUM(total_amount)` | Inline SQL in `finance.php` |

### Sales Analytics ùchartsù

Sales Analytics does **not** use Chart.js. It uses **monthly matrix HTML tables** (`smart_report_render_erp_matrix_html()`) with heat-map-style cell coloring based on `smart_report_matrix_value_class()`.

Matrix types:

| Matrix | Builder | Tree / grouping |
| ------ | ------- | --------------- |
| Revenue by Territory | `smart_report_sales_revenue_matrices()` | Territory from `customers.city` or `country` + `ai_territory_mappings` |
| Top Customers by Revenue | `smart_report_ranking_matrices()` | Customer ù month |
| Dormant Products | `smart_report_ranking_matrices()` | Product ù month (90+ days since last sale) |
| Open Deals by Month | `smart_report_pipeline_matrices()` | Order ù month |

---

## 7. Business Insights & AI

### Overview / main dashboards

**No dynamic business insights** on Overview, Sales, Finance, or Performance pages. Only raw KPIs, charts, and tables.

**Hard-coded highlights:**

- **Monthly Top Performer** ù employee with highest `SUM(award_points)` and best `AVG(completion_rate)` since first day of current month (`analytics_monthly_top_performer()`).

### Sales Analytics ù Data Checker

| Aspect | Detail |
| ------ | ------ |
| Trigger | Every load of `smart_report_sales.php` |
| What it checks | Displayed revenue, invoice count, gross profit math, margin %, team sales, rep sum, pipeline count/value, matrix totals vs re-queried ùground truthù |
| Pass condition | All checks within tolerance (`smart_report_sales_amounts_close()`, default ù0.01) |
| On failure | **Entire sales analytics body hidden**; issues listed in checker UI |
| AI on failure | `smart_report_sales_ai_verify_analysis()` calls OpenAI (if enabled) to explain issues |
| Fallback | Rule-based issue list if AI disabled/unavailable |

### Rep insights (rules + optional AI)

| Function | Type | Logic |
| -------- | ---- | ----- |
| `smart_report_rep_build_insights()` | Rule-based | Thresholds on achievement % (80/100), conversion % (35/60), quote value realized, team share ?40%, zero-invoice/quote cases |
| `smart_report_rep_fetch_ai_insights()` | AI (optional) | OpenAI via `ai_openai_request()`; expects `ACHIEVEMENT:` / `SUGGESTION:` lines |
| Provider | OpenAI | Through `includes/ai_helpers.php` |
| Enable gate | `ai_fetch_settings_row()['is_enabled']` |
| Display | Rep detail React component; also `rep_ai_insights.php` API |
| Error handling | Falls back to rules; errors logged; API returns `{ success: false }` |

### Territory normalization AI

`smart_report_sales_sync_territory_mappings()` may call OpenAI to normalize city/territory names into `ai_territory_mappings` table, with local `ucwords` fallback.

---

## 8. Filters

### Main pages (`includes/filters.php`)

| Filter | GET param | Default | Affects |
| ------ | --------- | ------- | ------- |
| From | `start_date` | First day of current month | All date-scoped KPIs/charts/tables |
| To | `end_date` | Today | Same |
| Department | `department` | `""` (all) | Employee list, performance charts/rows, mission chart (joins users) |
| Employee | `employee` | `0` (all) | Overview mission KPIs, performance data, mission chart |
| Week | `week_start` | Current ISO week Monday | Performance page only (`$showWeekFilter = true`) |
| Module | `module` (mission category) | `""` | Mission status chart filter on `weekly_missions.category` |
| Status | `status` | `""` | Mission chart ù zeroes non-selected statuses |
| ERP module context | `module=analytics` | Required hidden field | Sidebar/navigation context only |

**Submission:** HTML GET form ù **Apply** submits; **Reset** clears to `?module=analytics`.

**Date validation:** `analytics_parse_filters()` enforces `YYYY-MM-DD`, swaps if start > end.

**Combination:** All active filters combine (AND logic in SQL).

### Sales Analytics (`smart_report_sales_filters.php`)

| Filter | Default | Notes |
| ------ | ------- | ----- |
| `start_date` | Jan 1 current year | Auto-submit on change |
| `end_date` | Today | Auto-submit on change |
| Quick presets | YTD, This month, Last month, Last 30 days | Link navigation |

Additional params parsed but **not exposed in Sales Analytics UI**:

- `tree_type` ù matrix grouping (default `customer_group`)
- `value_qty` ù `value` or `quantity`

---

## 9. Backend & Data Flow

### Main dashboard request

```
GET /modules/analytics/{page}.php?module=analytics&start_date=...&end_date=...
    ?
includes/layout.php ? analytics_bootstrap()
    ?
requireLogin() + analytics_parse_filters()
    ?
Page-specific queries (analytics_helpers.php or inline SQL)
    ?
analytics_append_company_scope() / analytics_scoped_tables()
    ?
HTML + inline Chart.js config
```

### Customer invoice drill-down (Sales Analytics only)

```
Click customer row in matrix
    ?
JavaScript fetch GET api/customer_invoices.php?customer_id=&start_date=&end_date=&module=analytics
    ?
smart_report_customer_invoices()
    ?
JSON { success, html } inserted into drill panel
```

### Rep AI insights refresh

```
React useEffect on mount
    ?
GET api/rep_ai_insights.php?user_id=&rep_name=&start_date=&end_date=
    ?
smart_report_rep_performance_snapshot() + smart_report_rep_fetch_ai_insights()
    ?
JSON { achievements, suggestions, source, html }
```

---

## 10. API Endpoints

| Endpoint | Method | Auth | Parameters | Response |
| -------- | ------ | ---- | ---------- | -------- |
| `api/export.php` | GET | `requireLogin()` | `section` (`overview`\|`sales`\|`finance`\|`performance`), filter params | CSV download (UTF-8 BOM) |
| `api/customer_invoices.php` | GET | `requireLogin()` | `customer_id` or `customer_label`, `start_date`, `end_date` | JSON `{ success, count, html }` |
| `api/rep_ai_insights.php` | GET | `requireLogin()` + `analytics_user_in_company()` | `user_id`, `rep_name`, dates | JSON insights |
| `api/sales_data_verify.php` | GET | `requireLogin()` | `start_date`, `end_date` | JSON verification + analysis |

**Note:** `api/export.php` requires `__DIR__ . '/../../includes/functions.php'` (two levels up). From `modules/analytics/api/`, that resolves to `modules/includes/functions.php`, which **does not exist**. The correct path used elsewhere is `../../../includes/functions.php`. Export may **fail at runtime** unless the server resolves paths differently.

---

## 11. Database Tables

| Table | Purpose | Important Fields | Used By |
| ----- | ------- | ---------------- | ------- |
| `invoices` | Sales revenue, AR, collections | `total_amount`, `amount_paid`, `balance_due`, `invoice_date`, `status`, `customer_id`, `created_by`, `company_id` | All sales/finance analytics |
| `customers` | Customer names, segments, territory | `company_name`, `customer_type`, `city`, `country`, `company_id` | Sales, matrices, top customers |
| `payment_vouchers` | Expenses | `total_amount`, `status`, `date_created`, `description`, `payee_name`, `company_id` | Finance, income vs expense |
| `erp_expenses` | Fallback expenses | `amount`, `date`, `payee`, `status` | `analytics_sum_expenses()`, finance rows |
| `expenses_requests` | Fallback expenses | `amount`, `date`, `status` | `analytics_sum_expenses()` |
| `products` | Product catalog, reorder | `reorder_level`, cost columns, `name` | Low stock, COGS, dormant products |
| `stock` | Inventory quantities | `quantity`, `product_id` | Low stock alerts |
| `users` | Employees, departments | `full_name`, `department`, `role`, `is_active`, `company_id` | Filters, performance, rep attribution |
| `weekly_missions` | Mission records | `status`, `week_start`, `user_id`, `category`, `due_day`, `completed_at` | Mission status chart |
| `performance_points` | Weekly aggregates | `completion_rate`, `award_points`, `week_start`, `user_id`, totals | Performance KPIs, leaderboard |
| `sales_orders` | Quotes/orders/pipeline | `status`, `quote_date`, `total_amount`, `created_by`, `customer_id` | Pipeline, rep quotes, fulfillment |
| `sales_order_items` | Line items | `line_total`, `quantity`, `product_id`, `order_id` | COGS, product revenue matrices |
| `sales_targets` | Sales targets | `user_id` (0 = company), `period`, `target_amount`, `company_id` | Team/rep achievement |
| `ai_territory_mappings` | Normalized territory names | `raw_name`, `normalized_name` | Territory revenue matrix (auto-created) |

### Relationships (as used in queries)

```
invoices ? customers (customer_id)
invoices ? users (created_by)          [rep performance]
invoices ? sales_orders (order_id)     [customer COGS join]
sales_orders ? sales_order_items ? products
weekly_missions ? users (user_id)
performance_points ? users (user_id)
```

---

## 12. SQL & Calculations

### Representative patterns

**Invoice revenue (scoped):**

```sql
SELECT COALESCE(SUM(total_amount), 0)
FROM invoices
WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
-- + company scope via salesAppendCompanyScope()
```

**Daily sales trend:**

```sql
SELECT DATE(invoice_date) AS d, COALESCE(SUM(total_amount), 0) AS total
FROM invoices
WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
GROUP BY DATE(invoice_date) ORDER BY d ASC
```

**Mission status (computed):**

Rows from `weekly_missions` for `week_start`; status may be overridden by `wm_compute_status()` using `completed_at`, `due_day`, and stored `status`.

**Pending missions (derived):**

`pending_missions = total_missions - completed_missions - delayed_missions` in PHP after fetch.

**Gross profit:**

Revenue from `sales_order_items.line_total`; COGS from `quantity * products.{cost column}`; falls back to invoice revenue if order lines unavailable.

**Sales target proration:**

Yearly target ù (`count(months in range)` / 12) via `smart_report_sales_rep_period_target()`.

### Performance notes

- Invoice detail limited to **200 rows** (`analytics_sales_rows()`)
- Finance rows capped at **200** after merge
- Top customers limited to **10ù15** depending on page
- Matrix queries can be heavy on large datasets (multi-table joins, monthly pivot in PHP)

---

## 13. JavaScript / Frontend Logic

| Function / Script | File | Purpose | Inputs | Output |
| ----------------- | ---- | ------- | ------ | ------ |
| `analytics_render_chart_script()` | `includes/layout.php` | Instantiate Chart.js | Canvas ID, chart config | Inline script |
| Date filter auto-submit | `smart_report_sales.php` | Submit on date change | `start_date`, `end_date` | Page reload |
| Date preset menu | `smart_report_sales.php` | Toggle quick-range dropdown | Click handlers | Navigation |
| Matrix tree toggle | `smart_report_sales.php` | Expand/collapse child rows | DOM events | UI state |
| Customer drill-down fetch | `smart_report_sales.php` | Load invoices for customer | `customer_invoices.php` params | HTML panel |
| `SmartReportRepDetail` | `js/smart_report_rep_detail.jsx` | Rep detail SPA shell | `window.__REP_DETAIL_DATA__` | React render |
| AI insights fetch | `smart_report_rep_detail.jsx` | Refresh suggestions | `rep_ai_insights.php` | Updates insights state |

**Main dashboards:** No dedicated `.js` files ù charts only.

---

## 14. Permissions & Security

| Topic | Status |
| ----- | ------ |
| Login required | ? `requireLogin()` on all pages/APIs |
| Role-based module access | ? Not implemented |
| Rep detail cross-company | ? `analytics_user_in_company()` blocks wrong-company `user_id` |
| Company SQL scoping | ?? Partial ù most invoice/sales queries scoped; several gaps (see [Known Issues](#20-known-issues--limitations)) |
| SQL injection | ? Prepared statements used in helper functions |
| Input validation | ? Date regex validation; `tree_type` / `value_qty` whitelists in smart report |
| Session company vs URL slug | Helper `analytics_url_company_mismatch()` exists but **not called** from analytics bootstrap |

---

## 15. Export & Reporting

| Format | Trigger | Implementation | Sections |
| ------ | ------- | -------------- | -------- |
| CSV | Export CSV button | `api/export.php` | overview KPIs, sales invoices, finance transactions, performance rows |
| PDF | ? | Not in analytics module | ù |
| Excel | ? | ù | ù |
| Print | ? | No dedicated print view | ù |

CSV includes UTF-8 BOM (`EF BB BF`). Filename pattern: `analytics-{section}-{date}.csv`.

**Sales Reports module** (linked from nav) has its own PDF/export pipeline ù outside this folder.

---

## 16. Error Handling

| Scenario | Behavior |
| -------- | -------- |
| Missing DB tables | Helpers return `0`, empty arrays; pages render empty charts/tables |
| Query exceptions | `error_log()` in smart report helpers; section may show zeros |
| Sales Reports count on overview | try/catch ? logs error, shows 0 |
| Export failure | Broken require path may cause fatal error (see export API note) |
| Verification failure | Sales Analytics content hidden; checker shows issues |
| AI failure | Falls back to rule-based insights/analysis |
| Rep AI fetch (React) | `.catch(() => {})` ù silent failure, keeps initial rules insights |
| Auth failure | Redirect via global `requireLogin()` |

**Weaknesses:** Silent AJAX failures on rep detail; no user-visible toast for chart render failures.

---

## 17. Performance Considerations

| Area | Observation |
| ---- | ----------- |
| Overview page | **7+ separate query groups** per request (KPIs, 4 charts, top performer, sales report count) |
| `smart_report_sales.php` | **Very heavy** ù full drilldown + 3 matrix builders + verification re-queries ground truth |
| Territory sync | May invoke OpenAI on first load when unmapped territories exist |
| N+1 | Mostly avoided via aggregated SQL; rep targets queried per rep in loop |
| Low stock query | **No company scope** ù full table scan |
| `performance_points` on overview | No company filter on AVG queries |
| Browser payload | Matrices can be large HTML tables; preview row limits mitigate somewhat |
| Caching | **None** ù every request hits DB |
| Indexes | Not determinable from codebase; date-range filters on `invoice_date`, `week_start` benefit from indexes |

---

## 18. Dependencies

| Dependency | Where | Why |
| ---------- | ----- | --- |
| Chart.js (CDN) | `layout.php` | Main dashboard charts |
| Bootstrap 5.3 (CDN) | `layout.php` | Base CSS |
| Bootstrap Icons (CDN) | `layout.php` | Icons |
| React 18 (CDN) | `smart_report_rep_detail.php` | Rep detail UI |
| Tailwind CSS (CDN) | `smart_report_rep_detail.php` | Rep detail styling |
| Font Awesome 6 (CDN) | Rep detail | Icons |
| Babel Standalone (CDN) | Rep detail | JSX transpilation in browser |
| `includes/functions.php` | Bootstrap | Auth, PDO, `tableExists`, etc. |
| `todo/includes/weekly_mission_helpers.php` | Bootstrap | Missions, leaderboard |
| `modules/sales/functions.php` | Company scope | `salesAppendCompanyScope()` |
| `includes/ai_helpers.php` | Smart report | OpenAI requests |
| `modules/sales-reports/includes/sales-reports-lib.php` | Overview hub card | Report counts |

---

## 19. Feature Matrix

| Feature | Status | Location | Data Source | Notes |
| ------- | ------ | -------- | ----------- | ----- |
| Overview KPI dashboard | ? | `index.php` | invoices, vouchers, stock, performance_points | |
| Sales & Revenue page | ? | `sales.php` | invoices, customers | |
| Finance & Expenses page | ? | `finance.php` | invoices, payment_vouchers | |
| Weekly Missions page | ? | `performance.php` | performance_points, weekly_missions, users | Requires todo mission tables |
| Tab navigation | ? | `layout.php` ? `analytics_nav()` | ù | `.da-nav` CSS missing |
| Shared date/employee filters | ? | `includes/filters.php` | users | |
| Chart.js visualizations | ? | Main pages | SSR data | 4 chart types |
| CSV export | ?? | `api/export.php` | Same as pages | Possible broken require path |
| Sales Reports hub link | ? | `index.php` | `sales_reports` table | Separate module |
| Monthly Top Performer | ? | Overview, Performance | performance_points | Current calendar month |
| Sales Analytics (smart report) | ? | `smart_report_sales.php` | invoices, orders, targets | Not in tab nav |
| Data verification gate | ? | Sales Analytics | Re-query ground truth | Blocks UI on failure |
| Monthly ERP matrices | ? | Sales Analytics | invoices, orders, products | HTML tables, not charts |
| Rep detail page | ? | `smart_report_rep_detail.php` | orders, invoices | React CDN |
| Rule-based rep insights | ? | `smart_report_rep_build_insights()` | Snapshot metrics | |
| AI rep insights | ?? | `smart_report_rep_fetch_ai_insights()` | OpenAI | Requires AI enabled |
| AI data checker analysis | ?? | `smart_report_sales_ai_verify_analysis()` | OpenAI | On verification failure only |
| Customer invoice AJAX drill-down | ? | `customer_invoices.php` | invoices | Sales Analytics only |
| Order Fulfillment section | ? | Sales Analytics | ù | "Coming soon" panel |
| PDF/Excel export | ? | ù | ù | |
| Role-based access control | ? | ù | ù | Login only |
| Real-time / AJAX dashboard refresh | ? | Main pages | ù | Full page reload on filter |
| smart_report hub page | ? | ù | ù | Removed (per codebase; no `smart_report.php`) |

---

## 20. Known Issues & Limitations

1. **`api/export.php` require path** ù Uses `../../includes/functions.php` instead of `../../../includes/functions.php`; likely broken.
2. **Sales Analytics not in navigation** ù `smart_report_sales.php` has no tab link; users must know the URL.
3. **Data checker blocks entire Sales Analytics** ù Any verification mismatch hides all content (strict gate).
4. **Order Fulfillment** ù UI shows "Coming soon" despite partial fulfillment metrics computed in `smart_report_sales_drilldown()`.
5. **`.da-nav` unstyled** ù No CSS rules for tab navigation class.
6. **Company scope gaps:**
   - `analytics_low_stock_count()` ù no company filter
   - `finance.php` expense category + pending voucher queries ù no `analytics_append_company_scope()`
   - `erp_expenses` / `expenses_requests` fallbacks ù no company scope
   - Some `performance_points` queries on overview ù no company filter
7. **Pending Payments KPI** ù Uses all-time `SUM(balance_due)`, not limited to selected date range (subtitle says "Outstanding receivables").
8. **Mission Completion KPI subtitle** ù Says "Current week team average" but uses selected week from filters when `week_start` set on overview (week filter only shown on performance page by default).
9. **`debug_pdo_tables.php`** ù Hard-coded `company_slug = 'roadmaster'`; dev artifact, not production UI.
10. **Rep detail JSX via Babel in browser** ù Not pre-built; depends on CDN availability.
11. **`analytics_url_company_mismatch()`** ù Defined but unused in analytics bootstrap.
12. **Hub card CSS for smart report** ù `.da-hub-card--smart` styles exist but smart report hub card was removed from index.

---

## 21. Maintenance Guide

### Add a KPI to Overview

1. Add query logic in `analytics_overview_kpis()` (`includes/analytics_helpers.php`).
2. Add HTML card in `index.php`.
3. Optionally add row to `api/export.php` `overview` case.

### Add a chart

1. Create a data function in `analytics_helpers.php` returning `labels` + series arrays.
2. Call it from the target page PHP file.
3. Add `<canvas id="...">` markup and `analytics_render_chart_script()` call.

### Add a filter

1. Parse in `analytics_parse_filters()`.
2. Add form control in `includes/filters.php` (with optional `$showXFilter` flag).
3. Apply in relevant SQL helper `WHERE` clauses.

### Modify Sales Analytics matrices

1. Edit or extend `smart_report_sales_matrix_data()` / ranking builders in `smart_report_sales_helpers.php`.
2. Register matrix in `smart_report_sales_revenue_matrices()`, `smart_report_ranking_matrices()`, or `smart_report_pipeline_matrices()`.
3. Render via `smart_report_render_erp_matrix_html()` in `smart_report_render_sales_drilldown_html()`.
4. If new totals affect verification, update `smart_report_sales_verify_displayed_data()`.

### Change sales targets logic

Edit functions: `smart_report_sales_admin_company_target()`, `smart_report_sales_rep_period_target()`, `smart_report_sales_team_performance_data()`.

Tables: `sales_targets` (`user_id = 0` for company-wide yearly target).

### Enable/disable AI insights

Configured globally via AI settings (`includes/ai_helpers.php` ? `is_enabled`). Analytics code automatically falls back to rules.

---

## 22. Example User Workflow

### Overview dashboard

1. User logs in and opens **Data Analysis & Reports** from module picker (`select-module.php`).
2. Browser loads `index.php?module=analytics`.
3. `requireLogin()` validates session; `analytics_bootstrap()` loads filters (default: month-to-date).
4. Server runs `analytics_overview_kpis()`, trend/chart helpers, optional Sales Reports count.
5. Page renders KPI cards, hub card, and four Chart.js charts with embedded data.
6. User changes **From/To** dates and clicks **Apply** ù full page reload with new query string.
7. User clicks **Export CSV** ù browser requests `api/export.php?section=overview&...` (if path works).
8. User clicks **Sales Reports** hub card ? navigates to `modules/sales-reports/index.php?module=analytics`.

### Sales Analytics (direct URL)

1. User opens `smart_report_sales.php?module=analytics` (not linked from tabs).
2. Server builds drilldown + matrices, runs data verification.
3. If accurate ? sections render; user picks **Last 30 days** preset ? reload.
4. User expands territory matrix, clicks customer row ? AJAX loads invoice detail panel.
5. User clicks rep name ? `smart_report_rep_detail.php` with React UI and AI suggestions.

---

## 23. Architecture Summary

| Layer | Technology |
| ----- | ---------- |
| Frontend (main) | Server-rendered PHP + Chart.js + Bootstrap |
| Frontend (Sales Analytics) | PHP HTML matrices + vanilla JS |
| Frontend (rep detail) | React 18 + Tailwind (CDN) |
| Backend | PHP 8.x-style (`declare(strict_types=1)` in helpers) |
| Database | MySQL via PDO (`$pdo` global) |
| APIs | Thin JSON/CSV PHP endpoints under `api/` |
| Charts | Chart.js (main dashboards only) |
| Calculations | SQL aggregation in PHP helpers; some derived metrics in PHP |
| Authentication | Session-based `requireLogin()` |
| Authorization | Minimal ù login + rep company check only |
| Multi-company | `salesAppendCompanyScope()` via `analytics_company_scope.php` (partial coverage) |
| Reporting/export | CSV only (main dashboards) |
| External services | OpenAI (optional) for territory mapping, data checker analysis, rep insights |
| Related module | `modules/sales-reports/` ù multi-domain AI business report writer (React + TinyMCE) |

---

## 24. Business Reports & AI Report Engine

The **editable business report system** lives in `modules/sales-reports/`. The analytics module provides dashboard KPIs and links into this report writer. Sales remains the reference domain; Procurement, Finance, Driver/Fleet, and Store/Warehouse were added using the same architecture.

### Entry points

| URL | Purpose |
| --- | ------- |
| `modules/sales-reports/index.php?module=analytics` | Report list + create via sidebar Reports submenu |
| `modules/analytics/index.php` | Overview hub card ? Business Reports |

### Report engine architecture

```
User ? Create modal (domain + date range + filters)
     ? salesReportsCreate() [report_domain, filters_json]
     ? Autofill: reportEngineAutofillSections() or salesReportsAutofillSections()
     ? KPI snapshot: reportEngineBuildSnapshot() (deterministic SQL ? JSON)
     ? AI narrative: reportEngineGenerateAiText() (interpretation only; no invented numbers)
     ? TinyMCE editor (editable sections)
     ? Save ? sales_report_documents + sales_report_versions
     ? Export PDF/Word (live ERP blocks refreshed on export)
```

Internal layout:

```
modules/sales-reports/includes/
??? report-engine.php              # Domain registry, schema, section vocabulary, cover pages
??? report-domain-data.php         # Dispatcher: fetch ERP blocks, build AI snapshots
??? report-domain-procurement.php  # PO spend, suppliers, categories
??? report-domain-finance.php      # Income, expenses, vouchers, AR
??? report-domain-fleet.php        # Delivery trips, drivers, route costs
??? report-domain-store.php        # Inventory, movements, valuation
??? report-domain-autofill.php     # Section ? ERP block / prose templates
??? report-domain-ai.php           # Domain AI prompts + rules fallback
```

### Report domains

| Domain key | Label | Primary ERP tables |
| ---------- | ----- | ------------------ |
| `sales` | Sales Report | `invoices`, `sales_orders`, `sales_targets`, ù (unchanged) |
| `procurement` | Procurement Report | `stocks_purchase_orders`, `stocks_po_items`, `stocks_suppliers` (fallback: `erp_purchase_orders`) |
| `finance` | Finance Report | `payment_vouchers`, `financial_accounts`, `invoices` |
| `fleet` | Driver / Fleet Report | `delivery_trips`, `delivery_orders` |
| `store_warehouse` | Store / Warehouse Report | `products`, `stock`, `stock_movements`, `warehouses` |

### Database (report storage)

Existing tables extended (migration in `reportEngineEnsureDomainColumns()`):

| Table | New columns |
| ----- | ----------- |
| `sales_reports` | `report_domain VARCHAR(32) DEFAULT 'sales'`, `filters_json LONGTEXT` |

Documents remain in `sales_report_documents` and `sales_report_versions`.

### APIs (sales-reports)

| Endpoint | Method | Purpose |
| -------- | ------ | ------- |
| `api/create.php` | POST | Create report; accepts `report_domain`, `filters`, date range |
| `api/domain-meta.php` | GET | Filter definitions + option lists per domain |
| `api/erp-data.php` | GET | Insert/refresh ERP data blocks (domain-aware) |
| `api/export.php` | GET | PDF/Word/print; refreshes live ERP blocks via `reportEngineRefreshLiveBlocks()` |

### Filters (optional, module-specific)

Loaded from `reportEngineFilterDefinitions()` / `reportEngineFilterOptions()`:

- **Procurement:** supplier, PO status, buyer/employee
- **Finance:** account, voucher status, transaction type
- **Fleet:** driver, trip status, vehicle
- **Store/Warehouse:** warehouse, category, stock status

Filters are stored in `filters_json` and merged into SQL via `reportEngineFiltersFromReport()`.

### AI data contract

1. PHP runs deterministic SQL ? structured JSON snapshot (`reportEngineBuildSnapshot()`).
2. AI receives snapshot + section vocabulary; generates narrative, findings, recommendations.
3. If OpenAI is disabled, rules-based fallback text is used (`report-domain-ai.php`).
4. AI must not invent figures; all KPIs come from ERP queries.

### Template vocabulary

Section catalogs per domain in `reportEngineSectionCatalog()` ù e.g. Executive Summary, KPI Overview, domain-specific sections (Spend Analysis, Revenue Analysis, Trip Performance, Stock Movement), plus Recommendations, Action Plan, Conclusion.

Sections with no supporting data are omitted or noted in `data_quality_notes` rather than showing empty placeholders.

### Security

- Same session/auth as sales reports (`salesReportsRequireAccess()`).
- Company scoping via `reportEngineAppendScope()` ? `analytics_append_company_scope()` on supported tables.
- **Gap:** not every ERP table has company_id columns; domain providers skip or note missing scope where applicable.

### Frontend

React/Vite app in `modules/sales-reports/frontend/`:

- `CreateReportTypeModal.jsx` ù Step 1: domain picker; Step 2: date range + filters; Sales keeps monthly/quarterly presets.
- `ReportCard.jsx` ù domain badge on list rows.
- Built assets: `frontend/dist/` (run `npm run build` after JS/CSS changes).

### Export & editing

- Full WYSIWYG edit in TinyMCE; manual edits persist without re-running AI.
- PDF/Word export from saved HTML; live ERP blocks re-query on export.
- Excel export remains sales-transaction oriented (domain reports: use PDF/Word).
- Per-section AI regenerate UI exists in codebase but is not fully wired for domain reports.

### Known limitations (report engine)

1. Fleet reporting limited to delivery trip data ù no fuel/maintenance/vehicle registry tables in ERP.
2. Procurement/finance KPIs depend on which tables exist per company database.
3. Excel export not extended for non-sales domains.
4. Section-level AI regenerate not implemented for new domains.
5. Company scope coverage varies by table.

### CLI smoke test

`modules/sales-reports/tools/test-domains-cli.php` ù verifies snapshot + ERP block generation for all non-sales domains.

---

*Documentation generated from codebase inspection. Last reviewed against files in `modules/analytics/` and related dependencies.*
