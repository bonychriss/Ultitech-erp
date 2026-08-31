# Full Modules Guide

This file documents the main modules available from the app launcher at `select-module.php`.

## Module Index

| Module | Launcher URL | Main Path | Purpose | Access |
|---|---|---|---|---|
| Payment Voucher | `admin/dashboard.php?module=voucher` (admin) / `employee/dashboard.php?module=voucher` (employee) | `admin/`, `employee/` | Create, review, and approve payment vouchers. | Employee + Admin (role-based dashboards) |
| Attendance | `attendance/index.php?module=attendance` | `attendance/` | Employee sign-in/sign-out and attendance records. | Logged-in users |
| Delivery Logistics | `deliveries/index.php?module=deliveries` | `deliveries/` | Delivery operations, manifests, and proof-of-delivery workflows. | Logged-in users |
| Outstanding Invoices | `erp/outstanding-invoices/index.php?module=outstanding` | `erp/outstanding-invoices/` | Track unpaid invoices and debt balances. | Logged-in users |
| Customer Email | `modules/email/index.php?module=email` | `modules/email/` | Customer communication and email operations. | Logged-in users |
| Petty Cash | `erp/petty-cash/index.php?module=petty_cash` | `erp/petty-cash/` | Manage day-to-day petty cash entries and balances. | Logged-in users |
| Expenses | `modules/finance/index.php` | `modules/finance/`, `modules/expenses/` | Record expenses, approvals, posting, and financial tracking. | Finance/Admin for elevated actions |
| Payroll | `modules/payroll/index.php?module=payroll` | `modules/payroll/` | Salary setup, payroll runs, and payslips. | Payroll/Admin (demo mode may allow anonymous access) |
| Revenue & Debt | `revenue.php?module=revenue` | `revenue*.php`, `includes/revenue_ledger.php` | Revenue recording, receivables, collections, and debt tracking. | Finance/Admin |
| Accounting | `modules/accounting/index.php?module=accounting` | `modules/accounting/` | Journals, accounting records, and reporting support. | Finance/Admin |
| Balances | `modules/balances/index.php` | `modules/balances/` | Liquidity, accounts, transfers, and transaction views. | Logged-in users (some actions restricted) |
| Budgets | `modules/finance/budgets/index.php?module=finance` | `modules/finance/budgets/` | Budget planning, actuals, and variance tracking. | Finance/Admin only |
| Stock Management | `stock/dashboard.php?module=stocks` | `stock/` | Inventory, stock movements, and stock-related operations. | Logged-in users |
| Sales | `modules/sales/dashboard/index.php?module=sales` | `modules/sales/` | Quotes, orders, invoicing, and sales lifecycle. | Sales/Finance/Admin |
| Statement | `customer_statement/index.php?module=sales` | `customer_statement/` | Customer statements, outstanding balances, and exports. | Sales/Finance/Admin |
| Dispatch | `dispatch/index.php?module=dispatch` | `dispatch/` | Dispatch notes and outbound document flow. | Logged-in users |
| To-Do List | `todo/index.php` | `todo/` | Personal task management and tracking. | Logged-in users |
| General Settings | `admin/settings.php?module=settings` | `admin/settings.php` | Global application settings. | Admin |
| User Settings | `employee/system-settings.php?module=account` | `employee/system-settings.php` | Employee account/system preferences. | Non-admin users |
| Suggestions | `suggest.php` | `suggest.php` | Submit improvement ideas and requests. | Logged-in users |
| Reports | `reports/index.php` | `reports/` | Reporting dashboards and insights. | Logged-in users (report scope may vary by role) |
| Inbox (Admin) | `manage-letters.php` | `manage-letters.php` | Review and manage internal letters. | Admin |
| Write Letter (Employee) | `write-letter.php` | `write-letter.php` | Draft and submit official letters/requests. | Employee |
| Layout | `layout.php` | `layout.php` | UI layout/theme customization tools. | Logged-in users |

## Existing Module Docs

More detailed technical notes already exist for some modules:

- `modules/expenses/README.md`
- `modules/balances/README.md`
- `modules/payroll/README.md`
- `modules/sales/payments/README.md`

## Notes

- The launcher and visibility rules are defined in `select-module.php`.
- Some modules have role-based visibility (for example Admin-only and Finance-only items).
- A module may have multiple related entry points (for example `revenue.php`, `revenue_entries.php`, `revenue_payments.php`).
