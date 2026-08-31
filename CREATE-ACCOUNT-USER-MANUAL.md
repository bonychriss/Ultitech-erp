# Create Account - User Manual

**Module:** Balances > Chart of Accounts > Add New Account  
**Purpose:** This guide explains how to fill in the **Create Account** form when adding a new account to your company Chart of Accounts (COA).

---

## Before You Start

- You need permission to create accounts (typically **Finance** or **Admin**).
- Use clear, consistent account names so reports and payment screens are easy to understand.
- When in doubt, ask your Finance team before entering an **opening balance**.

---

## 1. Account Information

This section defines the **identity** of the account: what it is called, what type it is, and how it behaves in the ledger.

### Account Code

- The system **assigns this automatically**. You do not need to type it.
- The code is generated from the **account type series** (number blocks used to organize accounts).
- **Examples:** `1000`, `1100`, `2000`, `4000`
- **Typical code ranges:**
  - `1000-1999` - Assets (including cash, bank, inventory, receivables)
  - `2000-2999` - Liabilities
  - `3000-3999` - Equity
  - `4000-4999` - Revenue
  - `5000-5999` - Expenses
- **Do not change the code manually** unless your system administrator has enabled custom codes and Finance has approved it.

### Account Name

- This is the **display name** of the account used across the ERP.
- Choose a name that staff will recognize immediately.
- **Examples:**
  - CRDB Bank Account
  - Cash Account
  - Accounts Receivable
  - Accounts Payable
  - Inventory Account
  - Sales Revenue
  - Office Expenses

### Account Type

- This is the **main accounting classification** of the account.
- It affects reports, normal balance, and how transactions are grouped.

| Type | What it means | Simple examples |
|------|----------------|-----------------|
| **Asset** | Things the company **owns** or is **owed** | Bank, cash, inventory, receivables |
| **Liability** | Money the company **owes** | Payables, loans, unpaid supplier bills |
| **Equity** | Owner's stake in the business | Owner capital, retained earnings |
| **Revenue** | **Income** from business activity | Sales income, service fees |
| **Expense** | **Costs** of running the business | Rent, transport, salaries, office costs |

Additional types such as **Cash**, **Bank**, and **Mobile** may appear for payment and treasury accounts. Treat them as asset-related accounts unless Finance advises otherwise.

### Parent Account

- Use this when the new account should sit **under** an existing account in the chart hierarchy.
- **Example:**
  - **Parent Account:** Bank Accounts
  - **Account Name:** CRDB Bank Account
- If the account stands alone, select **None**.

### Account Sub Type

- This adds **more detail** about how the account is used.
- **Examples:**
  - Accounts Receivable
  - Accounts Payable
  - Bank
  - Cash
  - Inventory
  - Operating Expense
  - Sales Income

### Normal Balance

- This shows whether the account **normally increases** on the **Debit** or **Credit** side.
- Getting this wrong can break reports and trial balance checks.

**Simple rule:**

| Account type | Normal balance |
|--------------|----------------|
| Assets | **Debit** |
| Expenses | **Debit** |
| Liabilities | **Credit** |
| Equity | **Credit** |
| Revenue | **Credit** |

### Description

- Optional short explanation of what the account is for.
- **Example:** *This account is used to track CRDB bank transactions.*

---

## 2. Classification

This section controls **how the account appears in financial reports** (Balance Sheet, Profit & Loss, and management reports).

### Account Category

- Groups the account into **financial statement categories**.
- **Examples:**
  - Current Assets
  - Fixed Assets
  - Current Liabilities
  - Long-term Liabilities
  - Income
  - Operating Expenses
  - Cost of Goods Sold

### Reporting Group

- Determines **where the account appears** in structured reports.
- **Examples:**
  - Current Assets
  - Bank and Cash
  - Accounts Receivable
  - Accounts Payable
  - Revenue
  - Expenses

Choose a reporting group that matches how Finance wants the account summarized on the Balance Sheet or Profit & Loss.

### Currency

- The **currency used by this account**.
- **Example:** TZS - Tanzanian Shilling
- For foreign currency bank or trading accounts, select **USD** or another supported currency if your company uses multi-currency accounts.

