# Ultimate ERP — Developer Reference & Accounting Module Guide

This document is designed for developers who are modifying, extending, or auditing the **Accounting & Financial Reporting** module of the Ultimate ERP platform.

---

## 1. Tech Stack & Architecture

- **Backend**: PHP 7.4–8.x (vanilla architecture).
- **Database Access**: PHP Data Objects (PDO) for secure, prepared SQL statements.
- **Frontend**: Bootstrap 5, Chart.js for data visualization, and Vanilla JS/CSS.
- **Tenant Routing**: Multi-company tenancy. Tenant database operations or filters are routed using company slugs (`?company_slug=...`) and the helper function `currentCompanyId()`.
- **Tenant-Safe Asset Loading**: Ensure all assets (CSS, JS, media) resolve correctly under company subfolders by utilizing the absolute path resolver:
  ```php
  <?= app_url('/assets/css/style.css') ?>
  ```

---

## 2. Global Directory Layout

```
public_html/
│
├── includes/                      # Core configuration and helpers
│   ├── functions.php              # Session management, authentication, tenant helpers
│   ├── ai_helpers.php             # Core OpenAI API request client & token logging
│   └── ai_assistant_helper.php    # Context queries for AI (vouchers, attendance, sales)
│
├── accounting/                    # <--- Accounting module files
│   ├── chart-of-accounts.php      # Displays active ledger accounts
│   ├── create-account.php         # Chart of accounts (COA) builder
│   ├── create-journal.php         # Manual double-entry journal ledger posting
│   ├── ledger.php                 # T-accounts ledger transaction history
│   ├── profit-loss.php            # Profit & Loss calculations (accrual basis)
│   ├── balance-sheet.php          # Assets, Liabilities, and Equity reporting
│   ├── trial-balance.php          # Debit/credit balance check audit sheet
│   └── posting-approvals.php      # Auditor posting authorization center
│
├── modules/
│   └── analytics/                 # Business Intelligence dashboards
│       ├── sales.php              # Accounts receivable, sales trend, debtors aging
│       ├── finance.php            # Expenses and cash flow trends
│       └── full_report.php        # AI Full System Audit & report visualizer
│
├── employee/                      # Employee portal & employee AI assistant
└── admin/                         # Admin portal, anomaly scans, and analytics desk
```

---

## 3. Database Schema: Accounting & Ledger Core

The accounting module uses a strict **Double-Entry Bookkeeping System**. Every transaction consists of a header (`erp_journal_entries`) and at least two line items (`erp_journal_items`) where the sum of debits equals the sum of credits.

### A. Chart of Accounts Table (`erp_accounts`)
Stores all ledger accounts.
- `id` (int, PK)
- `account_code` (varchar) — e.g., "1100" for Cash, "1200" for AR.
- `account_name` (varchar) — e.g., "Product Sales", "Accounts Receivable".
- `account_type` (enum) — `Asset`, `Liability`, `Equity`, `Revenue`, `Expense`.
- `company_id` (int) — Tenancy scope.
- `is_active` (tinyint) — Active status flag.

### B. Journal Entries Table (`erp_journal_entries`)
Stores transaction headers (audit trial metadata).
- `id` (int, PK)
- `entry_date` (date) — Transaction date.
- `narration` (text) — Description of the transaction.
- `reference_no` (varchar) — Optional invoice or voucher reference number.
- `status` (enum) — `draft`, `posted`. Only `posted` journals affect balances.
- `company_id` (int) — Tenancy scope.

### C. Journal Items Table (`erp_journal_items`)
Stores the actual debit and credit ledger rows.
- `id` (int, PK)
- `entry_id` (int, FK -> `erp_journal_entries.id`)
- `account_id` (int, FK -> `erp_accounts.id`)
- `debit` (decimal 15,2) — Debit amount (defaults to 0.00).
- `credit` (decimal 15,2) — Credit amount (defaults to 0.00).
- `company_id` (int) — Tenancy scope.

### D. Outstanding Invoices Table (`erp_outstanding_invoices`)
- `id` (int, PK)
- `type` (enum) — `receivable` (Customer debt), `payable` (Supplier bills).
- `invoice_date` (date)
- `entity_name` (varchar) — Customer or Supplier business name.
- `amount` (decimal 15,2)
- `status` (enum) — `outstanding`, `paid`.

---

## 4. Ledger Accounting Core Rules & Logic

Developers extending this module must adhere to these accounting principles:

### A. The Double-Entry Constraint
When creating or modifying journal transactions (specifically in `accounting/create-journal.php`), you must enforce:
$$\sum \text{debits} = \sum \text{credits}$$
No journal entry can transition from `draft` to `posted` if the debits and credits do not balance.

### B. Debit / Credit Effects by Account Type
Use the standard accounting equation rules for balances computation:
- **Assets & Expenses**: Balance increases with **Debits** (+Dr) and decreases with **Credits** (-Cr).
- **Liabilities, Equity, & Revenue**: Balance increases with **Credits** (+Cr) and decreases with **Debits** (-Dr).

### C. Accrual Revenue Recognition
The system recognizes revenue on the date an invoice is **POSTED** (Debit Accounts Receivable, Credit Product Sales), not when cash payment is received.
- **Profit & Loss calculations** (`accounting/profit-loss.php`) must query posted journal items matching `Revenue` and `Expense` accounts within the filtered date range.
- **Payments** (Debit Cash/Bank, Credit Accounts Receivable) affect the Balance Sheet, not the P&L statement.

---

## 5. Tenancy & Schema Safety Guidelines

To prevent cross-tenant data leaks and SQL crashes across dev/staging schema variations, all database operations must follow these constraints:

### A. Strict Company Tenancy Scoping
Always filter queries by the tenant company ID. Get the active context company ID:
```php
$companyId = (int) currentCompanyId();
```
Filter all SQL operations on tables carrying `company_id`:
```sql
SELECT * FROM erp_accounts WHERE company_id = :company_id AND is_active = 1
```

### B. Database Resiliency Checks
Before querying tables or columns that may differ between dev, staging, and production environments, use the schema safety helpers defined in `includes/ai_helpers.php`:
```php
// Check if table exists
if (tableExists('erp_outstanding_invoices', $pdo)) {
    // Run query safely
}

// Check if column exists inside a table
if (columnExists('invoices', 'due_date', $pdo)) {
    // Query due_date safely
}
```

### C. Audited Posting approvals
All manual journal postings should enter as status `draft` and require audit verification via `accounting/posting-approvals.php` before their values affect the Trial Balance, Ledger history, or General Ledger aggregates.
