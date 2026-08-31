# Expenses Module

Complete reference for the **Expenses desk**: how expenses are recorded, how they map onto the
**Chart of Accounts**, and how **bulk import** works.

Audience: developers and finance users working on `modules/expenses`.

Two sentence summary: an expense answers two questions -- *what* was the money spent on (an
**expense account**) and *where* did the money leave from (a **payment account**, i.e. bank / cash /
mobile). Both are rows in `financial_accounts`, the single Chart of Accounts owned by the Balances
module.

---

## Table of contents

1. [Pages and URLs](#1-pages-and-urls)
2. [Directory layout](#2-directory-layout)
3. [The account model](#3-the-account-model)
4. [Database tables](#4-database-tables)
5. [Expense lifecycle: draft to posted](#5-expense-lifecycle-draft-to-posted)
6. [What posting writes to the ledger](#6-what-posting-writes-to-the-ledger)
7. [Where imported and posted expenses show up](#7-where-imported-and-posted-expenses-show-up)
8. [Petty cash is a separate module](#8-petty-cash-is-a-separate-module)
9. [Bulk import](#9-bulk-import)
10. [API reference](#10-api-reference)
11. [Frontend (React) build](#11-frontend-react-build)
12. [Permissions and multi-company scope](#12-permissions-and-multi-company-scope)
13. [Setup checklist](#13-setup-checklist)
14. [Troubleshooting](#14-troubleshooting)
15. [Known issues and gotchas](#15-known-issues-and-gotchas)

---

## 1. Pages and URLs

All pages take `?module=expenses` so the sidebar highlights correctly. Paths below are relative to
the company root, e.g. `http://localhost/public_html/<company-slug>/modules/expenses/`.

| Page | File | React page key | Purpose |
|------|------|----------------|---------|
| Expenses desk (list + KPIs) | `index.php` | `list` | Search, filter, quick view, export |
| Record expense | `create.php` | `create` | Single expense entry form |
| Edit draft | `edit.php?id={id}` | `edit` | Edit an unposted draft |
| **Bulk import** | `import.php` | `import` | Upload CSV / XLSX |
| Smart insights | `smart_insights.php` | `insights` | Trends and AI commentary |
| Receipt / voucher view | `view.php?id={id}` | (plain PHP) | Printable receipt, posting |
| Payees | `payees.php` | (plain PHP) | Payee / vendor master list |
| Export | `export.php` | (plain PHP) | Spreadsheet export of the filtered list |
| Soft delete | `delete.php?id={id}` | (plain PHP) | Sets `status = 'deleted'` |
| Legacy redirect | `list.php` | -- | 302 to `index.php` |

`index.php`, `create.php`, `edit.php`, `import.php` and `smart_insights.php` are thin PHP shells:
they call `expensesDeskLoadReactAssets()` and render `includes/expenses-react-shell.php`, passing the
page key in `window.__EXPENSES_PAGE__` and the API base in `window.__EXPENSES_API_BASE__`. If the
React bundle has not been built they return **HTTP 503** with build instructions.

---

## 2. Directory layout

```
modules/expenses/
|-- index.php                 # Desk (React shell, page key "list")
|-- create.php                # Record expense (React shell)
|-- edit.php                  # Edit draft (React shell)
|-- import.php                # Bulk import (React shell)
|-- smart_insights.php        # Insights (React shell)
|-- view.php                  # Printable receipt / voucher (plain PHP)
|-- export.php                # Spreadsheet export (plain PHP)
|-- payees.php                # Payee CRUD (plain PHP)
|-- delete.php                # Soft delete (plain PHP)
|-- list.php                  # Legacy redirect to index.php
|-- api/
|   |-- desk-init.php         # Desk bootstrap: filters, KPIs, csrf token
|   |-- expenses.php          # Filtered expense list
|   |-- stats.php             # KPI / insight numbers
|   |-- kpi-confirm.php       # AI confirmation of a KPI figure
|   |-- create-init.php       # Form options for create
|   |-- edit-init.php         # Form options + existing draft values
|   |-- create-expense.php    # Insert (draft or posted)
|   |-- update-expense.php    # Update a draft
|   |-- delete-draft.php      # Delete a draft
|   |-- accounts.php          # Account options
|   |-- payees.php            # Payee list
|   |-- payee_options.php     # Payee autocomplete
|   |-- exchange_rate.php     # BoT rate lookup for a currency
|   |-- post_voucher.php      # Legacy posting endpoint (view.php)
|   |-- import-init.php       # Import bootstrap: accounts, currencies, template columns
|   |-- import-template.php   # CSV template download (4 columns)
|   |-- import-preview.php    # Upload + parse + validate (no writes)
|   |-- import-classify.php   # AI / heuristic expense-account suggestions
|   \-- import-commit.php     # Insert drafts (balances unchanged)
|-- includes/
|   |-- balances_integration.php  # Core: accounts, scope SQL, posting, list building
|   |-- import_helpers.php        # CSV/XLSX parsing, account matching, commit
|   |-- currency_helpers.php      # Currency catalog, TZS <-> TSh display
|   |-- category_helpers.php      # Category helpers
|   |-- expenses-lib.php          # Bootstrap, access check, React asset loader
|   |-- expenses-react-shell.php  # HTML shell for the React pages
|   \-- payee_options.php
\-- frontend/                 # Vite + React source and built bundle (dist/)
    \-- src/
        |-- App.jsx           # Routes on window.__EXPENSES_PAGE__
        |-- api/expensesDesk.js
        |-- pages/ExpensesDeskPage.jsx
        |-- pages/ExpenseCreatePage.jsx
        |-- pages/ExpenseForm.jsx
        |-- pages/ExpenseImportPage.jsx
        |-- pages/ExpenseSmartInsightsPage.jsx
        |-- expenses-desk.css
        \-- expenses-dark.css
```

`includes/balances_integration.php` is the file to read first -- almost every account rule,
SQL scope fragment and posting path lives there.

---

## 3. The account model

### One Chart of Accounts

There is a single chart of accounts: **`financial_accounts`**, owned by the Balances module
(`modules/balances`). The Expenses module reads it through
`expenses_fetch_financial_account_map()` and never writes to it directly.

Account `type` values are seeded by `balances_default_account_types()` in
`modules/balances/functions.php`:

| Type | Code range | Used by Expenses as |
|------|-----------|---------------------|
| `asset` | 1000-1999 | payment account |
| `cash` | 1000-1999 | payment account |
| `bank` | 1000-1999 | payment account |
| `mobile` | 1000-1999 | payment account |
| `liability` | 2000-2999 | -- |
| `equity` | 3000-3999 | -- |
| `revenue` | 4000-4999 | -- |
| `expense` | 5000-5999 | **expense account** |

Custom types can be added in `modules/balances/account_types.php`; they are stored in
`financial_account_types`.

### The two account fields on every expense

| Field | Points at | Meaning | Validated by |
|-------|-----------|---------|--------------|
| `account_id` | `financial_accounts.id` with type `expense` | **What** was bought (the account to debit) | `expenses_validate_expense_sub_account()` |
| `source_account_id` | `financial_accounts.id` with type `asset` / `cash` / `bank` / `mobile` | **Where the money came from** (the account to reduce) | `expenses_is_payment_account_type()` |

> **Legacy note:** `account_id` used to reference `erp_accounts` (a separate expense-category table).
> It now references `financial_accounts`. `expenses_ensure_schema()` actively **drops** any leftover
> foreign key from `erp_expenses.account_id` to `erp_accounts` on first use, so no manual migration
> is needed. One fallback remains: if `financial_accounts` has no `expense` account at all,
> `api/desk-init.php` falls back to listing `erp_accounts` rows of type `expense` as filter
> categories. Create real expense accounts in Balances and that path stops being used.

### Parent / child (hierarchical) charts

`expenses_build_expense_account_tree()` and `expenses_build_payment_account_tree()` split accounts
into `mains` (no parent) and `childrenByParent`. If **any** parent/child pair exists the chart is
treated as *hierarchical*, and then:

- the UI shows two dropdowns -- group first, then account;
- only **sub-accounts** may be selected. `expenses_validate_expense_sub_account()` rejects an account
  with `parent_id <= 0` in hierarchical mode, and rejects a child whose parent is not the selected
  group;
- display labels come from `expenses_account_row_label()`, which renders `Parent / Child` when a
  parent exists and the plain name otherwise.

If the chart is flat, a single dropdown lists every eligible account.

### Payment method

`payment_method` is stored on `erp_expenses` as **`cash`** or **`mobile_money`** only. Bank
transfers deliberately share the `mobile_money` value, and
`expenses_payment_method_label()` renders `cash`, `mobile_money`, `mobile`, `bank` and
`bank_transfer` as either `Cash` or `Bank Transfer`. See
[Known issues](#15-known-issues-and-gotchas).

### Currency

`currency_helpers.php` keeps a currency catalog (Bank of Tanzania rates when available).
`expenses_currency_display_code()` maps ISO `TZS` to the display code **`TSh`**, which is what gets
stored in `erp_expenses.currency_code` (column default is `TSh`).

---

## 4. Database tables

### `erp_expenses` -- the expense ledger

Live column list:

| Column | Type | Role |
|--------|------|------|
| `id` | int | PK |
| `company_id` | int null | Multi-company scope |
| `pv_id` | int null | Payment voucher link (voucher-sourced rows) |
| `source_type` | enum(`receipt`,`voucher`) default `receipt` | Origin of the row |
| `expense_number` | varchar(255) | Public ref, e.g. `EXP-20260803-001` |
| `date` | date | Expense date |
| `payee` | varchar(255) null | Who was paid |
| `account_id` | int null | Expense account -> `financial_accounts.id` |
| `source_account_id` | int null | Payment account -> `financial_accounts.id` |
| `amount` | decimal(10,2) | Gross amount (VAT inclusive) |
| `tax_amount` | decimal(15,2) default 0 | `amount - vat_exclusive` |
| `currency_code` | varchar(10) default `TSh` | Display currency code |
| `payment_method` | varchar(255) | `cash` or `mobile_money` |
| `description` | text | Narration |
| `attachment` | varchar(255) null | Receipt file path |
| `status` | enum(`draft`,`pending`,`approved`,`rejected`,`deleted`) default `pending` | Workflow state |
| `is_posted` | tinyint(1) default 0 | `1` once the ledger rows exist |
| `created_by` / `created_at` | int / timestamp | Audit |
| `approved_by` / `approved_at` | int / timestamp null | Audit |

Schema upgrades are automatic: `expenses_ensure_schema()` calls `ensureExpenseColumns()`, drops the
stale `erp_accounts` foreign key, widens the `status` enum to include `draft`
(`expenses_ensure_draft_status_enum()`), and adds the approval columns
(`expenses_ensure_approval_columns()`).

### The default list scope

Every desk query is wrapped in `expenses_scope_sql()`:

```sql
status != 'deleted'
AND (pv_id IS NULL OR pv_id = 0)
AND COALESCE(NULLIF(source_type, ''), 'receipt') <> 'voucher'   -- expenses_receipt_only_sql()
AND (expense_number NOT LIKE 'PC-%' AND expense_number NOT LIKE 'PCV-%' AND source_account_id NOT IN (<petty cash ids>))
                                                                -- expenses_exclude_petty_cash_sql()
```

So the desk shows **direct receipts only**: no soft-deleted rows, no voucher-sourced rows, and no
petty cash rows.

### Related tables

| Table | Owner | Role |
|-------|-------|------|
| `financial_accounts` | Balances | Chart of Accounts; holds `opening_balance` and `current_balance` |
| `account_transactions` | Balances | Every balance movement (`debit` / `credit`, `reference_type`, `reference_id`) |
| `financial_account_types` | Balances | Account type catalog |
| `payment_vouchers` | Vouchers | Employee vouchers; synced into `erp_expenses` with `pv_id` set |
| `petty_cash_vouchers` | Petty Cash | Petty cash records (moved out of `erp_expenses`) |
| `payees` | Expenses | Optional vendor master list |

---

## 5. Expense lifecycle: draft to posted

```
   create.php / import.php
            |
            v
   +-------------------+   status = 'draft', is_posted = 0
   |      DRAFT        |   editable, deletable, no ledger rows, no balance change
   +-------------------+
            |  post (create with post option, view.php, or import "Post to ledger immediately")
            v
   +-------------------+   is_posted = 1
   |      POSTED       |   two account_transactions rows written, balances recalculated
   +-------------------+   read-only from the desk
```

- **Draft** -- `expenses_editable_expense_sql_constraint()` is
  `is_posted = 0 AND LOWER(TRIM(status)) = 'draft'`. Only rows matching that can be opened in
  `edit.php` or removed via `api/delete-draft.php`.
- **Posted** -- once `is_posted = 1`, `expenses_is_editable_expense_row()` returns false and the desk
  offers view/print only.
- **Displayed status** is derived, not read raw:
  `expenses_resolve_list_display_status()` returns `draft` for `draft`/`pending`, and `posted`
  whenever `is_posted = 1` **or** matching rows already exist in `account_transactions`. That means
  `is_posted` is the effective source of truth.
- **Soft delete** sets `status = 'deleted'`, which `expenses_scope_sql()` filters out.
- **Legacy backfill runs on every desk load.** `api/desk-init.php` calls
  `expenses_backfill_pending_records()`, which finds receipt rows with `status = 'pending'` and
  `is_posted = 0`, sets them to `approved`, and posts them. Old pending rows therefore post
  themselves the first time someone opens the desk; failures are collected and skipped rather than
  aborting the request.

---

## 6. What posting writes to the ledger

Entry point: `expenses_post_erp_expense_row($pdo, $expenseId)` ->
`expenses_post_to_balances(...)` in `includes/balances_integration.php`.

Guards before anything is written:

1. amount must be greater than zero;
2. both accounts must resolve in `financial_accounts`;
3. the payment account type must be `asset` / `cash` / `bank` / `mobile`;
4. the expense account type must be `expense`;
5. `expenses_expense_already_posted()` (a `COUNT(*)` on `account_transactions` for
   `reference_type = 'expense'` and this `reference_id`) makes posting **idempotent**.

Then **two** `account_transactions` rows are written through `balancesRecordTransaction()`, both with
`type = 'debit'`, `reference_type = 'expense'` and `reference_id = <expense id>`:

| Row | Account | Description | Effect |
|-----|---------|-------------|--------|
| 1 | `source_account_id` (bank / cash / mobile) | `Payment: <description>` | money **out** of the wallet |
| 2 | `account_id` (expense) | `Expense #<id> <description>` | the expense account **grows** |

Finally `balancesRecalculateAccount()` recomputes both accounts. `recalculateBalance()` in
`modules/balances/functions.php` uses one formula for every account type:

```php
$newBalance = $opening + $inflow - $outflow;   // inflow = SUM(credit), outflow = SUM(debit)
```

Consequences worth knowing:

- A **payment account** goes down when an expense posts, which is what you want.
- An **expense account** accumulates debits, so its `current_balance` goes **negative** as spending
  grows. The Balances UI shows that raw value (red when negative); it is a magnitude, not an error.
- `expenses_mark_expense_posted()` sets `is_posted = 1` after a successful post.

Posting never touches `financial_accounts` directly -- always via `balancesRecordTransaction()`.

---

## 7. Where imported and posted expenses show up

| Surface | Reads from | Includes drafts? |
|---------|-----------|------------------|
| Expenses desk list (`index.php`) | `erp_expenses` under `expenses_scope_sql()` | Yes, labelled `draft` |
| Expenses KPIs (`api/stats.php`, `desk-init.php`) | `erp_expenses` with `is_posted = 1` | No |
| Export (`export.php`) | same filters as the desk list | Yes |
| Balances account balance | `financial_accounts.current_balance` | No -- only posted |
| Transaction Ledger (`modules/balances/transactions.php`) | `account_transactions` | No -- only posted |
| Analytics finance dashboard | `payment_vouchers`, falling back to a plain `SUM(amount)` over `erp_expenses` in range | **Yes** -- that fallback has no `is_posted` or `status` filter |

### Important: the accounting reports are a separate ledger

The formal statements under `accounting/` -- `profit-loss.php`, `trial-balance.php`,
`balance-sheet.php`, `cash-flow.php`, `ledger.php` -- read the **journal** tables
(`erp_accounts` + `erp_journal_entries` + `erp_journal_items`). For example `plFetchByType()` in
`accounting/profit-loss.php`:

```sql
SELECT a.name, COALESCE(SUM(ji.credit), 0) AS c, COALESCE(SUM(ji.debit), 0) AS d
FROM erp_accounts a
LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date BETWEEN ? AND ?
WHERE a.type = ?
```

The Expenses module posts to `account_transactions`, **not** to `erp_journal_items`. So an expense
posted (or imported and posted) from this module moves Balances account balances and appears in the
Transaction Ledger, but it does **not** appear on those journal-based statements unless a separate
`financial_accounts` -> `erp_accounts` journal sync runs. Treat Balances as the sub-ledger for
expenses and the `accounting/` screens as the GL view. `accounting/fa-gl-sync.php` only links
accounts; it does not copy expense transactions.

---

## 8. Petty cash is a separate module

Petty cash spending belongs to `modules/petty-cash` (table `petty_cash_vouchers`), not here. An
account counts as petty cash when its name matches `petty` --
`expenses_is_petty_cash_account_row()`:

```php
return $name !== '' && preg_match('/petty\s*cash|\bpetty\b/', $name) === 1;
```

The rule is enforced in four places:

1. **Dropdowns** -- `expenses_fetch_payment_accounts()` filters petty cash accounts out, so they
   cannot be picked on the create, edit or import screens.
2. **Create / update** -- `api/create-expense.php` and `api/update-expense.php` reject a petty cash
   `source_account_id` with *"Petty cash payments belong in the Petty Cash module, not Expenses."*
3. **Import** -- the same check runs per row in `expenses_import_commit_rows()`.
4. **Lists and KPIs** -- `expenses_exclude_petty_cash_sql()` hides rows funded by a petty cash
   account and rows numbered `PC-%` or `PCV-%`.

Legacy petty cash rows that predate the split are migrated out of `erp_expenses` into
`petty_cash_vouchers` by `petty_cash_migrate_expenses_from_erp()`, which runs from
`petty_cash_module_ensure_schema()`.

---

## 9. Bulk import (AI-assisted)

URL: `modules/expenses/import.php?module=expenses`
UI: `frontend/src/pages/ExpenseImportPage.jsx`
Logic: `includes/import_helpers.php`

### 9.1 The flow

1. **Download template** -- `api/import-template.php` serves a CSV with exactly four columns:
   `DATE`, `DESCRIPTION`, `AMOUNT`, `VAT EXCLUSIVE`.
2. **Upload file** -- drag and drop or browse. Accepted: `.xlsx`, `.csv`, `.txt`, max **10 MB**.
   Legacy `.xls` is rejected. `api/import-preview.php` parses and validates without writing.
3. **AI classify** -- the UI immediately calls `api/import-classify.php`, which maps each
   `DESCRIPTION` to an expense account via OpenAI when AI is configured
   (`balances_ai_is_connected()` + `ai_openai_request()`), otherwise via keyword heuristics.
   Suggestions are editable in the preview table.
4. **Paid from** -- the user picks **one** bank/cash account for the whole file (plus year and
   currency). AI does not choose Paid from.
5. **Import drafts** -- `api/import-commit.php` always inserts `status = 'draft'`, `is_posted = 0`.
   **Balances do not change.** Success text: *"Balances are unchanged until you post."*

```
Upload -> preview -> AI maps expense accounts -> user picks Paid from
  -> save drafts -> (later) post from desk -> balances update
```

### 9.2 Template columns

Defined in `expenses_import_template_columns()`:

| Column | Required | Contents |
|--------|----------|----------|
| `DATE` | **yes** | `7-Apr`, `07/04/2026`, `2026-04-07`, or an Excel serial |
| `EXPENSE ACCOUNT` | **yes** | Chart of Accounts expense account name (e.g. Fuel). Legacy headers `DESCRIPTION` and `EXPENSE SUB-ACCOUNT` are accepted the same way |
| `AMOUNT` | **yes** | Gross amount paid, VAT inclusive |
| `VAT EXCLUSIVE` | no | Net amount. `tax_amount = AMOUNT - VAT EXCLUSIVE` |

On preview, each expense account name is matched to `financial_accounts` and stored as `account_id`. The
matched account label is also saved as the expense narration (`description`). Unmatched names can
still be fixed in the preview (AI / heuristic assist, then manual dropdown). Import commits always
write that `account_id` onto the draft.

Extra columns from older sheets are still accepted if present, but the official download is four
columns only.

### 9.3 File parsing

Same as before: CSV/XLSX readers in `import_helpers.php`, header row detection requiring DATE /
DESCRIPTION / AMOUNT, number and date parsers for common formats.

### 9.4 AI classification

`expenses_import_ai_classify_rows()`:

1. Builds the expense-account catalog from `expenses_fetch_expense_sub_accounts()`.
2. If AI is enabled, batches rows (~40) into `ai_openai_request()` asking for JSON
   `{ row, account_id, confidence, reason }` using only catalog ids.
3. Validates every returned id against the catalog; unknown ids are discarded.
4. Logs usage with `ai_log_usage(..., 'expenses', 'import_classify', ...)`.
5. For rows still unmapped, runs `expenses_import_heuristic_classify_description()` (token / name
   overlap against account labels).
6. Returns rows with `account_id`, `account_label`, `ai_reason`, `ai_confidence`, and `via_ai`.

The user can override any suggestion in the preview dropdown before commit.

### 9.5 Row validation (preview)

| Error | Cause |
|-------|-------|
| `Invalid date (...)` | date missing or unparseable |
| `Missing description` | empty `DESCRIPTION` |
| `Invalid amount` | `AMOUNT` missing, zero or negative |
| `Invalid VAT exclusive amount` | negative `VAT EXCLUSIVE` |
| `VAT exclusive cannot be greater than amount` | net above gross |

Invalid rows are not imported; the rest still go through. Rows that are valid but have no
expense account yet must be assigned one before commit.

### 9.6 Commit (drafts only)

`expenses_import_commit_rows()` always forces `$postToLedger = false`. It requires:

- a global `source_account_id` (Paid from), not petty cash;
- each ready row to have a valid `account_id` (from AI, heuristic, or user override).

Rows are numbered `EXP-<YYYYMMDD>-NNN` and inserted as drafts inside one transaction. No
`account_transactions` are written, so bank/cash and expense-account balances stay unchanged
until the user posts later via the normal posting path (`expenses_post_erp_expense_row`).

### 9.7 Import endpoints

| Endpoint | Method | Body | Returns |
|----------|--------|------|---------|
| `api/import-init.php` | GET | -- | accounts, currencies, `template_columns`, `ai_available`, `csrf_token` |
| `api/import-template.php` | GET | -- | 4-column CSV template |
| `api/import-preview.php` | POST (multipart) | `file`, `csrf_token`, `default_year` | parsed `rows[]`, `summary` |
| `api/import-classify.php` | POST (JSON) | `csrf_token`, `rows[]` | rows with `account_id` / `ai_reason`, `via_ai` |
| `api/import-commit.php` | POST (JSON) | `csrf_token`, `rows[]`, `source_account_id`, `payment_method`, `currency` | `{ ok, imported, ids[], message }` (always drafts) |

---

## 10. API reference

Base path: `modules/expenses/api/`. Every endpoint calls `requireLogin()`; writes verify the CSRF
token. Errors come back as JSON with an `error` key (and `ok: false` on the newer endpoints).

### Desk and list

| Endpoint | Method | Notes |
|----------|--------|-------|
| `desk-init.php` | GET | KPI cards, filter options, CSRF token for the desk |
| `expenses.php` | GET | Filtered list. Query: `search`, `status`, `category`, `date_from`, `date_to`, `payment_method`, `amount_min`, `amount_max` |
| `stats.php` | GET | `month` optional. Totals, trends, category breakdown |
| `kpi-confirm.php` | POST | `?key=<kpi>&ai=1`, body `{ listedCount, filters }`. AI cross-check of a KPI |
| `accounts.php` | GET | Account options |
| `payees.php`, `payee_options.php` | GET | Payee list / autocomplete |
| `exchange_rate.php` | GET | `?currency=USD` -> BoT rate |

`status` accepts `posted` (`is_posted = 1`) and `unposted` / `approved`
(`is_posted = 0 AND status NOT IN ('draft','rejected','deleted')`); `payment_method=bank` matches
`bank_transfer`, `mobile_money`, `mobile` and `bank`.

### Create, edit, delete

| Endpoint | Method | Notes |
|----------|--------|-------|
| `create-init.php` | GET | Account trees, currencies, CSRF token |
| `edit-init.php` | GET | `?id=` plus the draft's current values |
| `create-expense.php` | POST (multipart) | Fields: `date`, `payee`, `account_id`, `main_account_id`, `source_account_id`, `amount`, `currency`, `payment_method`, `description`, `attachment`, draft flag. Rejects petty cash payment accounts |
| `update-expense.php` | POST (multipart) | Same fields plus `id`; drafts only. Rejects petty cash payment accounts |
| `delete-draft.php` | POST | `id`, `csrf_token`; drafts only |
| `post_voucher.php` | POST | Legacy posting used by `view.php`: `{ voucher_id, expense_id, source_account_id, category_id }` |

Both create and update support a "save as draft on leave" path -- the React form fires
`navigator.sendBeacon()` to the same endpoint so a half-finished entry is not lost.

---

## 11. Frontend (React) build

```bash
cd modules/expenses/frontend
npm install
npm run build      # writes dist/ ; the PHP shells read dist/index.html
npm run dev        # optional Vite dev server
```

`expensesDeskLoadReactAssets()` scrapes the hashed asset names out of `dist/index.html` and
cache-busts them with the file mtime, so **every UI change needs a rebuild** -- editing files under
`frontend/src/` alone changes nothing in the browser. If `dist/` is missing, the pages return
HTTP 503 with the build command.

Routing is not URL-based: each PHP shell sets `window.__EXPENSES_PAGE__` and `App.jsx` picks the
page component from it.

Styling lives in `frontend/src/expenses-desk.css` (plus `expense-create.css`), with dark mode
overrides in `expenses-dark.css` keyed on `html[data-theme="dark"] body.page-exp-desk`. Add a dark
rule for any new class you introduce.

---

## 12. Permissions and multi-company scope

- Every page and endpoint requires a session: `expensesDeskRequireAccess()` -> `requireLogin()`.
- Write endpoints verify `verify_csrf()`; the token is handed to the UI by the `*-init.php`
  endpoints.
- The app is multi-tenant: the company slug in the URL selects the tenant database.
  `currentCompanyId()` scopes queries, and `balancesUseCompanyScope()` decides whether
  `financial_accounts` / `account_transactions` filter on `company_id`.
- Posting passes the expense's `company_id` down to `balancesRecordTransaction()` so ledger rows stay
  in the right tenant.

---

## 13. Setup checklist

1. Enable the **expenses** and **balances** modules for the company.
2. In Balances, create the Chart of Accounts:
   - at least one `expense` account (code range 5000-5999) -- create sub-accounts under a parent such
     as `5000 - Expenses` if you want grouping;
   - at least one `bank`, `cash` or `mobile` account to pay from.
3. Build the React bundle (see [section 11](#11-frontend-react-build)).
4. Optional: enable the system AI assistant so import can classify descriptions via OpenAI
   (`balances_ai_is_connected()`). Without AI, keyword matching still suggests accounts.
5. Optional: add payees on `payees.php`.
6. Optional: keep petty cash out of this module by creating those accounts with `petty` in the name,
   and use `modules/petty-cash`.

`erp_expenses` column and enum migrations run automatically on first use.

---

## 14. Troubleshooting

| Symptom | Check |
|---------|-------|
| Page returns 503 "React UI has not been built" | run `npm run build` in `modules/expenses/frontend` |
| UI changes do not appear | same -- rebuild; the shell serves `dist/`, not `src/` |
| "API returned HTML instead of JSON" | the session expired; log in again |
| Expense account dropdown empty | no `financial_accounts` row with type `expense` and `status = 'active'`; in a hierarchical chart you must also pick the group first |
| Payment account dropdown empty | no active `bank` / `cash` / `mobile` / `asset` account, or the only ones are petty cash (filtered on purpose) |
| Payment account list missing an account | its name contains "petty" -- use the Petty Cash module |
| Balance did not change after recording | the expense is a **draft**; `is_posted` must be `1`. Check `account_transactions` for `reference_type = 'expense'` |
| Expense account balance is negative | expected -- expense accounts accumulate debits (see [section 6](#6-what-posting-writes-to-the-ledger)) |
| Expense missing from the desk list | `expenses_scope_sql()` hides `status = 'deleted'`, voucher rows (`pv_id`, `source_type = 'voucher'`) and petty cash rows |
| Expense missing from P&L / trial balance | expected -- those read the journal tables, not `account_transactions` (see [section 7](#7-where-imported-and-posted-expenses-show-up)) |
| KPIs lower than the list total | KPIs count posted rows only; the list also shows drafts |
| Import: AI left accounts blank | AI may be off, or descriptions do not match chart names -- pick accounts in the preview table |
| Import: "Select the Paid from..." | Step 3 requires one bank/cash account for the whole file |
| Import: balances did not change after import | Expected -- imports are drafts only; post from the desk to update balances |
| Import: legacy `.xls` rejected | re-save as `.xlsx` or `.csv` |
| Import: header row not found | the sheet needs `DATE`, `DESCRIPTION` and `AMOUNT` headers on one row |
| Import: day-month dates land in the wrong year | set the year in step 3 |

---

## 15. Known issues and gotchas

1. **`status = 'posted'` is not a valid enum value.** `expenses_mark_expense_posted()` runs
   `UPDATE erp_expenses SET is_posted = 1, status = 'posted'`, but the enum is
   `('draft','pending','approved','rejected','deleted')`. On MariaDB/MySQL **without**
   `STRICT_TRANS_TABLES` the status is silently written as an empty string while `is_posted = 1`;
   the UI is unaffected because `expenses_resolve_list_display_status()` derives the label from
   `is_posted`. Under **strict** SQL mode that UPDATE would throw, which would fail posting and roll
   back an import run with *Post to ledger immediately* enabled. Fix: add `posted` to the enum in
   `expenses_ensure_draft_status_enum()`.
2. **Bank transfers are stored as `mobile_money`.** `payment_method` only ever holds `cash` or
   `mobile_money`, and `expenses_payment_method_label()` renders the latter as "Bank Transfer". You
   cannot distinguish a bank transfer from a mobile money payment by `payment_method` alone -- use
   the payment account's type.
3. **Posted expenses do not reach the journal-based statements.** See
   [section 7](#7-where-imported-and-posted-expenses-show-up). There is no unified double-entry
   screen for the two `account_transactions` debit rows either; the closest view is the Balances
   Transaction Ledger.
4. **The analytics fallback counts drafts.** `analytics_sum_expenses()` falls back to
   `SELECT SUM(amount) FROM erp_expenses WHERE date BETWEEN ? AND ?` with no `is_posted` or `status`
   filter, so that dashboard can exceed the posted total.
5. **Petty cash detection is name-based.** Any account whose name contains "petty" is treated as
   petty cash. Renaming an account changes its behaviour, and account IDs are cached per request in
   `expenses_petty_cash_account_ids()`.
6. **`expenses_build_expense_account_tree()` caches statically per request.** Accounts created
   mid-request are not visible until the next one.
7. **Keep source files ASCII.** Several files in this module already carry mis-encoded bytes from
   earlier edits (a stray byte inside a regex character class once broke
   `expenses_import_account_key_variants()`). Prefer escapes such as `\x{2013}` over literal typographic
   characters in PHP string literals.