---

## 3. Opening Balance

Use this section when the account **already has a balance** before you start using the ERP (for example, during go-live or migration).

If the account is **brand new** with no prior balance, leave the opening balance at **0.00** or empty.

### Opening Balance

- The **starting amount** for the account.
- **Examples:**
  - CRDB Bank Account: `5,000,000`
  - Cash Account: `500,000`
  - New account with no prior balance: `0.00`

> **Important:** Opening balances affect your books. Only enter amounts you are sure about, and coordinate with Finance so debits and credits remain balanced across all opening entries.

### Opening Date

- The **date** from which the opening balance is effective.
- **Example:** 24/05/2026
- Use the company's official cut-over or migration date unless Finance instructs otherwise.

### Reference

- A short code to **identify the opening balance entry** in records and audit trails.
- **Examples:**
  - OB-001
  - BANK-OPENING-001
  - AR-001

### Notes

- Optional **internal note** for staff (not shown to customers).
- **Example:** *Opening balance entered during system setup.*

---

## 4. Account Preview

This section shows a **summary** of what you are about to save.

Before saving, confirm:

- Account Name
- Account Type
- Normal Balance
- Parent Account
- Category
- Currency
- Opening Balance (if any)

If anything looks wrong, go back and correct the form. The preview should match what you expect to see on reports and in account lists.

---

## 5. Account Settings

These switches control **what users can do** with the account after it is created.

### Allow Manual Journals

- When **ON**, the account can be used in **manual journal entries** (adjustments posted by Finance).
- **Often allowed for:**
  - Office Expenses
  - Bank Charges
  - Sales Revenue
  - Accounts Payable
  - Accounts Receivable
- Some **system-controlled accounts** may need this **OFF** to prevent manual changes. Follow your company policy or ask Finance/Admin.

### Allow Reconciliation

- When **ON**, the account can be **reconciled** (matched against bank statements, cash counts, or sub-ledgers).
- **Recommended for:**
  - Bank accounts
  - Cash accounts
  - Mobile money accounts
- Turn **OFF** for accounts that are not reconciled (for example, pure revenue or expense summary accounts).

### Track Budget

- When **ON**, the account can be included in **budget tracking and variance reports**.
- **Recommended for:**
  - Office Expenses
  - Marketing Expenses
  - Procurement Expenses
  - Transport Expenses
  - Department expense accounts
- Usually **OFF** for balance sheet accounts such as bank, receivables, and payables.

---

## 6. Audit Information

This section is **filled automatically by the system**. You do not edit it.

| Field | Meaning |
|-------|---------|
| **Created By** | User who created the account |
| **Created On** | Date and time of creation |
| **Last Modified By** | User who last changed the account (if applicable) |
| **Last Modified On** | Date and time of last change (if applicable) |

This supports internal control and audit review.

---

## 7. Practical Examples

### Example 1: CRDB Bank Account

| Field | Value |
|-------|--------|
| Account Name | CRDB Bank Account |
| Account Type | Asset |
| Parent Account | Bank Accounts |
| Account Sub Type | Bank |
| Normal Balance | Debit |
| Account Category | Current Assets |
| Reporting Group | Bank and Cash |
| Currency | TZS |
| Opening Balance | 5,000,000 |
| Opening Date | 24/05/2026 |
| Reference | BANK-001 |
| Allow Manual Journals | Yes |
| Allow Reconciliation | Yes |
| Track Budget | No |

**Use:** Track money held in the CRDB bank account and reconcile against bank statements.

---

### Example 2: Accounts Receivable

| Field | Value |
|-------|--------|
| Account Name | Accounts Receivable |
| Account Type | Asset |
| Account Sub Type | Accounts Receivable |
| Normal Balance | Debit |
| Account Category | Current Assets |
| Reporting Group | Accounts Receivable |
| Currency | TZS |
| Opening Balance | 0.00 |
| Allow Manual Journals | No |
| Allow Reconciliation | Yes |
| Track Budget | No |

**Use:** Tracks **money customers owe the company** (outstanding invoices and credit sales).

---

### Example 3: Accounts Payable

