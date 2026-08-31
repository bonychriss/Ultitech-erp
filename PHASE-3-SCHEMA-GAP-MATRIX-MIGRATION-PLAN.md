# Phase 3 — Schema Gap Matrix & Migration Plan

**Document type:** Planning only (no code, no migrations, no SQL execution)  
**Status:** Phase 3 — schema design and safe migration strategy  
**Sources:**

- `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md` (target workflow)
- `CURRENT-SYSTEM-AUDIT-STOCK-PURCHASE-PAYMENTS.md` (Phase 2 accepted audit)

**Rule:** This document defines *what* to build and *how* to migrate safely. Implementation begins only after approval of this plan.

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [Current tables vs required tables matrix](#2-current-tables-vs-required-tables-matrix)
3. [Payment Voucher extension plan](#3-payment-voucher-extension-plan)
4. [Vendor Bills table plan](#4-vendor-bills-table-plan)
5. [Vendor Bill Items table plan](#5-vendor-bill-items-table-plan)
6. [Supplier Payments table plan](#6-supplier-payments-table-plan)
7. [Supplier payment allocations](#7-supplier-payment-allocations)
8. [Journal posting plan](#8-journal-posting-plan)
9. [Existing mark-paid.php transition plan](#9-existing-mark-paidphp-transition-plan)
10. [Backward compatibility plan](#10-backward-compatibility-plan)
11. [Multi-company safety](#11-multi-company-safety)
12. [Migration order](#12-migration-order)
13. [Testing scenarios](#13-testing-scenarios)
14. [Final recommendation](#14-final-recommendation)

---

## 1. Executive summary

The current ERP already provides substantial building blocks:

| Capability | Current state |
|------------|----------------|
| **Payment Vouchers** | Mature: create, multi-step approval, print, attachments, mark paid, `is_paid` / `is_posted` flags |
| **Stock purchase orders** | Modern `stocks_purchase_orders` + legacy `purchases`; partial link to PV via `linked_stock_po_id` |
| **Stock receiving** | Via `stocks_po_items.qty_received` and `stocks_transactions` (no GRN table) |
| **Bank / cash (liquidity)** | `financial_accounts` + `account_transactions`; debited on mark paid |
| **General ledger** | `erp_accounts`, `erp_journal_entries`, `erp_journal_items` (posting paths inconsistent) |
| **Operational expenses** | `erp_expenses` synced from some paid vouchers |
| **Multi-company** | `company_id` on many tables via migration; enforcement partial |

What is **missing** for the target stock-purchase-payment workflow:

| Gap | Impact |
|-----|--------|
| **`vendor_bills`** | No place to record supplier invoice and **Accounts Payable** liability |
| **`vendor_bill_items`** | No line-level link between stock cost and bill/AP posting |
| **`supplier_payments`** | No official payment entity; cash impact is implicit on PV + `recordTransaction` |
| **AP subledger** | AP exists only as a **COA account concept**, not as bill balances (`paid_amount`, `balance_due`) |
| **Clean PV ? bill ? payment chain** | PV can link to PO (`linked_stock_po_id`) but not to a bill; paying PV does not reduce bill balance |
| **Single posting path** | Risk of double bank debit (`mark-paid.php`, `post_voucher.php`, admin GL) |

**Target model (unchanged from README):**

- **Vendor Bill** = records supplier debt (Dr Inventory / Cr AP when posted).
- **Payment Voucher** = approval/control only until paid (no bank on approve).
- **Supplier Payment** = actual cash movement (Dr AP / Cr Bank when posted).

Phase 3 defines **nullable, additive** schema changes and a **migration sequence** that does not drop production tables or break existing general-purpose vouchers.

---

## 2. Current tables vs required tables matrix

Legend:

- **Exists:** confirmed in codebase / schema dumps / runtime `ensure*`
- **Risk:** Low = additive nullable columns; High = posting logic / data migration / FK constraints

### 2.1 Existing and related tables

| Table | Exists now? | Purpose now | Missing columns / gaps | Recommended action | Risk |
|-------|-------------|-------------|------------------------|-------------------|------|
| **`payment_vouchers`** | Yes | Approval document + payee payment; `is_paid`, `is_posted`, print fields | `vendor_bill_id`, `supplier_id`, `approved_amount`, `supplier_payment_id`, `journal_entry_id`, `grn_id`, `payment_reference_no` | **ALTER** add nullable columns (§3) | **Medium** (many consumers) |
| **`voucher_items`** | Yes | PV line breakdown (budget type, amount) | No bill line link | No change initially; bill lines live on `vendor_bill_items` | Low |
| **`approvals`** | Yes | Line approver signatures | No change | None | Low |
| **`approval_logs`** | Yes | PV action audit | No change | None | Low |
| **`voucher_attachments`** | Yes | Supporting docs | Optional `company_id` if missing | Verify + add `company_id` if absent | Low–Medium |
| **`stocks_purchase_orders`** | Yes | Modern PO header | `vendor_bill_id` (optional back-link), consistent `company_id` filters | **ALTER** optional `default_vendor_bill_id`; enforce company scope in PHP | Medium |
| **`stocks_po_items`** | Yes | PO lines, `qty_ordered` / `qty_received` | Bill line linkage | Use `vendor_bill_items` when bill created from PO | Low |
| **`stocks_transactions`** | Yes | Stock in/out audit (`reference_type=purchase_order`) | Link to `vendor_bill_id` optional later | None initially | Low |
| **`stocks_suppliers`** | Yes | Modern supplier master | Map to `vendor_bills.supplier_id` | Align with legacy `suppliers` over time | Medium |
| **`suppliers`** | Yes | Legacy supplier master (stock UI) | Same | Consolidation strategy (Phase 4+), not Phase 3 | Medium |
| **`purchases`** | Yes | Legacy PO | No vendor bill | Deprecate gradually; no Phase 3 dependency | Low |
| **`purchase_items`** | Yes | Legacy PO lines | No vendor bill | Same | Low |
| **`financial_accounts`** | Yes | Bank/cash/mobile balances | None for AP | Keep as payment source on `supplier_payments` | Low |
| **`account_transactions`** | Yes | Liquidity ledger (debit/credit) | Should reference `supplier_payment_id` not only `payment_voucher` | **ALTER** optional `supplier_payment_id`; keep `reference_type` | Medium |
| **`erp_accounts`** | Yes | GL chart of accounts | AP as account type, not subledger | Use for JE lines on bill post / payment post | Low |
| **`erp_journal_entries`** | Yes | GL journal headers | Consistent `source_type` for `vendor_bill`, `supplier_payment` | Standardize posting service | Medium |
| **`erp_journal_items`** | Yes | GL journal lines | None | None | Low |
| **`erp_expenses`** | Yes | Operational expense from vouchers/receipts | Overlap with stock AP payment | **Do not** treat as AP clearance for stock bills; branch logic in mark-paid | **High** |
| **`shipments`** | Yes | Import logistics; `stocks_po_id` | GRN as separate entity | Optional `goods_received_notes` later | Low |

### 2.2 Target tables (new)

| Table | Exists now? | Purpose (target) | Recommended action | Risk |
|-------|-------------|------------------|-------------------|------|
| **`vendor_bills`** | **No** | Supplier invoice; AP liability; `paid_amount`, `balance_due`, `payment_status` | **CREATE** (§4) | Medium |
| **`vendor_bill_items`** | **No** | Bill lines; stock cost ? inventory/AP split | **CREATE** (§5) | Medium |
| **`supplier_payments`** | **No** | Official cash payment against bill | **CREATE** (§6) | Medium |
| **`supplier_payment_allocations`** | **No** | Split one payment across bills | **Defer** (§7); not required for v1 | Low if deferred |
| **`goods_received_notes`** | **No** (logic only) | Formal GRN document | **Defer**; use `stocks_po_items` + `stocks_transactions` for v1 | Low |

### 2.3 Relationship matrix (target vs current)

| Relationship | Current | Target |
|--------------|---------|--------|
| PO ? Vendor Bill | Not modeled | 1 PO ? 0..n bills |
| GRN ? Vendor Bill | Receipt columns only | Optional `grn_id` on bill later |
| Vendor Bill ? Payment Voucher | Missing | 1 bill ? 0..n PVs (partial pays) |
| Payment Voucher ? Supplier Payment | `is_paid` flag only | 1 PV ? 0..1 SP (when executed) |
| Supplier Payment ? Journal | Inconsistent / missing | 1 SP ? 1 JE |
| Vendor Bill ? Supplier Payment | Missing | 1 bill ? n payments (via SP) |

---

## 3. Payment Voucher extension plan

**Principle:** Add columns only. **Do not remove** `payee_name`, `payee_id`, `total_amount`, `is_paid`, `is_posted`, `linked_stock_po_id`, `payment_account_id`, `swift_document`, or existing status enum values.

All new columns should be **NULL allowed** at migration time for backward compatibility.

### 3.1 Proposed new columns on `payment_vouchers`

| Column | Type (suggested) | Purpose | Optional now? | Required later (when) |
|--------|------------------|---------|---------------|------------------------|
| **`vendor_bill_id`** | `INT UNSIGNED NULL` | Links PV to supplier bill being paid | Yes (NULL) | **Yes** for stock-purchase PVs (configurable) |
| **`supplier_id`** | `INT UNSIGNED NULL` | Normalized supplier FK (vs text `payee_name`) | Yes | Recommended when `vendor_bill_id` set |
| **`requested_amount`** | `DECIMAL(15,2) NULL` | Amount user requests to pay | Yes | If `total_amount` must remain print total; can mirror `total_amount` on create |
| **`approved_amount`** | `DECIMAL(15,2) NULL` | Amount approvers authorize | Yes | **Yes** before mark paid (default = `total_amount`) |
| **`grn_id`** | `INT UNSIGNED NULL` | Traceability to receipt | Yes | Optional; defer if no GRN table |
| **`supplier_payment_id`** | `INT UNSIGNED NULL` | Back-link after payment executed | Yes | **Yes** when paid (prevents double pay) |
| **`payment_execution_status`** | `VARCHAR(20) NULL` | e.g. `unpaid`, `scheduled`, `paid`, `void` | Yes | Optional; can derive from `is_paid` + `supplier_payment_id` initially |
| **`journal_entry_id`** | `INT UNSIGNED NULL` | GL entry for payment leg (if PV triggers JE) | Yes | Optional; prefer JE on `supplier_payments` |
| **`payment_reference_no`** | `VARCHAR(64) NULL` | Bank transfer / cheque reference | Yes | Recommended at mark paid |
| **`payment_purpose`** | `VARCHAR(40) NULL` | e.g. `general`, `stock_purchase`, `expense` | Yes (may overlap `purpose` runtime column) | Helps branch mark-paid logic |

**Note:** `purpose` and `linked_stock_po_id` may already exist via runtime `ensureVoucherStockPurchaseSchema()`. Phase 3 migration should **consolidate** into formal migration files without dropping runtime ensures until deploy is stable.

### 3.2 Columns to keep unchanged (compatibility)

| Column | Why keep |
|--------|----------|
| `payee_name`, `payee_id` | Printed on voucher; legacy vouchers |
| `total_amount` | Print + existing reports |
| `status` (`confirming`, `pending`, `approved`, `rejected`) | Existing workflow |
| `is_paid`, `paid_by`, `paid_at` | Existing finance UI |
| `is_posted`, `posted_by`, `posted_at` | Existing bookkeeping flag |
| `payment_account_id` | Existing mark-paid account picker |
| `swift_document` | Existing SWIFT proof path |
| `linked_stock_po_id` | Existing stock link |
| `company_id` | Multi-company (add if missing) |

### 3.3 Indexes (after columns exist)

| Index | Columns | Reason |
|-------|---------|--------|
| `idx_pv_vendor_bill_id` | `vendor_bill_id` | List PVs per bill |
| `idx_pv_supplier_id` | `supplier_id` | Supplier queries |
| `idx_pv_supplier_payment_id` | `supplier_payment_id` | Idempotency / joins |
| `idx_pv_company_status` | `company_id`, `status` | List filters |

**Foreign keys:** Add only after backfill strategy defined. Prefer **application-level** integrity first, then FKs on staging.

### 3.4 Validation rules (application layer, post-migration)

| Rule | Scope |
|------|--------|
| If `vendor_bill_id` IS NOT NULL ? `approved_amount` ? `vendor_bills.balance_due` (unless overpayment allowed) | Stock purchase PV |
| If `vendor_bill_id` IS NULL ? existing rules (general voucher) | Legacy |
| Cannot mark paid if `supplier_payment_id` already set | All |
| `approved_amount` ? `total_amount` unless override role | All |

---

## 4. Vendor Bills table plan

**Table name:** `vendor_bills`

**Purpose:** Record supplier invoice (supplier debt). This is **not** a Payment Voucher. Posting this document increases **Accounts Payable** and recognizes inventory/expense cost.

### 4.1 Column specification

| Column | Type | Purpose |
|--------|------|---------|
| **`id`** | `INT UNSIGNED PK AI` | Primary key |
| **`company_id`** | `INT UNSIGNED NOT NULL` | Tenant isolation; required on every query |
| **`supplier_id`** | `INT UNSIGNED NOT NULL` | Who invoiced us (`stocks_suppliers.id` or mapped legacy `suppliers.id`) |
| **`bill_number`** | `VARCHAR(50) NOT NULL` | Internal document number (system-generated, e.g. `VB-UGC-2026-0001`) |
| **`supplier_invoice_number`** | `VARCHAR(100) NULL` | Supplier’s invoice no. (e.g. `INV-001`) |
| **`purchase_order_id`** | `INT UNSIGNED NULL` | Optional link to `stocks_purchase_orders.id` |
| **`linked_stock_po_id`** | `INT UNSIGNED NULL` | Duplicate-friendly link if PO id stored on both sides; same as `purchase_order_id` in v1 |
| **`grn_id`** | `INT UNSIGNED NULL` | Future formal GRN; NULL in v1 |
| **`bill_date`** | `DATE NOT NULL` | Invoice date |
| **`due_date`** | `DATE NULL` | Payment due date (aging) |
| **`currency`** | `VARCHAR(3) NOT NULL DEFAULT 'TZS'` | Bill currency |
| **`exchange_rate`** | `DECIMAL(12,6) NOT NULL DEFAULT 1` | To base currency if needed |
| **`subtotal`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Before tax |
| **`tax_amount`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Total tax |
| **`total_amount`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Bill gross / liability amount |
| **`paid_amount`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Sum of posted supplier payments |
| **`balance_due`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | `total_amount - paid_amount` (+/- adjustments); maintained by app |
| **`payment_status`** | `ENUM('unpaid','partially_paid','paid','overpaid') NOT NULL DEFAULT 'unpaid'` | Derived from amounts |
| **`status`** | `ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft'` | Document lifecycle |
| **`posted_by`** | `INT UNSIGNED NULL` | User who posted bill to GL |
| **`posted_at`** | `DATETIME NULL` | When posted |
| **`journal_entry_id`** | `INT UNSIGNED NULL` | GL entry from bill posting (Dr Inventory / Cr AP) |
| **`notes`** | `TEXT NULL` | Internal notes |
| **`created_by`** | `INT UNSIGNED NULL` | Creator |
| **`created_at`** | `TIMESTAMP` | Created |
| **`updated_at`** | `TIMESTAMP` | Updated |

### 4.2 Uniqueness constraints

| Constraint | Definition |
|------------|------------|
| Unique bill number per company | `UNIQUE (company_id, bill_number)` |
| Optional unique supplier invoice | `UNIQUE (company_id, supplier_id, supplier_invoice_number)` where invoice number not null |

### 4.3 Status behavior

| `status` | Meaning | Accounting |
|----------|---------|------------|
| `draft` | Editable, not in AP | None |
| `posted` | Finalized liability | Dr Inventory/Expense Cr AP (once) |
| `cancelled` | Voided | Reversal JE policy (Phase 6+) |

### 4.4 `payment_status` (derived)

| Value | Condition |
|-------|-----------|
| `unpaid` | `paid_amount = 0` |
| `partially_paid` | `0 < paid_amount < total_amount` |
| `paid` | `paid_amount >= total_amount` and `balance_due <= 0` |
| `overpaid` | `paid_amount > total_amount` (only if policy allows) |

---

## 5. Vendor Bill Items table plan

**Table name:** `vendor_bill_items`

**Purpose:** Line-level detail on a vendor bill. Links **stock items** (or free-text lines) to amounts that roll into **`subtotal`** / **`total_amount`** and drive **inventory vs expense** GL accounts on posting.

### 5.1 Column specification

| Column | Type | Purpose |
|--------|------|---------|
| **`id`** | `INT UNSIGNED PK AI` | Primary key |
| **`vendor_bill_id`** | `INT UNSIGNED NOT NULL` | Parent bill |
| **`company_id`** | `INT UNSIGNED NOT NULL` | Tenant isolation (denormalized for safe queries) |
| **`stock_item_id`** | `INT UNSIGNED NULL` | `stocks_items.id` when stock purchase |
| **`product_id`** | `INT UNSIGNED NULL` | Legacy `products.id` if needed for old stack |
| **`description`** | `VARCHAR(255) NULL` | Line description if no stock item |
| **`quantity`** | `DECIMAL(15,4) NOT NULL DEFAULT 0` | Qty billed |
| **`unit_cost`** | `DECIMAL(15,4) NOT NULL DEFAULT 0` | Unit price |
| **`tax_rate`** | `DECIMAL(8,4) NOT NULL DEFAULT 0` | % tax |
| **`tax_amount`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Line tax |
| **`line_total`** | `DECIMAL(15,2) NOT NULL DEFAULT 0` | Extension (qty × unit_cost + tax) |
| **`account_id`** | `INT UNSIGNED NULL` | `erp_accounts.id` for GL (inventory vs expense); NULL = default stock account |
| **`po_item_id`** | `INT UNSIGNED NULL` | Optional link to `stocks_po_items.id` |
| **`sort_order`** | `INT NOT NULL DEFAULT 0` | Display order |
| **`created_at`** | `TIMESTAMP` | Audit |

### 5.2 How this links stock cost to accounting

1. **Operational:** PO receive increases `stocks_items.stock_quantity` (already today).
2. **Financial:** Vendor Bill records what we owe for those goods.
3. **On post:** Each line (or bill total) maps to:
   - **Debit:** Inventory asset account (or expense if non-stock line) via `account_id` or company default.
   - **Credit:** Accounts Payable control account.
4. **Payment Voucher** does not repeat inventory debit — only clears AP when **Supplier Payment** posts.

---

## 6. Supplier Payments table plan

**Table name:** `supplier_payments`

**Purpose:** Official record that **cash/bank left the company** to settle (part of) a vendor bill. This replaces the informal combination of `is_paid` + `account_transactions` as the long-term source of truth for stock supplier payments.

**Payment Voucher** remains the **approval and control** document (signatures, print, supporting docs). **Supplier Payment** is the **execution** document.

### 6.1 Column specification

| Column | Type | Purpose |
|--------|------|---------|
| **`id`** | `INT UNSIGNED PK AI` | Primary key |
| **`company_id`** | `INT UNSIGNED NOT NULL` | Tenant isolation |
| **`payment_number`** | `VARCHAR(50) NOT NULL` | e.g. `SP-UGC-2026-0001` |
| **`supplier_id`** | `INT UNSIGNED NOT NULL` | Payee supplier |
| **`vendor_bill_id`** | `INT UNSIGNED NOT NULL` | Which bill is being paid down |
| **`payment_voucher_id`** | `INT UNSIGNED NULL` | Source PV (NULL if direct payment without PV — policy decision: disallow in v1) |
| **`payment_date`** | `DATE NOT NULL` | Value date |
| **`amount`** | `DECIMAL(15,2) NOT NULL` | Payment amount in `currency` |
| **`currency`** | `VARCHAR(3) NOT NULL` | Payment currency |
| **`exchange_rate`** | `DECIMAL(12,6) NOT NULL DEFAULT 1` | FX |
| **`bank_or_cash_account_id`** | `INT UNSIGNED NOT NULL` | `financial_accounts.id` — source of funds |
| **`payment_method`** | `VARCHAR(32) NULL` | transfer, cheque, cash, mobile |
| **`reference_no`** | `VARCHAR(64) NULL` | Bank reference / cheque no. |
| **`swift_document`** | `VARCHAR(500) NULL` | Path to proof file (may copy from PV) |
| **`journal_entry_id`** | `INT UNSIGNED NULL` | GL: Dr AP / Cr Bank |
| **`status`** | `ENUM('draft','posted','void') NOT NULL DEFAULT 'draft'` | Posting lifecycle |
| **`created_by`** | `INT UNSIGNED NULL` | Who recorded payment |
| **`posted_by`** | `INT UNSIGNED NULL` | Who posted |
| **`posted_at`** | `DATETIME NULL` | When posted |
| **`created_at`** | `TIMESTAMP` | Created |
| **`updated_at`** | `TIMESTAMP` | Updated |

### 6.2 Uniqueness

| Constraint | Purpose |
|------------|---------|
| `UNIQUE (company_id, payment_number)` | Human-readable ref |
| `UNIQUE (payment_voucher_id)` where not null | One execution per PV (prevents double pay) |

### 6.3 Relationship to liquidity module

On **post**:

1. Insert `supplier_payments` row (`status=posted`).
2. Insert `account_transactions` with `reference_type='supplier_payment'`, `reference_id=supplier_payments.id` (keep `payment_voucher` as secondary reference if needed).
3. Recalculate `financial_accounts.current_balance`.
4. Update `vendor_bills.paid_amount`, `balance_due`, `payment_status`.
5. Update `payment_vouchers.supplier_payment_id`, `is_paid=1`, `paid_at`, etc.

---

## 7. Supplier payment allocations

### 7.1 Decision: defer to Phase 6+ (not required for v1)

| Scenario | Need allocations? |
|----------|-------------------|
| **One PV pays one bill** (README v1) | **No** — `supplier_payments.vendor_bill_id` is enough |
| **One PV pays multiple bills** | **Yes** — need `supplier_payment_allocations` |
| **One bank transfer covers multiple bills** | **Yes** — need allocations or multiple SP rows |

**Recommendation for v1:** Enforce **one Payment Voucher ? one Vendor Bill ? one Supplier Payment** (1:1:1). Document exception process for multi-bill transfers later.

### 7.2 Future table: `supplier_payment_allocations` (if needed)

| Column | Purpose |
|--------|---------|
| **`id`** | PK |
| **`company_id`** | Tenant |
| **`supplier_payment_id`** | Parent payment |
| **`vendor_bill_id`** | Bill slice paid |
| **`allocated_amount`** | Amount applied to that bill |
| **`created_at`** | Audit |

**When to introduce:** When finance requires single bank payment split across multiple open bills without separate PVs per bill.

---

## 8. Journal posting plan

### 8.1 When Vendor Bill is posted (`vendor_bills.status` ? `posted`)

**Single journal entry per bill** (header + lines):

| Line | Account | Debit | Credit |
|------|---------|------:|-------:|
| Stock lines | Inventory (asset) or Expense per `vendor_bill_items.account_id` | ? line_totals | |
| Liability | Accounts Payable (liability control) | | ? bill total |

**Rules:**

- Post **once** per bill.
- **Do not** debit bank.
- **Do not** create `erp_expenses` row for the full bill amount (that would double-count expense).

**Source metadata:** `erp_journal_entries.source_type = 'vendor_bill'`, `source_id = vendor_bills.id`

### 8.2 When Supplier Payment is posted (`supplier_payments.status` ? `posted`)

| Account | Debit | Credit |
|---------|------:|-------:|
| Accounts Payable | amount | |
| Bank / Cash (`bank_or_cash_account_id`) | | amount |

**Rules:**

- **Do not** debit inventory or expense again.
- **Do not** debit bank twice (coordinate with `recordTransaction`).
- **Do not** run legacy `ensureVoucherSyncToExpenses` full expense duplicate for stock AP payments (or map sync to non-cash accrual only — policy).

**Source metadata:** `erp_journal_entries.source_type = 'supplier_payment'`, `source_id = supplier_payments.id`

### 8.3 Avoiding duplicate effects (critical)

| Current path | Risk | Phase 3+ action |
|--------------|------|-----------------|
| **`mark-paid.php`** ? `recordTransaction` debit | Bank ? | Keep for **general** vouchers; for **stock+bill** path, bank ? only via SP post |
| **`mark-paid.php`** ? `ensureVoucherSyncToExpenses` | Expense + `is_posted` | **Skip** for `vendor_bill_id` PVs |
| **`modules/expenses/api/post_voucher.php`** | Second bank debit | Block if `supplier_payment_id` set or `vendor_bill_id` present |
| **Admin all-vouchers GL** | Third GL/expense | Same branch: stock AP uses unified SP posting only |

### 8.4 `erp_expenses` role after change

| Voucher type | `erp_expenses` |
|--------------|----------------|
| General expense PV (no bill) | Keep current sync behavior (transition period) |
| Stock purchase PV (with `vendor_bill_id`) | **Do not** create expense equal to full payment as cash expense; AP clearance is via SP |

### 8.5 Account resolution (configuration)

Store in `company_settings` or `system_settings` per company:

| Setting key | Example |
|-------------|---------|
| `default_ap_account_id` | `erp_accounts` id for AP |
| `default_inventory_account_id` | Stock asset account |
| `default_stock_expense_account_id` | Non-inventory purchases |

---

## 9. Existing mark-paid.php transition plan

**File:** `mark-paid.php` (and `employee/mark-paid.php`)

### 9.1 Current behavior (summary)

1. Require `status = approved`.
2. Require SWIFT upload.
3. `recordTransaction` ? debit `financial_accounts`.
4. Set `is_paid=1`, `payment_account_id`, `swift_document`, `paid_at`.
5. (Root) `ensureVoucherSyncToExpenses` ? `erp_expenses`, may set `is_posted=1`.

### 9.2 Target behavior (branching)

```
mark-paid request
    ?
    ??? [A] vendor_bill_id IS NULL  ?  LEGACY PATH (unchanged)
    ?       • recordTransaction (debit bank)
    ?       • is_paid = 1
    ?       • optional erp_expenses sync (root)
    ?       • existing validations
    ?
    ??? [B] vendor_bill_id IS NOT NULL  ?  STOCK AP PATH (new)
            1. Validate PV approved, not already paid (supplier_payment_id IS NULL)
            2. Validate approved_amount ? vendor_bills.balance_due
            3. Validate bill.status = posted
            4. Create supplier_payments (draft)
            5. Post supplier_payment:
                 • JE: Dr AP / Cr Bank
                 • account_transactions (reference supplier_payment)
                 • update vendor_bills paid_amount, balance_due, payment_status
            6. Update payment_vouchers:
                 • supplier_payment_id, is_paid=1, paid_at, payment_account_id
                 • swift_document, payment_reference_no
            7. Do NOT run expense sync that debits bank again
            8. Do NOT post inventory/expense JE
```

### 9.3 Idempotency guards

| Check | Purpose |
|-------|---------|
| `payment_vouchers.supplier_payment_id IS NOT NULL` | Block second pay |
| `UNIQUE (payment_voucher_id)` on `supplier_payments` | DB-level guard |
| Transaction wrap | All-or-nothing post |

### 9.4 `employee/mark-paid.php`

Align with root: either add expense sync to both or neither; **stock AP path** should be identical on both.

### 9.5 What not to change in Phase 3 code (plan only)

- Print layout files (`voucher-approvals-table.php`, `#voucherFull` tables).
- Approval status enum values (`confirming`, `pending`, `approved`).
- General vouchers without `vendor_bill_id`.

---

## 10. Backward compatibility plan

### 10.1 Existing vouchers without `vendor_bill_id`

| Aspect | Behavior |
|--------|----------|
| **Treatment** | General / legacy expense vouchers |
| **mark-paid** | Legacy path (§9.2 branch A) |
| **Print** | Unchanged; `payee_name` still shown |
| **Reports** | Still appear in existing voucher lists |

### 10.2 Stock purchase vouchers (transition)

| Phase | Rule |
|-------|------|
| **Phase 4–5** | `vendor_bill_id` optional on PV |
| **Phase 6** | Config flag: `require_vendor_bill_for_stock_pv = true` for new PVs only |
| **Grandfather** | Old PVs with only `linked_stock_po_id` remain payable via legacy path until migrated |

### 10.3 `linked_stock_po_id`

| Decision |
|----------|
| **Keep** column on `payment_vouchers` |
| **Also** set `vendor_bills.purchase_order_id` / `linked_stock_po_id` when bill created from PO |
| Do not remove PO link when bill is introduced |

### 10.4 Print layout

| Rule |
|------|
| No changes to printable voucher table structure |
| New fields (bill number, balance) may appear in **screen UI only** outside `#voucherFull` |
| `payee_name` remains on printed face |

### 10.5 Data migration (optional backfill)

| Step | Action |
|------|--------|
| Manual | Finance creates vendor bills for open POs with supplier invoices |
| Link | Set `payment_vouchers.vendor_bill_id` for open approved unpaid PVs tied to stock |
| Do not auto-post historical bills without finance sign-off |

---

## 11. Multi-company safety

### 11.1 Schema rules

| Rule | Application |
|------|-------------|
| Every new table includes **`company_id NOT NULL`** | `vendor_bills`, `vendor_bill_items`, `supplier_payments`, allocations (future) |
| Composite indexes lead with `company_id` | All list/report queries |
| Unique keys include `company_id` | Bill numbers, payment numbers |

### 11.2 Application rules

| Operation | Requirement |
|-----------|-------------|
| SELECT | `WHERE company_id = :current_company_id` (or tenant DB isolation) |
| INSERT | Set `company_id` from `currentCompanyId()`; reject 0 |
| UPDATE/DELETE | Same filter in WHERE |
| Joins | Child tables must match parent `company_id` |

### 11.3 Attachments and files

| Asset | Rule |
|-------|------|
| PV attachments | `assets/uploads/vouchers/{voucher_id}/` — access only if PV belongs to company |
| SWIFT / SP proofs | Prefer `assets/uploads/supplier-payments/{company_id}/{payment_id}/` for new paths |
| **Never** use voucher id alone across companies in shared DB | |

### 11.4 Upload path recommendation (new)

```
assets/uploads/
  vendor-bills/{company_id}/{bill_id}/
  supplier-payments/{company_id}/{payment_id}/
  vouchers/{company_id}/{voucher_id}/   ? migrate gradually
```

### 11.5 Test company isolation

Scenario **H** in §13: Ultimate company user must not see Roadmaster `vendor_bills`, `supplier_payments`, or attachments.

---

## 12. Migration order

**No SQL in Phase 3.** This is the order for Phase 3b (migration scripts) after sign-off.

| Step | Action | Notes |
|------|--------|-------|
| **1** | **Full database backup** | Production + staging |
| **2** | **CREATE `vendor_bills`** | Empty table; no FKs to PV yet |
| **3** | **CREATE `vendor_bill_items`** | FK to `vendor_bills` (optional defer FK) |
| **4** | **CREATE `supplier_payments`** | FK references nullable initially |
| **5** | **SKIP `supplier_payment_allocations`** | Until multi-bill needed |
| **6** | **ALTER `payment_vouchers`** add nullable columns (§3) | No NOT NULL yet |
| **7** | **ALTER `account_transactions`** optional `supplier_payment_id` | Nullable |
| **8** | **ADD indexes** | Company-scoped |
| **9** | **ADD foreign keys** | Only on staging after data review; ON DELETE RESTRICT |
| **10** | **Optional backfill script** | Manual/semi-auto; not in hot path |
| **11** | **Deploy application code** | Feature flags off first |
| **12** | **Enable on staging** | Run §13 tests |
| **13** | **Production deploy** | Monitor; keep legacy mark-paid path for non-bill PVs |

### 12.1 What NOT to do in migration

- DROP or RENAME existing tables/columns
- CHANGE `payment_vouchers.status` enum without mapping
- SET `vendor_bill_id NOT NULL` on day one
- Mass-post vendor bills for all historical POs without review

---

## 13. Testing scenarios

Execute on **staging** after migrations + feature code (Phases 4–6). Phase 3 defines expected outcomes.

### A. Supplier bill 1,000,000 unpaid

| Step | Expected |
|------|----------|
| Create/post vendor bill | `total_amount=1,000,000`, `paid_amount=0`, `balance_due=1,000,000`, `payment_status=unpaid` |
| GL | Dr Inventory 1,000,000 / Cr AP 1,000,000 |
| Bank | No change |

### B. Pay 500,000 through PV ? partially_paid

| Step | Expected |
|------|----------|
| PV approved for 500,000 linked to bill | OK |
| Mark paid (stock AP path) | SP posted; bank ?500,000; AP ?500,000 |
| Bill | `paid_amount=500,000`, `balance_due=500,000`, `payment_status=partially_paid` |
| PV | `is_paid=1`, `supplier_payment_id` set |

### C. Pay remaining 500,000 ? paid

| Step | Expected |
|------|----------|
| Second PV + SP | Bill `paid_amount=1,000,000`, `balance_due=0`, `payment_status=paid` |

### D. Reject PV ? no bank movement

| Step | Expected |
|------|----------|
| PV `status=rejected` | mark paid blocked |
| Bank / SP | None |

### E. Approve PV only ? no bank movement

| Step | Expected |
|------|----------|
| PV `status=approved`, not paid | Bank unchanged; no SP row |

### F. Pay more than balance_due ? blocked

| Step | Expected |
|------|----------|
| `approved_amount` = 600,000 when `balance_due` = 500,000 | Validation error (unless overpayment setting) |

### G. Same voucher cannot be paid twice

| Step | Expected |
|------|----------|
| Second mark paid on same PV | Error: `supplier_payment_id` already set |

### H. Multi-company isolation

| Step | Expected |
|------|----------|
| User in company A | Cannot SELECT/UPDATE company B bills, payments, attachments |
| Document numbers | Unique per company only |

### Additional regression tests

| ID | Scenario |
|----|----------|
| **I** | General PV (no `vendor_bill_id`) still marks paid via legacy path |
| **J** | `post_voucher.php` does not second-debit bank for stock AP PV |
| **K** | Cancelled vendor bill cannot accept new PV |
| **L** | Print output byte-identical for sample approved voucher (visual diff) |

---

## 14. Final recommendation

### Build order (aligned with README Phases 3–8)

| Order | Deliverable | Rationale |
|-------|-------------|-----------|
| **1** | **Vendor Bills** (tables + post + UI) | Establishes AP liability before changing pay flow |
| **2** | **Link Payment Voucher to Vendor Bill** (nullable columns + validation + UI) | Extends existing module safely |
| **3** | **Supplier Payments** (table + post + mark-paid branch) | Single cash + JE path |
| **4** | **Journal posting** (unified service; disable duplicate paths) | Financial integrity |
| **5** | **Reports** (supplier statement, AP aging from bills) | Uses correct balances |
| **6** | **Stock UI integration** (create bill from PO/GRN) | Operational completeness |

### Do not do yet

| Action | Why |
|--------|-----|
| Change voucher **print layout** | Audit: official document must stay stable |
| Refactor **all** mark-paid logic at once | Branch: stock AP vs general |
| **DROP** legacy `purchases` tables | High risk; separate project |
| Require `vendor_bill_id` on day one | Breaks in-flight PVs |
| Create **`supplier_payment_allocations`** in v1 | Unnecessary if 1 PV : 1 bill |

### Architectural reminders

1. **Payment Voucher ? Vendor Bill** — never use PV post as bill post.
2. **Approved PV does not move bank** — only **Supplier Payment** post does.
3. **Supplier Payment** becomes the official payment record; **`is_paid`** remains a compatible flag for existing UI.
4. **`erp_expenses`** stays for general expenses until redesigned; exclude stock AP clearance from duplicate expense posting.
5. **`company_id`** on every new row and every query.

### Phase 3 exit criteria

- [ ] This document reviewed by finance + lead developer  
- [ ] Account IDs for Inventory and AP agreed per company  
- [ ] Decision confirmed: **defer allocations** for v1  
- [ ] Decision confirmed: **defer `goods_received_notes` table** for v1  
- [ ] Staging migration dry-run scheduled  
- [ ] **No migration SQL committed** until Phase 3b sign-off  

---

**End of Phase 3 planning document — no code, migrations, or SQL were executed.**
