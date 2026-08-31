# Multi-Company Phase 1 Inventory (Main Root App)

This inventory is scoped to the main runtime at `c:\xampp\htdocs\staff\` and is used by the Phase 2 migration.

## Tenant-Control Tables (new)

- `companies`
- `company_settings`
- `company_modules`
- `document_sequences`

## Business Tables Requiring `company_id`

### Identity and access
- `users`

### Vouchers and expenses
- `payment_vouchers`
- `voucher_items`
- `approval_logs`
- `voucher_attachments`
- `erp_expenses`
- `expenses_categories`
- `expenses_requests`
- `expenses_reports`
- `expenses_history`

### Sales and receivables
- `customers`
- `sales_orders`
- `sales_order_items`
- `invoices`
- `payments`
- `sales_payments`
- `delivery_notes`
- `delivery_items`
- `sales_commissions`
- `sales_reassignments`
- `sales_targets`
- `sales_notification_log`

### Procurement, suppliers, inventory
- `suppliers`
- `purchases`
- `purchase_items`
- `products`
- `product_images`
- `stock`
- `stock_movements`
- `stock_reservations`
- `stock_intakes`
- `categories`
- `stocks_categories`
- `stocks_items`
- `stocks_suppliers`
- `stocks_supplier_items`
- `stocks_purchase_orders`
- `stocks_po_items`
- `stocks_transactions`

### Accounting and balances
- `financial_accounts`
- `account_transactions`
- `erp_accounts`
- `erp_journal_entries`
- `erp_journal_items`
- `erp_account_categories`
- `erp_reporting_groups`
- `erp_tax_rates`
- `erp_bank_accounts`
- `erp_bank_transactions`
- `erp_bank_reconciliations`

### Revenue and debt
- `revenue_entries`
- `revenue_collections`
- `revenue_customers`

### Payroll and attendance
- `employee_salary`
- `payroll_runs`
- `payslips`
- `payslip_items`
- `attendance`
- `attendance_records`
- `attendance_settings`

### Cash and petty cash
- `petty_cash_vouchers`
- `petty_cash_replenishments`
- `petty_cash_balance`

## Global / platform-level tables (no `company_id` in this phase)

- `companies`
- `company_settings`
- `company_modules`
- `document_sequences`
- Pure platform metadata tables not storing business transactions

## Notes

- Migration adds `company_id` as nullable first.
- Existing rows are backfilled to default company (`Ultimate General Trading`) before later hardening to `NOT NULL`.
- Queries must be updated to include `company_id` filters in all CRUD operations.
