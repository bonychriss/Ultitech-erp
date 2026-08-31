# Accounting & Financial Reporting README

This document explains the workflow for **Journal Entries** and how they flow into your **Profit & Loss (P&L)** statement.

## 1. The Core Concept: Double-Entry Bookkeeping

Every financial transaction in the system is recorded as a **Journal Entry**. A Journal Entry consists of at least two "line items" (debits and credits) that must balance (Total Debit = Total Credit).

- **Debits** increase Assets and Expenses.
- **Credits** increase Liabilities, Equity, and Revenue.

## 2. Journal Entry Workflow

Journal Entries (JEs) are the source of truth for all reports.

### A. Automated Entries (from Sales)
When you use the Sales module, the system automatically creates JEs for you:

1.  **Invoice Posting**:
    - **Dr** Accounts Receivable (Asset)
    - **Cr** Product Sales (Revenue)
    - **Cr** Tax Payable (Liability)
    
2.  **Payment Registration**:
    - **Dr** Bank (Asset)
    - **Cr** Accounts Receivable (Asset)

### B. Manual Entries
For other transactions (e.g., Expenses, Salaries, Capital Injection), you must create manual JEs.

- **Navigate**: Accounting > Journal Entries > New Entry
- **Example: Paying Rent**
    - **Dr** Rent Expense (Expense Account)
    - **Cr** Bank (Asset Account)

## 3. Profit & Loss Statement (Income Statement)

The Profit & Loss statement tells you how much money you made or lost over a period.

- **Navigate**: Accounting > Profit & Loss

### Revenue Recognition (Important!)
Your P&L uses **Accrual Accounting Principles**.

- **When is Revenue Recognized?**
    - Revenue is recognized the moment you **POST** an Invoice.
    - It does **NOT** wait for the customer to pay.
    - This gives you a true picture of "Sales made this month" regardless of when cash arrives.

- **Do Paid Invoices appear in P&L?**
    - **YES**. But they appeared the moment they were Posted.
    - Marking an invoice as "Paid" creates a journal entry affecting the **Balance Sheet** (Cash increases, Accounts Receivable decreases). It does **not** change the Revenue (P&L) amount, because the sale was already recorded.

### Period Filtering
The P&L is always calculated for a specific time window (e.g., "This Month", "Year to Date").
- Make sure you are viewing the correct period.
- An invoice posted in January will appear in the January P&L, even if paid in February.

### How it is calculated
1.  **Revenue**: Sum of CREDITS to 'Revenue' accounts.
2.  **Expenses**: Sum of DEBITS to 'Expense' accounts.
3.  **Net Income** = Total Revenue - Total Expenses.

## 4. Troubleshooting

**"Not Balanced" Error**:
- Total Debits must equal total Credits. You cannot save an unbalanced manual entry.

**"Invoice not showing in P&L"**:
- Ensure the Invoice status is at least **Posted**. Drafts are ignored.
- Check the **Date** of the Invoice relative to the P&L period you are viewing.

## 5. Future Enhancements

We are planning the following improvements to the accounting engine:

1.  **Per-Product Revenue Accounts**:
    - Ability to map different products to different accounts (e.g., "Service Revenue" vs "Hardware Sales") instead of a single global "Product Sales" account.
    
2.  **Purchase Automation**:
    - Automatically create Expense Journals when a Purchase Order is billed.
    
3.  **Advanced Accrual Accounting**:
    - Handling deferred revenue and prepaid expenses.
