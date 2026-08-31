# Stock Purchase Payment Workflow (Payment Voucher)

**Document purpose:** Planning and design guide for connecting stock purchases to Payment Vouchers, Supplier Payments, and accounting in this ERP.

**Status:** Phase 1 — workflow confirmation (no code in this document).

**Audience:** Developers, finance users, and project owners implementing or reviewing this module.

---

## Table of Contents

1. [Main Concept](#1-main-concept)
2. [Correct Workflow](#2-correct-workflow)
3. [Vendor Bill Logic](#3-vendor-bill-logic)
4. [Payment Voucher Logic](#4-payment-voucher-logic)
5. [Supplier Payment Logic](#5-supplier-payment-logic)
6. [Partial Payment Handling](#6-partial-payment-handling)
7. [Suggested Database Tables](#7-suggested-database-tables)
8. [Relationships](#8-relationships)
9. [Validation Rules](#9-validation-rules)
10. [UI Pages Needed](#10-ui-pages-needed)
11. [Posting Rules](#11-posting-rules)
12. [Example Scenario](#12-example-scenario)
13. [Implementation Plan](#13-implementation-plan)
14. [Safety Instructions](#14-safety-instructions)
15. [Final Notes](#15-final-notes)

---

## 1. Main Concept

In this ERP, **stock purchase payments** must follow accounting best practice: record the supplier obligation first, approve payment separately, then pay and post to the ledger.

### Three different documents (do not mix them up)

| Document | What it is | What it does to money |
|----------|------------|------------------------|
| **Vendor Bill / Supplier Bill / Supplier Invoice** | Records what the supplier is owed for goods/services received | Increases **Accounts Payable** (debt). Does **not** touch bank/cash. |
| **Payment Voucher** | Internal approval request to pay a supplier (against a bill) | **No** bank/cash movement while pending or approved. Only authorizes a future payment. |
| **Supplier Payment** | The actual payment transaction | Reduces **Accounts Payable** and reduces **Bank/Cash**. |

### Simple analogy

- **Vendor Bill** = “We owe the supplier TZS 1,000,000.”
- **Payment Voucher** = “We request approval to pay TZS 500,000 of that debt.”
- **Supplier Payment** = “We actually sent TZS 500,000 from the bank.”

### Payment Voucher is NOT a Vendor Bill

- A **Vendor Bill** is a **liability** document (supplier invoice).
- A **Payment Voucher** is an **approval and control** document linked to that liability.
- Treating a Payment Voucher as if it were the bill itself causes wrong balances, double posting, and bank balances changing too early.

---

## 2. Correct Workflow

End-to-end flow for stock purchases paid through the ERP:

```
Purchase Order (PO)
    ?
Goods Received Note (GRN) / Stock Received
    ?
Vendor Bill / Supplier Invoice
    ?
Payment Voucher
    ?
Supplier Payment
    ?
Journal Entry
    ?
Reconciliation
```

### What happens at each stage

| Stage | Business meaning | Stock impact | Accounting impact |
|-------|------------------|--------------|-------------------|
| **Purchase Order** | Commitment to buy from supplier (qty, price, terms) | None until goods arrive | Usually none (optional commitment tracking only) |
| **GRN / Stock Received** | Goods physically received into warehouse | **Inventory increases** (quantity on hand) | May be none until bill, or accrual depending on policy |
| **Vendor Bill / Supplier Invoice** | Supplier invoice recorded; debt recognized | Confirms cost/value of stock received (if not already valued) | **Debit** Inventory/Expense/Asset · **Credit** Accounts Payable |
| **Payment Voucher** | Request to pay supplier (full or partial) | None | **None** on bank/cash while draft, pending, or approved |
| **Supplier Payment** | Money leaves bank/cash | None | **Debit** Accounts Payable · **Credit** Bank/Cash |
| **Journal Entry** | Formal GL posting of payment | None | Links payment to chart of accounts |
| **Reconciliation** | Match bank statement to system payments | None | Confirms bank balance agrees with records |

### Key principle

**Obligation is recorded on the Vendor Bill.**  
**Approval is recorded on the Payment Voucher.**  
**Cash movement is recorded on the Supplier Payment.**

---

## 3. Vendor Bill Logic

When a **supplier invoice** is recorded (against PO/GRN as applicable):

### Business effects

- Confirms the amount owed to the supplier.
- Ties stock receipt to a financial liability.
- Starts the **Accounts Payable** balance for that supplier/bill.

### Accounting entry (standard stock purchase)

**Example — Supplier Bill Amount: TZS 1,000,000**

| Account | Debit | Credit |
|---------|------:|-------:|
| Inventory / Stock | 1,000,000 | |
| Accounts Payable | | 1,000,000 |

### What does NOT happen

- No reduction in bank or cash.
- No Payment Voucher is required yet.
- No Supplier Payment exists yet.

### Typical fields on a Vendor Bill (conceptual)

- Bill number (supplier invoice no.)
- Supplier
- Bill date, due date
- Currency, exchange rate (if multi-currency)
- Line items (product, qty, unit cost, tax)
- Links: `purchase_order_id`, `grn_id`
- `bill_amount`, `paid_amount`, `balance_due`, `payment_status`

---

## 4. Payment Voucher Logic

A **Payment Voucher** is created **against** a Vendor Bill (or linked purchase context). It is the document your organization already uses for payment approvals and printing.

### Purpose

- Formalize **who** is asking to pay **how much** to **which supplier**.
- Route through approval (requested by, checked by, approved by).
- Attach supporting documents (invoice copy, PO, GRN, etc.).
- Control **when** bank/cash is allowed to move.

### Suggested fields

| Field | Purpose |
|-------|---------|
| `voucher_number` | Unique reference (e.g. PV/UGC/2026/239) |
| `supplier_id` | Who will be paid |
| `vendor_bill_id` | Which bill this payment applies to |
| `purchase_order_id` | Optional traceability to PO |
| `grn_id` | Optional traceability to receipt |
| `requested_amount` | Amount user asks to pay |
| `approved_amount` | Amount approvers authorize (may differ after review) |
| `payment_method` | Bank transfer, cheque, cash, mobile money, etc. |
| `bank_or_cash_account_id` | GL/financial account to use when paid |
| `description` / `purpose` | Reason for payment |
| `requested_by` | Employee who initiated |
| `approved_by` | Final approver |
| `checked_by` | Intermediate checker (if workflow requires) |
| `status` | Workflow state (see below) |
| `company_id` | Multi-company isolation |

### Status lifecycle

| Status | Meaning | Bank/Cash affected? |
|--------|---------|---------------------|
| `draft` | Being prepared, not submitted | No |
| `pending_approval` | Submitted, awaiting approvers | No |
| `approved` | Approved to pay up to `approved_amount` | **No** |
| `rejected` | Not authorized; no payment | No |
| `paid` | Supplier Payment executed and linked | **Yes** (via Supplier Payment) |
| `cancelled` | Voided before or instead of payment | No |

### Critical rule

> **Pending or approved Payment Vouchers must NOT reduce bank/cash balance.**  
> Bank/cash reduces **only** when the voucher is marked **`paid`** and a **Supplier Payment** is posted.

### Link to existing Payment Voucher module

- Reuse existing voucher print layout, approval flow, and supporting documents unless a separate change is requested.
- Extend data model to store `vendor_bill_id`, `purchase_order_id`, `grn_id`, and payment amounts—not replace the voucher document concept.

---

## 5. Supplier Payment Logic

A **Supplier Payment** is the **actual payment** record. It should be created **only after** the Payment Voucher is **approved** and the organization executes payment (then mark voucher `paid`).

### When payment is made

**Example — paying TZS 500,000 against bill TZS 1,000,000**

| Account | Debit | Credit |
|---------|------:|-------:|
| Accounts Payable | 500,000 | |
| Bank / Cash (e.g. CRDB Bank) | | 500,000 |

### Effects on Vendor Bill

| Field | Value |
|-------|------:|
| `bill_amount` | 1,000,000 |
| `paid_amount` | 500,000 |
| `balance_due` | 500,000 |
| `payment_status` | `partially_paid` |

### One payment voucher ? one supplier payment (typical)

- Each approved Payment Voucher that is executed should generate **one** Supplier Payment (for the paid amount).
- That Supplier Payment generates **one** journal entry (header + lines).
- Avoid creating Supplier Payments without an approved Payment Voucher.

---

## 6. Partial Payment Handling

Suppliers are often paid in installments.

### Example

- Supplier bill: **TZS 1,000,000**
- First Payment Voucher (approved & paid): **TZS 500,000**
- Remaining: **TZS 500,000**

### System should store and display

| Field | After first payment |
|-------|--------------------:|
| `bill_amount` | 1,000,000 |
| `paid_amount` | 500,000 |
| `balance_due` | 500,000 |
| `payment_status` | `partially_paid` |

### Second payment

- New Payment Voucher for **TZS 500,000** (or remaining balance).
- After Supplier Payment posts:
  - `paid_amount` = 1,000,000
  - `balance_due` = 0
  - `payment_status` = `paid`

### Payment statuses (Vendor Bill)

| Status | Condition |
|--------|-----------|
| `unpaid` | `paid_amount` = 0 |
| `partially_paid` | 0 < `paid_amount` < `bill_amount` |
| `paid` | `paid_amount` >= `bill_amount` (and balance_due = 0) |
| `overpaid` | `paid_amount` > `bill_amount` (only if overpayment is allowed by policy) |

### Allocation rules

- Each Payment Voucher should specify how much of **which** Vendor Bill it settles.
- Sum of paid vouchers for a bill must not exceed `bill_amount` unless overpayment is explicitly allowed.
- `balance_due` should always be: `bill_amount - paid_amount` (adjusted for credits/returns if applicable).

---

## 7. Suggested Database Tables

**Note:** These are **design suggestions only**. Do not create migrations from this document alone. Phase 3 will compare with existing tables and add columns safely.

### `suppliers`

| Field | Purpose |
|-------|---------|
| `id` | Primary key |
| `company_id` | Tenant isolation |
| `name`, `code` | Supplier identity |
| `email`, `phone`, `address` | Contact |
| `currency_default` | Default trading currency |
| `payment_terms` | e.g. Net 30 |
| `status` | active / inactive |

### `purchase_orders`

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `po_number` | Human reference |
| `supplier_id` | Vendor |
| `order_date`, `expected_date` | Scheduling |
| `status` | draft, approved, received, closed, cancelled |
| `total_amount` | Order value |
| `currency` | PO currency |

### `goods_received_notes` (GRN)

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `grn_number` | Reference |
| `purchase_order_id` | Source PO |
| `supplier_id` | Who delivered |
| `received_date` | When stock entered |
| `warehouse_id` | Where stored |
| `status` | draft, posted, cancelled |

### `vendor_bills`

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `bill_number` | Supplier invoice number |
| `supplier_id` | Who invoiced |
| `purchase_order_id` | Optional PO link |
| `grn_id` | Optional receipt link |
| `bill_date`, `due_date` | Dates |
| `bill_amount` | Total liability |
| `paid_amount` | Sum of supplier payments |
| `balance_due` | Remaining owed |
| `payment_status` | unpaid, partially_paid, paid, overpaid |
| `currency`, `exchange_rate` | Multi-currency |
| `posted_at`, `posted_by` | GL posting audit |
| `status` | draft, posted, cancelled |

### `payment_vouchers`

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `voucher_number` | PV reference |
| `supplier_id` | Payee |
| `vendor_bill_id` | Bill being paid |
| `purchase_order_id`, `grn_id` | Traceability |
| `requested_amount`, `approved_amount` | Amounts |
| `payment_method` | How to pay |
| `bank_or_cash_account_id` | Account when paid |
| `description` | Purpose |
| `requested_by`, `checked_by`, `approved_by` | Workflow |
| `status` | draft, pending_approval, approved, rejected, paid, cancelled |
| `paid_at`, `supplier_payment_id` | Link when executed |

*May overlap with existing `payment_vouchers` table—Phase 2 will map columns.*

### `supplier_payments`

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `payment_number` | SP reference |
| `supplier_id` | Payee |
| `vendor_bill_id` | Bill settled |
| `payment_voucher_id` | Source approval |
| `payment_date` | Value date |
| `amount` | Paid amount |
| `bank_or_cash_account_id` | Source of funds |
| `payment_method` | Transfer, cheque, etc. |
| `journal_entry_id` | Posted GL entry |
| `reference_no` | Bank ref / cheque no. |
| `status` | draft, posted, void |

### `journal_entries`

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `entry_number` | JE reference |
| `entry_date` | Posting date |
| `source_type` | vendor_bill, supplier_payment, etc. |
| `source_id` | Link to originating record |
| `description` | Narration |
| `status` | draft, posted, reversed |
| `posted_by`, `posted_at` | Audit |

### `journal_entry_lines`

| Field | Purpose |
|-------|---------|
| `id` | Primary key |
| `journal_entry_id` | Parent |
| `account_id` | Chart of accounts |
| `debit`, `credit` | Amounts (one side zero) |
| `description` | Line memo |
| `company_id` | Tenant |

### `accounts` (chart of accounts / financial accounts)

| Field | Purpose |
|-------|---------|
| `id`, `company_id` | Identity & tenant |
| `code`, `name` | Account label |
| `type` | asset, liability, equity, income, expense |
| `subtype` | inventory, accounts_payable, bank, cash |
| `currency` | Account currency |
| `current_balance` | Optional sub-ledger balance (if maintained) |

---

## 8. Relationships

```
suppliers (1) ??????< (many) vendor_bills
suppliers (1) ??????< (many) payment_vouchers
suppliers (1) ??????< (many) supplier_payments

purchase_orders (1) ??< (many) goods_received_notes
purchase_orders (1) ??< (0..many) vendor_bills

goods_received_notes (1) ??< (0..1) vendor_bill   [often one bill per GRN]

vendor_bills (1) ??????< (many) payment_vouchers
vendor_bills (1) ??????< (many) supplier_payments

payment_vouchers (1) ??< (0..1) supplier_payment   [one payment per executed voucher]

supplier_payments (1) ??< (1) journal_entry

journal_entries (1) ??< (many) journal_entry_lines
```

### Cardinality summary

| From | To | Relationship |
|------|-----|--------------|
| Supplier | Vendor bills | One-to-many |
| Vendor bill | Payment vouchers | One-to-many (partial payments) |
| Payment voucher | Supplier payment | One-to-one when paid |
| Supplier payment | Journal entry | One-to-one |
| Purchase order | Vendor bills | One-to-many (partial billing allowed) |
| GRN | Vendor bill | Many-to-one or one-to-one (per policy) |

---

## 9. Validation Rules

Implement these checks in application logic (and optionally DB constraints):

### Amount and balance

1. **Payment Voucher `requested_amount` / `approved_amount` must not exceed `vendor_bill.balance_due`** unless overpayment is explicitly allowed by company settings.
2. **Sum of paid amounts** on a bill (from Supplier Payments) must not exceed `bill_amount` unless overpayment is allowed.
3. **Vendor Bill `balance_due`** must recalculate after every Supplier Payment:  
   `balance_due = bill_amount - paid_amount` (± adjustments).

### Status and workflow

4. **Payment Voucher cannot be marked `paid` unless status is `approved`** (or equivalent final approval state).
5. **Supplier Payment cannot be created** without an approved Payment Voucher (except admin override with audit log, if ever allowed).
6. **Rejected or cancelled Payment Vouchers** must not create Supplier Payments or journal entries.
7. **Draft Payment Vouchers** must not affect Accounts Payable or bank balances.

### Accounts

8. **`bank_or_cash_account_id` is required** before marking Payment Voucher as paid or posting Supplier Payment.
9. **Vendor Bill must be `posted`** before Payment Voucher can be submitted for approval (configurable grace period for draft bills if needed).

### Accounting integrity

10. **No double posting:** Vendor Bill posts AP once; each Supplier Payment reduces AP once—never post full bill amount again at payment time.
11. **Cancelled Vendor Bills** cannot accept new Payment Vouchers or Supplier Payments.
12. **All records must include `company_id`** and queries must filter by current company context.

---

## 10. UI Pages Needed

### A. Vendor Bills Page

**Goals:** Record supplier invoices and show liability status.

| Screen | Features |
|--------|----------|
| List | Filter by supplier, status, date, PO; columns: bill no., supplier, amount, paid, balance, status |
| Create | Select supplier, PO/GRN, line items, tax, due date; save draft or post |
| View | Bill details, linked PO/GRN, payment history, balance due, payment status |
| Actions | Post bill, cancel (if no payments), print |

### B. Payment Voucher Page

**Goals:** Request and approve payment against a bill (integrate with existing voucher UI).

| Screen | Features |
|--------|----------|
| Create from bill | Pre-fill supplier, bill balance, PO/GRN refs |
| Approval workflow | draft ? pending ? approved / rejected |
| Print | Existing voucher print layout preserved |
| Attachments | Invoice, PO, GRN, bank details |
| Mark as paid | Triggers Supplier Payment + JE (after approval) |

### C. Supplier Payments Page

**Goals:** Audit trail of actual cash outflows.

| Screen | Features |
|--------|----------|
| List | Filter by supplier, bill, date, bank account |
| View | Amount, voucher link, journal entry, bank reference |
| Actions | Void/reverse (with permissions and reversal JE) |

### D. Supplier Statement Page

**Goals:** Supplier-facing or internal statement of account.

- Opening balance (if tracked)
- Vendor bills (debits to AP / increases owed)
- Payments (credits / reductions)
- Running balance per supplier
- Export PDF/Excel

### E. Accounts Payable Aging Report

**Goals:** Finance control of unpaid supplier balances.

- Buckets: Current, 1–30, 31–60, 61–90, 90+ days
- Based on `due_date` and `balance_due`
- Filter by supplier, company
- Total outstanding AP

---

## 11. Posting Rules

### When Vendor Bill is posted (one-time per bill)

```
Debit:  Inventory / Stock (or Expense/Asset per line type)
Credit: Accounts Payable
```

- Increases stock value on hand (if not already valued at GRN).
- Creates supplier liability.

### When Supplier Payment is posted (per payment)

```
Debit:  Accounts Payable
Credit: Bank / Cash
```

- Reduces what you owe the supplier.
- Reduces bank/cash balance.

### What NOT to post

| Event | Do NOT post |
|-------|-------------|
| Purchase Order created | AP or Bank (unless policy requires commitment) |
| GRN / stock received only | AP (wait for bill, unless accrual policy) |
| Payment Voucher approved | Bank/Cash |
| Payment Voucher pending | Bank/Cash |

### Avoid double posting

- **Wrong:** Debit Inventory again when paying.  
- **Correct:** Only Debit AP and Credit Bank on payment.

---

## 12. Example Scenario

### Parties and documents

| Item | Value |
|------|-------|
| Supplier | ABC Supplies |
| Purchase Order | PO-001 |
| GRN | GRN-001 |
| Vendor Bill | INV-001 |
| Bill Amount | TZS 1,000,000 |
| Payment Voucher | PV-001 |
| Payment Amount | TZS 500,000 |
| Payment Account | CRDB Bank |
| Remaining Balance | TZS 500,000 |

### Step-by-step

**1. Purchase Order (PO-001)**  
- Status: Approved  
- Stock/accounting: No GL impact yet  

**2. GRN (GRN-001)**  
- Goods received into warehouse  
- Stock quantity increases  
- Accounting: Per policy—either no JE yet, or accrual (document policy in Phase 2)  

**3. Vendor Bill (INV-001) — Posted**  

| Account | Debit | Credit |
|---------|------:|-------:|
| Inventory | 1,000,000 | |
| Accounts Payable | | 1,000,000 |

- `bill_amount` = 1,000,000  
- `paid_amount` = 0  
- `balance_due` = 1,000,000  
- `payment_status` = **unpaid**  

**4. Payment Voucher (PV-001)**  
- `requested_amount` = 500,000  
- `approved_amount` = 500,000  
- Status: **approved**  
- Bank balance: **unchanged**  

**5. Supplier Payment (from PV-001) — Posted**  

| Account | Debit | Credit |
|---------|------:|-------:|
| Accounts Payable | 500,000 | |
| CRDB Bank | | 500,000 |

- Vendor Bill: `paid_amount` = 500,000, `balance_due` = 500,000  
- `payment_status` = **partially_paid**  
- Payment Voucher status: **paid**  

**6. Later — PV-002 for remaining 500,000**  
- Same flow ? after payment: `paid_amount` = 1,000,000, `balance_due` = 0, `payment_status` = **paid**  

---

## 13. Implementation Plan

### Phase 1 — Documentation (current)

- [x] Create this README and confirm workflow with stakeholders.
- [ ] Sign-off from finance + development lead.

### Phase 2 — Discovery

- Review existing tables: `payment_vouchers`, `purchases`, `stocks_purchase_orders`, financial accounts, approvals.
- List missing columns (`vendor_bill_id`, `paid_amount`, `balance_due`, etc.).
- Map existing Payment Voucher fields to proposed model.
- Document gaps in stock module vs finance module.

### Phase 3 — Database (safe migrations)

- Add tables/columns with migrations only.
- **Do not drop** production tables.
- Default new columns nullable where needed for legacy rows.
- Backfill scripts for historical data (optional, separate task).

### Phase 4 — Vendor Bill module

- CRUD + post/cancel.
- Link PO and GRN.
- Posting to Inventory + AP.

### Phase 5 — Payment Voucher integration

- Create PV from Vendor Bill.
- Enforce approval workflow and statuses.
- Preserve existing print layout and attachments.

### Phase 6 — Supplier Payment posting

- Mark voucher paid ? create Supplier Payment ? journal entry.
- Update bill `paid_amount`, `balance_due`, `payment_status`.

### Phase 7 — Reports

- Supplier statement.
- Accounts payable aging.

### Phase 8 — End-to-end testing

- Full scenario (PO ? GRN ? Bill ? PV ? Payment).
- Partial payments, rejections, cancellations.
- Multi-company isolation tests.
- Regression: existing vouchers, stock uploads, company uploads.

---

## 14. Safety Instructions

Before any database or production change:

1. **Do not drop existing production tables** or rename columns without migration path.
2. **Use safe, incremental migrations** (add column ? backfill ? enforce NOT NULL later).
3. **Backup database** before applying migrations in staging/production.
4. **Do not change** existing Payment Voucher **print layout** unless explicitly requested.
5. **Do not modify** existing stock upload paths, `company_uploads`, or tenant file storage conventions.
6. **Preserve `company_id`** on all new and linked records; every query must scope by company.
7. **Test on copy** of production data before go-live.
8. **Feature flags** optional: enable Vendor Bill workflow per company during rollout.

---

## 15. Final Notes

### For beginner developers

1. Remember the three layers: **Bill (debt) ? Voucher (approval) ? Payment (cash)**.  
2. If bank balance changes when only approving a voucher, the design is wrong.  
3. Partial payments are normal—always update `paid_amount` and `balance_due` on the bill.  
4. When in doubt, ask finance which account to debit/credit before coding posting logic.

### Integration with existing ERP

- **Stock module:** PO, GRN, purchases (`stock/modules/purchases`, etc.).
- **Payment Voucher module:** Existing employee/admin voucher views and approval flow.
- **Finance:** Financial accounts, mark-paid patterns, journal entries (align with existing `markVoucherPosted` / finance patterns where applicable).

### Next step after Phase 1 approval

Proceed to **Phase 2**: inventory of existing schema and a short gap analysis document (can be a section appended to this README or a separate `SCHEMA-GAP-STOCK-PAYMENT.md`).

---

**Document version:** 1.0  
**Last updated:** 2026-05-23  
**Author:** ERP development planning (Stock Purchase Payment workflow)