| Field | Value |
|-------|--------|
| Account Name | Accounts Payable |
| Account Type | Liability |
| Account Sub Type | Accounts Payable |
| Normal Balance | Credit |
| Account Category | Current Liabilities |
| Reporting Group | Accounts Payable |
| Currency | TZS |
| Opening Balance | 0.00 |
| Allow Manual Journals | No |
| Allow Reconciliation | Yes |
| Track Budget | No |

**Use:** Tracks **money the company owes suppliers** (unpaid bills and purchase invoices).

---

### Example 4: Inventory Account

| Field | Value |
|-------|--------|
| Account Name | Inventory / Stock Account |
| Account Type | Asset |
| Account Sub Type | Inventory |
| Normal Balance | Debit |
| Account Category | Current Assets |
| Reporting Group | Inventory |
| Currency | TZS |
| Opening Balance | 0.00 |
| Allow Manual Journals | No |
| Allow Reconciliation | No |
| Track Budget | No |

**Use:** Tracks the **value of stock items** held by the company.

---

### Example 5: Sales Revenue Account

| Field | Value |
|-------|--------|
| Account Name | Sales Revenue |
| Account Type | Revenue |
| Account Sub Type | Sales Income |
| Normal Balance | Credit |
| Account Category | Income |
| Reporting Group | Revenue |
| Currency | TZS |
| Opening Balance | 0.00 |
| Allow Manual Journals | Yes |
| Allow Reconciliation | No |
| Track Budget | No |

**Use:** Records **income from sales** of goods or services.

---

### Example 6: Office Expenses Account

| Field | Value |
|-------|--------|
| Account Name | Office Expenses |
| Account Type | Expense |
| Account Sub Type | Operating Expense |
| Normal Balance | Debit |
| Account Category | Operating Expenses |
| Reporting Group | Expenses |
| Currency | TZS |
| Opening Balance | 0.00 |
| Allow Manual Journals | Yes |
| Allow Reconciliation | No |
| Track Budget | Yes |

**Use:** Records **office-related costs** such as stationery, utilities, and admin supplies.

---

## 8. Simple Accounting Rules

Use these quick rules when choosing account type and name:

| Situation | Account to use |
|-----------|----------------|
| Customer owes us money | **Accounts Receivable** (Asset) |
| We owe supplier money | **Accounts Payable** (Liability) |
| Money in bank or cash | **Asset** account (Bank / Cash) |
| Value of stock on hand | **Inventory** (Asset) |
| Income from sales | **Revenue** account |
| Day-to-day business costs | **Expense** account |

---

## 9. Common Mistakes to Avoid

- **Do not** create a bank account as **Expense**.
- **Do not** create **Accounts Payable** as **Asset**.
- **Do not** create **Sales Revenue** as **Asset**.
- **Do not** enter an **opening balance** if you are not sure of the amount or date.
- **Do not** enable **reconciliation** for accounts that are not bank, cash, or mobile money (unless Finance requires it).
- **Do not** allow **manual journals** on sensitive system-controlled accounts without **Finance/Admin** approval.
- **Always check normal balance** before saving (Debit vs Credit).

---

## 10. Final Checklist Before Saving

Before clicking **Save Account**, confirm:

- [ ] Account name is correct and easy to understand
- [ ] Account type is correct (Asset, Liability, Equity, Revenue, or Expense)
- [ ] Normal balance is correct (Debit or Credit)
- [ ] Category and reporting group match how Finance wants reports
- [ ] Currency is correct (usually TZS)
- [ ] Opening balance and opening date are correct (or left empty/zero for new accounts)
- [ ] Account settings (manual journals, reconciliation, budget) are suitable
- [ ] **Account Preview** looks correct

If all items are checked, save the account. After saving, verify it appears on the **Chart of Accounts** list and test a sample transaction if your role allows.

---

## Need Help?

- Contact your **Finance team** for account naming standards, opening balances, and report grouping.
- Contact **System Admin** if you cannot access the Create Account screen or if account codes behave unexpectedly.

---

*Document: CREATE-ACCOUNT-USER-MANUAL.md - Chart of Accounts (Create Account form)*
