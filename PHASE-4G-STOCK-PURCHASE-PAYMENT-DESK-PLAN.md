# Phase 4G ù Stock Purchase Payment Desk Plan

**Document type:** Planning only (no code, no mark-paid changes, no print layout changes)  
**Status:** Phase 4G ù Finance Payment Desk for stock purchase vouchers only  
**Companion docs:**

- `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`
- `PHASE-4-VENDOR-BILLS-MODULE-IMPLEMENTATION-PLAN.md`
- `PHASE-3-SCHEMA-GAP-MATRIX-MIGRATION-PLAN.md`

**Critical correction:** The Finance Payment Desk must **not** list all approved payment vouchers. It lists **only** vouchers created with Purpose = **Stock Purchase**.

---

## Table of contents

1. [Page purpose](#1-page-purpose)
2. [Voucher selection rule](#2-voucher-selection-rule)
3. [Purpose field storage (audit)](#3-purpose-field-storage-audit)
4. [Backward compatibility for old vouchers](#4-backward-compatibility-for-old-vouchers)
5. [Page columns](#5-page-columns)
6. [Main action ù Mark as Paid](#6-main-action--mark-as-paid)
7. [Important balance rule](#7-important-balance-rule)
8. [Current office workflow](#8-current-office-workflow)
9. [Vendor bill requirement](#9-vendor-bill-requirement)
10. [Future accounting treatment (prepayment)](#10-future-accounting-treatment-prepayment)
11. [Filters](#11-filters)
12. [Tabs](#12-tabs)
13. [Security](#13-security)
14. [Compatibility](#14-compatibility)
15. [Implementation phases (suggested)](#15-implementation-phases-suggested)

---

## 1. Page purpose

Create a dedicated **Finance** page where finance users process **approved stock purchase payment vouchers** awaiting bank/cash payment.

| Item | Value |
|------|--------|
| **Page name** | Stock Purchase Payment Desk |
| **Suggested path** | `modules/finance/stock-purchase-payments.php` |
| **Alternative** | `employee/stock-purchase-payments.php` if finance users live primarily in the employee portal (voucher view/mark-paid already exists under `employee/`) |

### Recommended path: `modules/finance/stock-purchase-payments.php`

**Why:**

- `modules/finance/` already exists (`approvals.php`, `my_expenses.php`, `transactions.php`) with finance bootstrap via `includes/functions.php`.
- `modules/balances/` is for **liquidity accounts** (bank/cash balances), not voucher approval queues ù related but different concern.
- Desk is a **finance workflow** page; mark-paid may POST to existing `mark-paid.php` (root or `employee/mark-paid.php`) without moving voucher CRUD.

**Navigation:** Add link under Finance module menu / balances sidebar: **Stock Purchase Payments**.

**Audience:** Finance and Admin only.

**What this page is not:**

- Not a replacement for `all-vouchers.php` / general voucher lists.
- Not a vendor bill list (that stays under `stock/modules/purchases/vendor-bills/`).
- Not a supplier payment ledger (Phase 5+).

---

## 2. Voucher selection rule

The desk lists **only** payment vouchers that match **all** of:

| Rule | SQL / logic |
|------|-------------|
| Approved | `status = 'approved'` |
| Unpaid (Awaiting tab) | `is_paid = 0` |
| Company scope | `company_id = currentCompanyId()` |
| Stock purchase purpose | See ù3 ù `purpose = 'stock_purchase'` **or** synced `payment_purpose = 'stock_purchase'` |

### Core filter (recommended)

```sql
WHERE pv.company_id = :company_id
  AND pv.status = 'approved'
  AND (
      pv.purpose = 'stock_purchase'
      OR pv.payment_purpose = 'stock_purchase'
  )
```

**Use canonical value:** `'stock_purchase'` (matches create form).

### Explicit exclusions

| Do not show | Reason |
|-------------|--------|
| `purpose = 'general'` or NULL (default general) | General expense / other payments |
| `status IN ('confirming','pending','rejected')` | Not ready for finance payment |
| `is_paid = 1` on Awaiting tab | Already paid (show on Paid tab instead) |
| Other companies' vouchers | Multi-company isolation |

### Paid tab filter

Same purpose rule, plus `is_paid = 1` (and optionally `payment_execution_status = 'paid'` when populated).

---

## 3. Purpose field storage (audit)

### Create New Payment Voucher form (current)

| Item | Detail |
|------|--------|
| **UI file** | `employee/includes/voucher-form-page.php` |
| **Field name (POST)** | `voucher_purpose` |
| **Options** | `general` ? "General Payment"; `stock_purchase` ? "Stock Purchase" |
| **Create handler** | `employee/create-voucher.php` |
| **Edit handler** | `employee/edit-voucher.php` |

### How Purpose is saved today

| Column | Exists? | Written on create? | Written on edit? |
|--------|---------|-------------------|------------------|
| **`purpose`** | Yes (runtime `ensureVoucherStockPurchaseSchema()` + optional formal migration) | **Yes** ù if column exists in schema-aware INSERT | **Yes** |
| **`payment_purpose`** | Yes (Phase 3B migration) | **No** ù create path does not insert it | **No** ù edit path does not update it |
| **`voucher_purpose`** | Checked on edit only | N/A | **Yes** ù if column exists (legacy/alternate) |

**Create path (excerpt logic):**

```php
$voucher_purpose = trim($_POST['voucher_purpose'] ?? 'general');
// allowed: general | stock_purchase
if (in_array('purpose', $pvCols, true)) {
    $insertCols[] = 'purpose';
    $insertVals[] = $voucher_purpose;
}
// payment_purpose is NOT inserted on create
```

**Edit path:** Updates `purpose` and optionally `voucher_purpose`; does **not** update `payment_purpose`.

### Canonical column decision for Phase 4G

| Decision | Recommendation |
|----------|----------------|
| **Primary filter column** | `purpose` (already used by create/edit UI) |
| **Secondary / sync column** | `payment_purpose` (Phase 3B; use after sync fix) |
| **Desk query** | `(purpose = 'stock_purchase' OR payment_purpose = 'stock_purchase')` until sync is guaranteed |

### Fixes needed before or during Phase 4G implementation (document only)

| # | Fix | Why |
|---|-----|-----|
| 1 | On **create** and **edit**, write **both** `purpose` and `payment_purpose` when column exists | Avoid desk missing rows after 3B migration |
| 2 | Helper `getVoucherPurpose($row): string` returning `stock_purchase` \| `general` | Single read path for desk, view, mark-paid branch (Phase 5) |
| 3 | Optional backfill script: `UPDATE payment_vouchers SET payment_purpose = purpose WHERE payment_purpose IS NULL AND purpose IS NOT NULL` | Align historical rows after dual-write |
| 4 | Do **not** add Purpose to printable voucher table | Screen-only / desk metadata |

### If Purpose appears in UI but desk is empty

Check:

1. Was `purpose` column created on tenant DB? (runtime ensure vs migration)
2. Was voucher created **before** Purpose field existed? ? Old Vouchers tab + "Mark as Stock Purchase"
3. Is only `payment_purpose` populated (future) while `purpose` still `general`? ? dual-column query above

---

## 4. Backward compatibility for old vouchers

Stock Purchase purpose is **new**. Existing approved unpaid vouchers may have:

- `purpose = 'general'` (default)
- `purpose` NULL
- `payment_purpose` NULL
- `linked_stock_po_id` set but purpose not `stock_purchase`

### Future admin/finance action: **Mark as Stock Purchase**

**Not in Phase 4G code** ù document for Phase 4G-b or 4H.

| Rule | Detail |
|------|--------|
| Who | Finance / Admin only (`isFinance()` or `isAdmin()`) |
| Scope | `company_id = currentCompanyId()` |
| Allowed status | `approved` (and optionally `pending` ù **exclude** `rejected`, `confirming` for desk) |
| Block if | `is_paid = 1` (already paid ù classification less critical) |
| Updates | Set `purpose = 'stock_purchase'` and `payment_purpose = 'stock_purchase'` when column exists |
| Does **not** change | `total_amount`, approval rows, print layout, `is_paid`, bank/cash, attachments |
| Audit | Insert `approval_logs` or dedicated audit row: action = `classified_stock_purchase`, user, timestamp |

**Endpoint (future):** `modules/finance/api/mark-voucher-stock-purchase.php` (POST, CSRF, voucher id).

**UI:** Button on **Needs Classification** tab only.

---

## 5. Page columns

| Column | Source |
|--------|--------|
| Voucher No | `payment_vouchers.voucher_no` |
| Date | `date_created` or `created_at` |
| Payee / Supplier | `payee_name`; optional join `stocks_suppliers` via `supplier_id` |
| Description | `description` (truncated) |
| Amount | `total_amount` or `approved_amount` if set |
| Currency | `currency` |
| Purpose | Display "Stock Purchase" when purpose matches |
| Linked PO | `linked_stock_po_id` ? `stocks_purchase_orders.po_number` |
| Vendor Bill | `vendor_bill_id` ? `vendor_bills.bill_number` (nullable) |
| Requested By | `applicant` or `prepared_by` |
| Approved By | `approved_by` ? user name |
| Approved At | `approved_at` |
| Payment Status | `is_paid` ? Unpaid / Paid; optional `payment_execution_status` |
| Action | Mark as Paid (Awaiting tab); View (all tabs) |

**View link:** Existing `employee/view-voucher.php?id=` or `view-voucher.php?id=` (company-scoped) ù **do not change print HTML inside view**.

---

## 6. Main action ù Mark as Paid

Primary action on **Awaiting Payment** tab: **Mark as Paid**.

### Modal fields (finance confirmation)

| Field | Maps to |
|-------|---------|
| Payment Account / Bank Account | `payment_account_id` ? `financial_accounts` |
| Payment Date | `paid_at` (date portion) |
| Payment Method | `payment_method` or notes (if column added later) |
| Payment Reference No | `payment_reference_no` |
| SWIFT / Proof Document | `swift_document` (required per current policy) |
| Notes | Optional internal note (log or `description` append ù screen only) |

### Phase 4G implementation approach

| Phase | Behavior |
|-------|----------|
| **4G plan** | Document modal + POST target |
| **4G code (later)** | Modal posts to **existing** `mark-paid.php` (or thin wrapper) with `voucher_id` ù **do not rewrite mark-paid logic in 4G** |
| **Phase 5** | Branch mark-paid for `stock_purchase` + optional `supplier_payments` |

**Important:** Desk opens modal; **bank movement only on confirm**, not on opening the desk or viewing the list.

---

## 7. Important balance rule

| Event | Bank / cash impact |
|-------|-------------------|
| Voucher created | None |
| Approvals / `status = approved` | **None** |
| Appears on Payment Desk | None |
| Finance clicks **Mark as Paid** and confirms | **Yes** ù debit via `recordTransaction()` (current) or `supplier_payments` (Phase 5) |
| Vendor bill posted | None on bank (AP only) |
| PO / vendor bill linked later | None on bank |

**Principle:** Approval authorizes payment; **Mark as Paid** executes payment.

---

## 8. Current office workflow

Office reality (supported by this plan):

```
Employee creates Payment Voucher
    Purpose = Stock Purchase
        ?
Approvals (Department Manager, Checked By, etc.)
        ?
status = approved, is_paid = 0
        ?
Appears on Stock Purchase Payment Desk (Finance)
        ?
Finance Mark as Paid (+ SWIFT)
        ?
Bank/Cash balance reduces
        ?
(Parallel / later) Procurement creates or links PO
        ?
(Later) Vendor Bill created and linked for AP reconciliation
```

**Order flexibility:**

- PV may be **paid before PO exists**.
- PV may be **paid before Vendor Bill exists**.
- `linked_stock_po_id` and `vendor_bill_id` remain **optional** at payment time.

Desk must **not block** Mark as Paid when PO or Vendor Bill is missing.

---

## 9. Vendor bill requirement

| Rule | Detail |
|------|--------|
| **`vendor_bill_id`** | **Nullable** on `payment_vouchers` |
| **Before Mark as Paid** | **Do not require** vendor bill |
| **Before desk listing** | **Do not require** vendor bill |
| **Future** | When bill exists, show link; when paid, allow reconciliation (Phase 5+) |

Finance pays against **approved stock purchase PV**; procurement/accounting links bills later.

---

## 10. Future accounting treatment (prepayment)

**Document only ù do not implement in Phase 4G.**

When stock purchase PV is **paid before Vendor Bill** exists, treat economically as **Supplier Advance / Prepayment**.

### Payment at Mark as Paid (future GL)

| Account | Debit | Credit |
|---------|------:|-------:|
| Supplier Advances / Prepayments | amount | |
| Bank / Cash | | amount |

*(Not Dr AP ù no bill liability yet.)*

### When Vendor Bill is later posted

| Account | Debit | Credit |
|---------|------:|-------:|
| Inventory / Stock | bill total | |
| Accounts Payable | | bill total |

### Reconciliation (apply advance to bill)

| Account | Debit | Credit |
|---------|------:|-------:|
| Accounts Payable | prepayment applied | |
| Supplier Advances / Prepayments | | prepayment applied |

### System implications (future phases)

| Topic | Note |
|-------|------|
| New account | Supplier Advances / Prepayments in `erp_accounts` |
| Link table | Optional `payment_vouchers.prepayment_journal_id` |
| mark-paid branch | If `vendor_bill_id` NULL ? prepayment posting; if bill exists ? AP clearance (Phase 5) |
| Current mark-paid | Still debits bank via `recordTransaction`; GL alignment is Phase 5+ |

---

## 11. Filters

All filters scoped by `company_id` + stock purchase purpose rule.

| Filter | Parameter | Logic |
|--------|-----------|--------|
| Payee | `payee` or `supplier_id` | `payee_name LIKE` or supplier FK |
| Date range | `date_from`, `date_to` | On `date_created` or `approved_at` |
| Amount range | `amount_min`, `amount_max` | On `total_amount` |
| Approved by | `approved_by` | User id or name |
| Linked PO | `po_linked` | `linked_stock_po_id IS NOT NULL` / IS NULL |
| Vendor Bill linked | `bill_linked` | `vendor_bill_id IS NOT NULL` / IS NULL |
| Paid / Unpaid | `paid` | `is_paid` (tab may override) |
| Old without purpose | `needs_class` | Approved + unpaid + purpose NOT stock_purchase + finance flag (Needs Classification tab) |

---

## 12. Tabs

| Tab | Filter summary |
|-----|----------------|
| **A. Awaiting Payment** | `status = approved`, `is_paid = 0`, purpose = stock_purchase |
| **B. Paid Stock Purchase Vouchers** | purpose = stock_purchase, `is_paid = 1` |
| **C. Needs Classification / Old Vouchers** | `status = approved`, `is_paid = 0`, purpose NOT stock_purchase (NULL/general), optional: has `linked_stock_po_id` OR finance manually reviews |

Tab C is where **Mark as Stock Purchase** (ù4) appears.

**Do not** show general approved vouchers on Tab A or B.

---

## 13. Security

| Rule | Implementation |
|------|----------------|
| Access | `requireLogin()` + `(isFinance() \|\| isAdmin())` ù mirror `modules/finance/approvals.php` |
| Company | `currentCompanyId()` on every query; reject if `<= 0` |
| Never trust | `company_id` from GET/POST/hidden fields |
| Mark as Paid | Re-verify voucher belongs to company before POST |
| Mark as Stock Purchase | Finance/Admin only; CSRF |
| Attachments | SWIFT upload paths remain company-safe (existing proxy rules) |

---

## 14. Compatibility

| Area | Phase 4G stance |
|------|-----------------|
| General voucher flow | Unchanged |
| General approved vouchers | **Not shown** on this desk |
| `all-vouchers.php` / employee lists | Unchanged |
| Payment Voucher print layout | **No changes** |
| `mark-paid.php` | **Not modified in planning phase**; desk may call it later |
| Vendor Bills module | Independent; optional links only |
| Supplier Payments table | Phase 5+ |

---

## 15. Implementation phases (suggested)

| Phase | Deliverable |
|-------|-------------|
| **4G-a** | Purpose dual-write fix (create/edit ? `purpose` + `payment_purpose`) |
| **4G-b** | `modules/finance/stock-purchase-payments.php` ù Awaiting tab + columns + filters |
| **4G-c** | Mark as Paid modal ? existing `mark-paid.php` (no logic fork yet) |
| **4G-d** | Paid tab + Needs Classification tab |
| **4G-e** | "Mark as Stock Purchase" action (ù4) |
| **5+** | Supplier payment entity, prepayment GL, vendor bill reconciliation |

### Phase 4G exit criteria (planning)

- [ ] Finance agrees: desk = **Stock Purchase purpose only**
- [ ] Column strategy confirmed: **`purpose` primary**, sync **`payment_purpose`**
- [ ] Confirmed: **no vendor_bill_id required** before pay
- [ ] Confirmed: **prepayment GL** deferred to Phase 5+
- [ ] Path confirmed: `modules/finance/stock-purchase-payments.php`

---

**End of Phase 4G planning document ù no code, mark-paid, or print changes.**
