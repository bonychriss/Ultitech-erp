# Phase 4G — Stock Purchase Payment Desk (Updated Office Workflow)

**Document type:** Planning only — no code, no mark-paid changes, no print layout changes  
**Status:** Updated workflow reflecting real office process  
**Supersedes / complements:** `PHASE-4G-STOCK-PURCHASE-PAYMENT-DESK-PLAN.md` (desk-only view); use **this document** as the authoritative end-to-end workflow.

**Companion docs:**

- `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`
- `PHASE-4-VENDOR-BILLS-MODULE-IMPLEMENTATION-PLAN.md`
- `PHASE-3-SCHEMA-GAP-MATRIX-MIGRATION-PLAN.md`
- `CURRENT-SYSTEM-AUDIT-STOCK-PURCHASE-PAYMENTS.md`

---

## Executive summary

In our office, the **Payment Voucher (PV)** often comes **first** — before Purchase Order (PO) and before Vendor Bill. Sales, Procurement, or other authorized staff create the PV and must classify it as **General** or **Stock Purchase**. Only **Stock Purchase** vouchers enter the stock purchase payment workflow.

| Stage | Who | Money impact |
|-------|-----|--------------|
| **1. Sales / Staff** | Create PV, Purpose = Stock Purchase, link quotation, attach documents | **None** on bank/cash |
| **2. Procurement** | Create PO, link approved unpaid Stock Purchase PV, optional autofill from quotation | **None** on bank/cash |
| **3. Finance** | Stock Purchase Payment Desk ? Mark as Paid | **Bank/cash reduces** only on confirm |

**Vendor Bill** is optional and may be created **later** for reconciliation. **`vendor_bill_id` remains nullable.**

---

## Table of contents

