# ERP Workflow Documentation

This document outlines the standard workflow for the **Sales** and **Accounting** modules in your ERP system.

## 1. Sales Workflow

The sales process flows from an initial Invoice creation to final Payment.

### Step 1: Create Invoice
- **Navigate**: Sales > Invoices > New Invoice
- **Action**: Select a Customer and add Products (Items).
- **Status**: The invoice starts in **Draft** status.
- **Outcome**: A new invoice record is created. Stock levels for products are automatically reduced.

### Step 2: Post Invoice (Confirm Sale)
- **Navigate**: Open the Draft Invoice.
- **Action**: Click the **Post** button.
- **Status**: Changes from **Draft** to **Posted**.
- **Important**: This confirms the debt. The Invoice is now "Open" and awaiting payment.

### Step 3: Register Payment
- **Navigate**: Open the Posted Invoice.
- **Action**: Click **Register Payment**.
- **Status**: Changes from **Posted** to **Paid**.
- **Outcome**: The invoice balance is set to 0.

---

## 2. Accounting Workflow

Currently, the Accounting module operates semi-independently. You need to manually record the financial impact of your sales.

### Recording Sales (Revenue)
- **When**: After Posting an Invoice.
- **Action**: Go to **Accounting > Journal Entries > New Entry**.
- **Entry**:
    - **Debit**: Accounts Receivable (Total Invoice Amount).
    - **Credit**: Product Sales / Income Account (Subtotal).
    - **Credit**: Tax Payable (Tax Amount).

### Recording Payments
- **When**: After Registering a Payment on an Invoice.
- **Action**: Go to **Accounting > Journal Entries > New Entry**.
- **Entry**:
    - **Debit**: Bank or Cash Account.
    - **Credit**: Accounts Receivable.

## Summary of Interaction

| Action | Sales Module Status | Accounting Action Required |
| :--- | :--- | :--- |
| **Create** | Draft | None |
| **Post** | Posted | Manually create "Sales Invoice" Journal Entry |
| **Pay** | Paid | Manually create "Payment Received" Journal Entry |
