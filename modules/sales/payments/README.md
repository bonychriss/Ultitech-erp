# Sales Invoice Payment Page

## Page

- URL: `http://localhost/staff/modules/sales/payments/create.php?invoice_id={id}`
- File: `modules/sales/payments/create.php`
- Purpose: record a customer payment against an existing sales invoice.

## What This Page Does

This page lets a user register payment for one invoice and updates related records in one flow:

1. Loads invoice details by `invoice_id`.
2. Lets user enter payment details (amount, date, deposit account, reference, notes).
3. Saves payment record into `sales_payments`.
4. Updates invoice `amount_paid` and `status` (`paid` or `partial`).
5. Marks linked sales order as `paid` when invoice becomes fully paid.
6. Records a credit transaction into selected financial account.
7. Syncs the invoice to revenue ledger via `syncInvoiceToRevenueLedger(...)`.

## Access and Bootstrapping

- Includes:
  - `../../../includes/config.php`
  - `../functions.php`
  - `../../../modules/balances/functions.php`
  - `../../../includes/revenue_ledger.php`
- Starts session if needed.
- Uses `$_SESSION['user_id']` as `created_by` (falls back to `1` if missing in current implementation).

## Required Query Parameter

- `invoice_id` (GET, required)
  - If missing: request ends with `Invoice ID missing.`
  - If invalid/not found: request ends with `Invoice not found.`

## Form Fields

- `amount` (number, required, max = current invoice balance)
- `payment_date` (date, required)
- `account_id` (select, required) -> financial account to deposit funds
- `reference` (text, optional)
- `notes` (textarea, optional)

## Database Behavior

### Reads

- `invoices` + `customers` for invoice header.
- `financial_accounts` for deposit account list and selected account metadata.

### Writes

- Ensures table exists:
  - `sales_payments` (`CREATE TABLE IF NOT EXISTS ...`)
- Inserts a row into `sales_payments`.
- Updates `invoices.amount_paid` and `invoices.status`.
- Optionally updates `sales_orders.status = 'paid'` (when fully settled).
- Writes account transaction through `recordTransaction(...)`.

## Invoice Status Logic

- `newAmountPaid = existing amount_paid + posted amount`
- `newBalance = total_amount - newAmountPaid`
- If `newBalance <= 0.01`: status becomes `paid`
- Else: status becomes `partial`

## Redirects and Messages

- On success:
  - sets `$_SESSION['success'] = "Payment registered successfully."`
  - redirects to `../invoices/view.php?id={invoice_id}`
- On failure:
  - rolls back transaction
  - shows error alert on page

## Notes for Maintainers

- The page currently creates `sales_payments` table at runtime. In production, prefer a migration-managed schema.
- Currency symbol shown in UI is currently `$` on this page; update if you want consistency with `TZS` displays used elsewhere.
- If stricter access control is needed, re-enable and enforce auth checks at the top (currently commented placeholders exist).