1. [Purpose classification rule](#1-purpose-classification-rule)
2. [Stage 1 — Sales / Staff](#2-stage-1--sales--staff)
3. [Stage 2 — Procurement](#3-stage-2--procurement)
4. [Stage 3 — Finance Payment Desk](#4-stage-3--finance-payment-desk)
5. [Finance Mark as Paid](#5-finance-mark-as-paid)
6. [After successful payment](#6-after-successful-payment)
7. [Old vouchers — Needs Classification](#7-old-vouchers--needs-classification)
8. [Vendor Bill (later reconciliation)](#8-vendor-bill-later-reconciliation)
9. [Purpose field storage (system audit)](#9-purpose-field-storage-system-audit)
10. [Current codebase vs target (gaps)](#10-current-codebase-vs-target-gaps)
11. [Security and compatibility](#11-security-and-compatibility)
12. [Implementation phases](#12-implementation-phases)
13. [What not to change](#13-what-not-to-change)

---

## 1. Purpose classification rule

Every new Payment Voucher must be classified at creation (or edit while draft):

| Purpose value | Label in UI | Enters stock workflow? |
|---------------|-------------|------------------------|
| `general` | General | **No** — existing general expense / payment flow |
| `stock_purchase` | Stock Purchase | **Yes** — Sales ? Procurement ? Finance desk |

**Canonical DB values:** `purpose = 'stock_purchase'` and/or `payment_purpose = 'stock_purchase'` (see §9).

**Desk rule:** Finance Payment Desk and Procurement PO linker must **never** show all approved vouchers — only **`stock_purchase`**.

---

## 2. Stage 1 — Sales / Staff

**Who:** Sales staff, Procurement staff, or any authorized user with PV create access.

**When:** Before PO, before Vendor Bill (normal office order).

### 2.1 Create Payment Voucher

| Step | Action | System |
|------|--------|--------|
| 1 | Open Create New Payment Voucher | `employee/create-voucher.php` |
| 2 | Select **Purpose = Stock Purchase** | Save `purpose` / `payment_purpose` = `stock_purchase` |
| 3 | Enter payee, description, line items, amount | Existing voucher form |
| 4 | Link **quotation / sales quotation** from system | `linked_sales_order_id` / `linked_sales_order_ids` (Sales module) |
| 5 | Attach supporting documents | `voucher_attachments`: supplier invoice, office quote, quotation PDF, receipts |
| 6 | Submit for approval | `status`: confirming ? pending ? approved |

### 2.2 Quotation linking

| Item | Current system | Target |
|------|----------------|--------|
| UI label | "Link Sales Order(s) (from Sales Module)" | May relabel "Link Quotation / Sales Order" for staff clarity |
| Storage | `linked_sales_order_ids` (JSON) + `linked_sales_order_id` (first id) | Unchanged |
| Use | Procurement autofill source for PO lines (§3.3) | Finance can view linked quotation on Mark as Paid (§5) |

### 2.3 What must NOT happen at Stage 1

- No bank/cash debit
- No `is_paid = 1`
- No `recordTransaction()`
- No Vendor Bill required
- No PO required
- No change to printable voucher layout

---

## 3. Stage 2 — Procurement

**Who:** Procurement staff.

**When:** After PV is **approved** and **not yet paid** (typical office flow).

**Page:** `stock/modules/purchases/domestic_create.php` (modern PO create).

### 3.1 Selectable Payment Vouchers (strict filter)

When Procurement opens PO create, the **Link Payment Voucher** dropdown/list must show **only** vouchers matching **all** of:

```sql
pv.status = 'approved'
AND pv.is_paid = 0
AND (
    pv.purpose = 'stock_purchase'
    OR pv.payment_purpose = 'stock_purchase'
)
AND (pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)
AND pv.company_id = :company_id
```

| Exclude | Reason |
|---------|--------|
| `purpose = 'general'` | Not stock workflow |
| `is_paid = 1` | Already paid |
| Already linked to another PO | One PO link per PV (v1) |
| Other companies | Multi-company safety |

**Note:** Current `domestic_create.php` filters approved + unlinked PO but does **not** fully enforce `is_paid = 0`, `stock_purchase` purpose, or `company_id` — see §10.

### 3.2 After PV selection — PO line options

Procurement chooses how to build PO lines:

| Option | Behavior |
|--------|----------|
| **A. Autofill from linked quotation** | Read PV `linked_sales_order_ids` ? load sales order / quotation lines ? populate `stocks_po_items` (product, qty, unit cost) |
| **B. Keep existing PO items** | If user already added lines manually, retain them when linking PV |
| **C. Manual select** | User picks products and prices from catalogue as today |

**UX:** Radio or button group after PV select: *Autofill from quotation* | *Keep current lines* | *Manual* (default manual if no quotation linked).

**Autofill source priority:**

1. Linked sales order / quotation lines (from PV)
2. Fallback: message "No quotation linked on this voucher"

### 3.3 Bidirectional link on PO save

When PO is created successfully:

| Table | Column | Value |
|-------|--------|-------|
| `stocks_purchase_orders` | `payment_voucher_id` | Selected PV id (primary) |
| `stocks_purchase_orders` | `payment_voucher_ids` | JSON array if multi-PV supported later |
| `payment_vouchers` | `linked_stock_po_id` | New PO id |

**Existing behavior:** Partially implemented in `domestic_create.php` on save — keep and tighten validation (§3.1).

### 3.4 What must NOT happen at Stage 2

- No bank/cash movement
- No Mark as Paid
- No Vendor Bill required
- No change to PV print layout

---

## 4. Stage 3 — Finance Payment Desk

### 4.1 Page

| Item | Value |
|------|--------|
| **Name** | Stock Purchase Payment Desk |
| **Path** | `modules/finance/stock-purchase-payment-desk.php` |
| **Access** | Finance / Admin only (`isFinance()` or `isAdmin()`) |
| **Scope** | `company_id = currentCompanyId()` always |

**Do not show** general vouchers or all approved vouchers.

### 4.2 Global stock purchase filter (all tabs except Needs Classification)

```sql
(
    pv.purpose = 'stock_purchase'
    OR pv.payment_purpose = 'stock_purchase'
)
AND pv.company_id = :company_id
```

### 4.3 Tabs

#### Tab A — **Awaiting PO Link**

Approved Stock Purchase PVs **not yet linked** to a PO.

```sql
pv.status = 'approved'
AND pv.is_paid = 0
AND (pv.purpose = 'stock_purchase' OR pv.payment_purpose = 'stock_purchase')
AND (pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)
AND pv.company_id = :company_id
```

**Purpose:** Finance visibility — payment authorized but Procurement has not created/linked PO yet. **No Mark as Paid** on this tab (office rule: pay only when PO linked — see Tab B).

#### Tab B — **Ready for Payment**

Approved Stock Purchase PVs **with PO linked**, unpaid.

```sql
pv.status = 'approved'
AND pv.is_paid = 0
AND (pv.purpose = 'stock_purchase' OR pv.payment_purpose = 'stock_purchase')
AND pv.linked_stock_po_id IS NOT NULL
AND pv.linked_stock_po_id > 0
AND pv.company_id = :company_id
```

**Primary action:** **Mark as Paid** (§5).

#### Tab C — **Paid & Posted**

Stock Purchase PVs already paid.

```sql
(pv.purpose = 'stock_purchase' OR pv.payment_purpose = 'stock_purchase')
AND pv.is_paid = 1
AND pv.company_id = :company_id
```

Optional: also show `is_posted = 1` badge. Read-only; link to voucher view.

#### Tab D — **Needs Classification**

Old or unclassified vouchers for Finance/Admin cleanup.

```sql
pv.status = 'approved'
AND pv.is_paid = 0
AND COALESCE(NULLIF(pv.purpose, ''), 'general') NOT IN ('stock_purchase')
AND COALESCE(NULLIF(pv.payment_purpose, ''), 'general') NOT IN ('stock_purchase')
AND pv.company_id = :company_id
```

**Action:** **Mark as Stock Purchase** (§7) — does not pay, does not change amount or approval.

### 4.4 Suggested columns (Tabs A–C)

| Column | Source |
|--------|--------|
| Voucher No | `voucher_no` |
| Date | `date_created` |
| Payee / Supplier | `payee_name` |
| Description | `description` |
| Amount | `total_amount` |
| Currency | `currency` |
| Purpose | Stock Purchase |
| Linked quotation | `linked_sales_order_ids` / sales order no. |
| Linked PO | `linked_stock_po_id` ? `po_number` |
| Vendor Bill | `vendor_bill_id` (optional, often empty) |
| Requested by | `applicant` / `prepared_by` |
| Approved by / at | `approved_by`, `approved_at` |
| Payment status | Unpaid / Paid |
| Actions | View, Mark as Paid (Tab B only), Mark as Stock Purchase (Tab D only) |

### 4.5 Filters (all tabs)

| Filter | Notes |
|--------|-------|
| Payee / supplier | Text or dropdown |
| Date range | Created or approved date |
| Amount range | min / max |
| Approved by | User |
| PO linked / not linked | Overrides tab default when needed |
| Vendor bill linked / not | Optional |
| Quotation linked / not | `linked_sales_order_id(s)` present |

---

## 5. Finance Mark as Paid

**Available on:** Tab B — Ready for Payment only.

**Until payment desk is approved and built:** do **not** modify `mark-paid.php`. Plan assumes desk modal will POST to existing mark-paid endpoint later.

### 5.1 Required fields (modal)

| Field | Required | Maps to |
|-------|----------|---------|
| Payment account / bank account | Yes | `payment_account_id` |
| Payment date | Yes | `paid_at` (date) |
| Payment method | Yes | New or existing column / notes |
| Payment reference number | Yes | `payment_reference_no` |
| SWIFT / proof document | Yes | `swift_document` |
| Notes | Optional | Internal log |

### 5.2 Document panel (read-only in modal)

Finance must see **all related attachments** before confirming:

| Source | Content |
|--------|---------|
| Payment Voucher | `voucher_attachments` (invoices, quotes, receipts, PDFs) |
| Linked quotation | Sales order / quotation files or linked order summary |
| Linked Purchase Order | PO detail + any PO attachments if present |
| Supplier invoice / office quote | Any tagged docs from PV or PO |

**Implementation note:** Aggregate links in desk modal; do not change printable voucher HTML.

### 5.3 Balance rule

| Event | Bank/cash |
|-------|-----------|
| PV approved | No change |
| PO linked | No change |
| Open desk / modal | No change |
| **Confirm Mark as Paid** | **Debit** selected account (single time) |

Approval never moves money. Only Finance confirmation does.

---

## 6. After successful payment

On successful Mark as Paid (via existing safe path — **future wiring from desk**):

### 6.1 Update `payment_vouchers`

| Field | Value |
|-------|-------|
| `is_paid` | `1` |
| `paid_at` | Current datetime |
| `paid_by` | Current user id |
| `payment_account_id` | Selected account |
| `payment_reference_no` | Entered reference |
| `payment_execution_status` | `paid` (if column exists) |
| `is_posted` | `1` if current system sets posted after payment (match existing general voucher behavior) |
| `posted_by` / `posted_at` | If `is_posted` set (existing rules) |

### 6.2 Liquidity / account transaction

- Create **one** `account_transactions` debit via existing `recordTransaction()` (or equivalent safe balance function).
- **Do not double-debit** bank/cash (avoid duplicate paths: desk + `post_voucher.php` + expense sync for stock purchase — Phase 5).
- Do **not** call `postVendorBillToJournal()` at payment time.
- Do **not** require `vendor_bill_id`.

### 6.3 UI after pay

- Voucher moves from Tab B ? Tab C (Paid & Posted).
- PO remains linked; procurement/finance can view paid status.

---

## 7. Old vouchers — Needs Classification

**Problem:** Purpose = Stock Purchase is new. Old approved unpaid vouchers may have `purpose = 'general'` or NULL.

### 7.1 Future action: **Mark as Stock Purchase**

| Rule | Detail |
|------|--------|
| Who | Finance / Admin only |
| Scope | `company_id = currentCompanyId()` |
| Allowed | Typically `status = 'approved'` and `is_paid = 0` |
| Updates | `purpose = 'stock_purchase'`, `payment_purpose = 'stock_purchase'` |
| Does **not** change | Amount, approvals, print layout, `is_paid`, bank/cash |
| Audit | Log who classified and when |

**Endpoint (future):** e.g. `modules/finance/api/classify-voucher-stock-purchase.php`

After classification, voucher appears on Tab A (if no PO) or Tab B (if PO already linked).

---

## 8. Vendor Bill (later reconciliation)

### 8.1 Rules

| Rule | Detail |
|------|--------|
| Vendor Bill before pay | **Not required** |
| `vendor_bill_id` on PV | **Nullable** |
| When created | After payment, when supplier invoice is formally recorded |
| Module | `stock/modules/purchases/vendor-bills/` (Phase 4+) |

### 8.2 Future accounting — Supplier Advance / Prepayment

When PV is **paid before Vendor Bill** exists:

**At Mark as Paid (future GL — Phase 5+):**

| Account | Debit | Credit |
|---------|------:|-------:|
| Supplier Advances / Prepayments | amount | |
| Bank / Cash | | amount |

**When Vendor Bill posted later:**

| Account | Debit | Credit |
|---------|------:|-------:|
| Inventory / Stock | bill total | |
| Accounts Payable | | bill total |

**Reconciliation (advance applied to bill):**

| Account | Debit | Credit |
|---------|------:|-------:|
| Accounts Payable | applied amount | |
| Supplier Advances / Prepayments | | applied amount |

**Do not implement GL in Phase 4G.** Document only.

---

## 9. Purpose field storage (system audit)

| Item | Current state |
|------|----------------|
| Form field | `voucher_purpose` in `employee/includes/voucher-form-page.php` |
| Options | `general`, `stock_purchase` |
| Create save | Writes **`purpose`** if column exists |
| Create save | Does **not** write **`payment_purpose`** (Phase 3B column) |
| Edit save | Updates **`purpose`**; not **`payment_purpose`** |
| Runtime ensure | `ensureVoucherStockPurchaseSchema()` adds **`purpose`** |

### 9.1 Required fix (before desk go-live)

On create and edit, when columns exist:

```text
purpose = voucher_purpose
payment_purpose = voucher_purpose   // when stock_purchase or general
```

Desk and PO queries use:

```sql
(purpose = 'stock_purchase' OR payment_purpose = 'stock_purchase')
```

Helper (future): `getPaymentVoucherPurpose($row): string`.

---

## 10. Current codebase vs target (gaps)

| Area | Current | Target |
|------|---------|--------|
| PV create Purpose | UI exists; saves `purpose` only | Dual-write `purpose` + `payment_purpose` |
| PO PV picker (`domestic_create.php`) | Approved + unlinked PO | + `is_paid = 0`, + `stock_purchase`, + `company_id` |
| PO autofill from quotation | Partial / manual | Options A/B/C (§3.2) |
| Finance desk page | Not built | `modules/finance/stock-purchase-payment-desk.php` with 4 tabs |
| Mark as Paid from desk | Uses scattered voucher view | Desk modal ? `mark-paid.php` (later) |
| Pay without PO | Possible today via general mark-paid | Office rule: **Ready for Payment** requires `linked_stock_po_id` |
| Vendor Bill before pay | Not enforced | Keep optional |
| Quotation on PV | `linked_sales_order_ids` | Relabel/help text; show on desk |

---

## 11. Security and compatibility

| Rule | Implementation |
|------|----------------|
| Company | `currentCompanyId()` on all queries; never from GET/POST |
| Finance desk | `requireLogin()` + Finance/Admin |
| Mark as Stock Purchase | Finance/Admin + CSRF |
| General vouchers | Unchanged; excluded from desk |
| Approval workflow | Unchanged (`confirming` ? `pending` ? `approved`) |
| Print layout | **No changes** to `#voucherFull` / printable table |
| `mark-paid.php` | **No changes until desk plan approved and built** |

---

## 12. Implementation phases

| Phase | Deliverable |
|-------|-------------|
| **4G-1** | Purpose dual-write on create/edit + optional backfill |
| **4G-2** | Tighten PO PV picker filter (`domestic_create.php`) |
| **4G-3** | PO autofill options A/B/C from linked quotation |
| **4G-4** | `stock-purchase-payment-desk.php` — 4 tabs, columns, filters |
| **4G-5** | Mark as Paid modal + document aggregation (POST to mark-paid when approved) |
| **4G-6** | Mark as Stock Purchase (Tab D) |
| **5+** | Supplier Payment entity, prepayment GL, Vendor Bill reconciliation |

### Exit criteria

- [ ] Office confirms: pay only on Tab B (PO linked)
- [ ] Office confirms: PV may exist before PO and Vendor Bill
- [ ] Purpose filter enforced on desk and PO picker
- [ ] No print layout changes
- [ ] `mark-paid.php` unchanged until 4G-5 approved

---

## 13. What not to change

- Payment Voucher **print layout**
- Existing **approval workflow** and status enum
- Existing **general voucher** flow (Purpose = General)
- **`mark-paid.php`** until payment desk implementation is approved
- **Vendor Bill module** except optional future links (`vendor_bill_id` nullable)
- **Double bank posting** paths (audit before enabling stock-specific GL)

---

## Workflow diagram

```text
[Sales/Staff]  Create PV (Purpose=Stock Purchase)
               Link quotation + attachments
               Approvals ? approved
                      ?
                      ?
[Procurement]  Create PO ? select linked PV (approved, unpaid, stock_purchase)
               Autofill / keep / manual lines
               Link: payment_voucher_id ? linked_stock_po_id
                      ?
                      ?
[Finance]      Desk Tab A: awaiting PO (optional monitor)
               Desk Tab B: Ready for Payment ? Mark as Paid
               Bank/cash ? (once)
                      ?
                      ?
[Later]        Vendor Bill + prepayment reconciliation (Phase 5+)
```

---

**End of updated Phase 4G workflow plan — no code implemented.**
