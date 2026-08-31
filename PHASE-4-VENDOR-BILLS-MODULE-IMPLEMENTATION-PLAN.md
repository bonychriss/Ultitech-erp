# Phase 4 — Vendor Bills Module Implementation Plan

**Document type:** Implementation planning only (no code, no UI, no mark-paid changes)  
**Status:** Phase 4 — ready to implement after Phase 3B migration  
**Prerequisites:**

- `PHASE-3-SCHEMA-GAP-MATRIX-MIGRATION-PLAN.md` (approved)
- `PHASE-3B-STOCK-PURCHASE-PAYMENT-MIGRATIONS.sql` (run on target databases)
- Tables: `vendor_bills`, `vendor_bill_items` exist with `company_id`

**Rule:** Build the Vendor Bills module as a **new, separate** feature first. Do not change Payment Voucher print layout or `mark-paid.php` in Phase 4.

---

## Table of contents

1. [Module purpose](#1-module-purpose)
2. [Files to create](#2-files-to-create)
3. [Vendor Bills list page](#3-vendor-bills-list-page)
4. [Vendor Bill create page](#4-vendor-bill-create-page)
5. [Vendor Bill view page](#5-vendor-bill-view-page)
6. [Posting logic](#6-posting-logic)
7. [Accounts needed](#7-accounts-needed)
8. [Validation rules](#8-validation-rules)
9. [Link to Payment Voucher](#9-link-to-payment-voucher)
10. [Multi-company safety](#10-multi-company-safety)
11. [Backward compatibility](#11-backward-compatibility)
12. [Testing plan](#12-testing-plan)
13. [Implementation phases](#13-implementation-phases)

---

## 1. Module purpose

The **Vendor Bill** (supplier invoice) records what the company owes a supplier after goods or services are received. It is the **liability** document in the stock purchase payment workflow.

### Three documents (do not mix)

| Document | Role | Effect on money |
|--------|------|-----------------|
| **Vendor Bill** | Supplier invoice / debt | Increases **Accounts Payable**. Does **not** move bank or cash. |
| **Payment Voucher (PV)** | Internal approval to pay | **No** bank movement while draft, pending, or approved. |
| **Supplier Payment** | Actual payment execution | Reduces AP and reduces bank/cash (Phase 5+). |

### What a Vendor Bill does

- Records supplier, invoice number, amounts, and line detail.
- Links optionally to a **Purchase Order** (`stocks_purchase_orders` / `linked_stock_po_id`).
- When **posted**, creates a GL entry: **Debit** Inventory/Expense — **Credit** Accounts Payable.
- Maintains `paid_amount`, `balance_due`, and `payment_status` for partial payments later.

### What a Vendor Bill does not do

- It is **not** a Payment Voucher.
- It does **not** approve payment or print the official PV form.
- It does **not** debit bank, create `supplier_payments`, or set `payment_vouchers.is_paid`.
- It does **not** replace stock receiving (GRN/receive still updates inventory quantity).

Phase 4 delivers **create, list, view, edit (draft), post, cancel** for vendor bills only.

---

## 2. Files to create

### Recommended location: `stock/modules/purchases/vendor-bills/`

**Use this path, not `modules/purchases/vendor-bills/` at project root.**

| Reason | Detail |
|--------|--------|
| Existing convention | All live purchase UI lives under `stock/modules/purchases/` (`index.php`, `domestic_create.php`, `view_po.php`, receive flows). |
| Shared bootstrap | Same pattern as purchases list: `require_once __DIR__ . '/../../../config/database.php'` and `functions.php`, `requireLogin()`, `currentCompanyId()`. |
| Stock domain | Vendor bills tie to `stocks_purchase_orders`, `stocks_suppliers`, `stocks_items` — same module boundary as PO/receive. |
| Multi-tenant deploy | `ultimate/stock/modules/purchases/` can alias to main stock paths (same as purchases index). |

### Suggested file tree

```
stock/modules/purchases/vendor-bills/
    functions.php          # Core CRUD, numbering, balances, company scope
    index.php              # List + filters
    create.php             # Create draft (GET form + POST handler or separate save.php)
    edit.php               # Edit draft only
    view.php               # Read-only detail + actions
    post.php               # POST: draft ? posted + journal
    cancel.php             # POST: draft/posted ? cancelled (rules apply)
    save.php               # Optional: shared insert/update for create/edit AJAX
    api/
        po-lines.php       # Optional: fetch PO lines for prefill when PO selected
        next-bill-number.php  # Optional: preview next bill number

ultimate/stock/modules/purchases/vendor-bills/
    index.php              # Optional thin alias ? require main stock file
```

### Shared / includes (Phase 4A — not under vendor-bills folder)

| Path | Purpose |
|------|---------|
| `includes/vendor_bill_service.php` | Optional: service class used by stock UI and later PV prefill (keeps logic out of page scripts) |
| `includes/vendor_bill_schema.php` | Optional: `ensureVendorBillSchema()` if runtime checks needed beyond 3B |

**Do not** put vendor bill pages under `employee/` or mix into `view-voucher.php` in Phase 4.

### Navigation entry (Phase 4B)

Add a link from `stock/modules/purchases/index.php` (or stock sidebar): **Vendor Bills** ? `vendor-bills/index.php`. No change to PV menus yet.

---

## 3. Vendor Bills list page

**File:** `stock/modules/purchases/vendor-bills/index.php`

### Columns

| Column | Source |
|--------|--------|
| Bill number | `vendor_bills.bill_number` |
| Supplier | Join `stocks_suppliers` (fallback `suppliers` if needed per company) |
| Supplier invoice # | `supplier_invoice_number` |
| PO number | Join `stocks_purchase_orders` on `purchase_order_id` or `linked_stock_po_id` |
| Bill date | `bill_date` |
| Due date | `due_date` |
| Total amount | `total_amount` + `currency` |
| Paid amount | `paid_amount` |
| Balance due | `balance_due` |
| Payment status | `payment_status` (badge: unpaid / partially_paid / paid / overpaid) |
| Status | `status` (badge: draft / posted / cancelled) |
| Actions | View, Edit (draft only), Post (draft), Cancel (rules) |

### Filters (GET query params)

| Filter | Behavior |
|--------|----------|
| `supplier_id` | Exact match |
| `payment_status` | Enum filter |
| `status` | draft / posted / cancelled |
| `date_from`, `date_to` | On `bill_date` |
| `q` | Optional search on `bill_number`, `supplier_invoice_number` |
| **company_id** | Always `currentCompanyId()` in SQL — never from user input alone |

### Query pattern

```sql
SELECT vb.*, ss.name AS supplier_name, po.po_number
FROM vendor_bills vb
LEFT JOIN stocks_suppliers ss ON ss.id = vb.supplier_id
LEFT JOIN stocks_purchase_orders po ON po.id = COALESCE(vb.purchase_order_id, vb.linked_stock_po_id)
WHERE vb.company_id = :company_id
ORDER BY vb.bill_date DESC, vb.id DESC
```

### UI style

Match `stock/modules/purchases/index.php` and `stock/modules/products/index.php`: PHP + Tailwind, card/table layout, primary button **New Vendor Bill**.

### Pagination

Limit/offset (e.g. 25 per page) with total count for performance.

---

## 4. Vendor Bill create page

**File:** `stock/modules/purchases/vendor-bills/create.php`

### Header fields

| Field | DB column | Notes |
|-------|-----------|--------|
| Supplier | `supplier_id` | Required; dropdown from `stocks_suppliers` scoped by `company_id` |
| Linked PO | `purchase_order_id` + `linked_stock_po_id` | Optional; same id in both for v1 |
| Supplier invoice number | `supplier_invoice_number` | Optional but recommended |
| Bill date | `bill_date` | Default today (company timezone) |
| Due date | `due_date` | Optional |
| Currency | `currency` | Default `TZS` or company `base_currency` |
| Exchange rate | `exchange_rate` | Default `1` |
| Notes | `notes` | Textarea |

### Line items (repeatable rows ? `vendor_bill_items`)

| Field | DB column | Notes |
|-------|-----------|--------|
| Stock item | `stock_item_id` | Optional; from `stocks_items` |
| Product (legacy) | `product_id` | Optional; only if legacy stack used |
| Description | `description` | Required if no stock item |
| Quantity | `quantity` | > 0 |
| Unit cost | `unit_cost` | >= 0 |
| Tax rate % | `tax_rate` | Default 0 |
| Tax amount | `tax_amount` | Calculated or entered |
| Line total | `line_total` | qty × unit_cost + tax (stored) |
| GL account | `account_id` | Optional; default inventory account from settings |
| PO line link | `po_item_id` | Optional when created from PO |

### Totals (header, calculated server-side)

| Field | Rule |
|-------|------|
| `subtotal` | Sum of (qty × unit_cost) before tax |
| `tax_amount` | Sum of line tax |
| `total_amount` | subtotal + tax_amount |
| `paid_amount` | 0 on create |
| `balance_due` | = total_amount on create |
| `payment_status` | `unpaid` |
| `status` | `draft` |

### Bill number

- Generated on save via `functions.php` (e.g. `VB-{COMPANY_PREFIX}-{YEAR}-{SEQ}`).
- Use `document_sequences` if available (same pattern as PO/voucher numbering); else safe MAX+1 per company/year.

### Create from PO (enhancement in 4C)

- Query param `?po_id=123` preselects PO and loads lines from `stocks_po_items` (qty received or ordered per policy).
- User can adjust quantities/costs before save.

### Save behavior

- INSERT `vendor_bills` + INSERT `vendor_bill_items` in a transaction.
- Redirect to `view.php?id=...` with success message.

---

## 5. Vendor Bill view page

**File:** `stock/modules/purchases/vendor-bills/view.php`

### Sections

1. **Header** — Bill number, status badges, payment status, dates, currency.
2. **Supplier** — Name, contact (from `stocks_suppliers`).
3. **Linked PO** — PO number link to `view_po.php?id=...` if set.
4. **Line items table** — All `vendor_bill_items` with stock item name, amounts.
5. **Accounting summary** (posted only) — Journal ref `journal_entry_id`, link to journal view if exists; Dr/Cr summary text.
6. **Payment summary** — `total_amount`, `paid_amount`, `balance_due`.
7. **Linked Payment Vouchers** — List `payment_vouchers` WHERE `vendor_bill_id = :id` AND `company_id = :cid` (may be empty in Phase 4 until 4F).
8. **Linked Supplier Payments** — List `supplier_payments` WHERE `vendor_bill_id = :id` (Phase 5; show empty state in Phase 4).

### Action buttons (by status)

| Status | Buttons |
|--------|---------|
| `draft` | Edit, **Post**, Cancel |
| `posted` | **Create Payment Voucher** (4F), Cancel (only if no payments) |
| `cancelled` | View only |

**Post** ? confirm modal ? POST to `post.php`.  
**Create Payment Voucher** ? redirect to voucher create with query params (see §9).  
**Do not** add Mark Paid on this page in Phase 4.

---

## 6. Posting logic

**File:** `stock/modules/purchases/vendor-bills/post.php` (POST only, CSRF token)

### Preconditions

- Bill `status = draft`
- `company_id` matches session
- At least one line item
- `total_amount > 0`
- User has finance/admin role (reuse existing role checks from purchases approve)

### Steps (single DB transaction)

1. **Lock row** — `SELECT ... FROM vendor_bills WHERE id = ? AND company_id = ? FOR UPDATE`
2. **Revalidate** — rules in §8
3. **Build journal lines** via `AccountingService::postEntry()` (`includes/accounting_service.php`):
   - For each line (or one consolidated inventory line):
     - **Debit** `account_id` or default **Inventory/Stock** account — amount = line_total (or ex-VAT per policy)
   - **Credit** **Accounts Payable** control account — amount = `total_amount`
4. **Update `vendor_bills`:**
   - `status = 'posted'`
   - `posted_by = current user id`
   - `posted_at = NOW()`
   - `journal_entry_id =` returned entry id
   - `payment_status = 'unpaid'` (if not already)
   - `balance_due = total_amount - paid_amount` (paid_amount should be 0)
5. **Commit** — redirect to view with success

### Must not happen on post

| Action | Phase 4 |
|--------|---------|
| Debit/credit bank or cash | No |
| Insert `supplier_payments` | No |
| Update `payment_vouchers.is_paid` | No |
| Call `recordTransaction()` on `financial_accounts` | No |
| Call `ensureVoucherSyncToExpenses()` | No |
| Insert duplicate `erp_expenses` for full bill | No |

### Source metadata for journal

- `erp_journal_entries.reference` / description: e.g. `VB-{bill_number}`
- Prefer consistent `source_type` / `source_id` if columns exist on `erp_journal_entries`; otherwise store id only in `vendor_bills.journal_entry_id`

### Cancel posted bill (limited)

- Only if `paid_amount = 0` and no `supplier_payments` rows (Phase 5 check).
- Reverse journal policy: Phase 4E can set `status = cancelled` without auto-reversal; document that reversal JE is Phase 6+ if needed.

---

## 7. Accounts needed

Resolve from **`erp_accounts`** filtered by `company_id` (and `company_settings` overrides).

| Purpose | Account type | Setting key (suggested) |
|---------|--------------|-------------------------|
| **Accounts Payable** | liability | `default_ap_account_id` |
| **Inventory / Stock** | asset | `default_inventory_account_id` |
| **VAT input** (if used) | asset | `default_vat_input_account_id` |
| **Non-stock expense** | expense | `default_stock_expense_account_id` |

### Resolution order

1. `company_settings.setting_key` for company
2. Fallback: `erp_accounts` WHERE `type` = liability/asset and name/code LIKE `%payable%` / `%inventory%`
3. Block post with clear error if AP or inventory account missing (admin must configure chart)

### Line-level override

- `vendor_bill_items.account_id` allows non-stock lines to post to expense account.
- Stock lines use inventory default when `account_id` IS NULL.

### VAT handling (v1)

- If `tax_amount > 0` and VAT account configured: optional third journal line **Debit VAT input**; else include tax in inventory debit (simpler v1 — document chosen policy in `functions.php`).

---

## 8. Validation rules

| Rule | Enforcement |
|------|-------------|
| Supplier required | `supplier_id > 0` |
| `company_id` required | Set from `currentCompanyId()` on insert; reject 0 |
| Bill number unique per company | DB `UNIQUE (company_id, bill_number)` + catch duplicate on save |
| `total_amount > 0` | Before save and post |
| At least one line item | Before save and post |
| Line qty > 0 | Per line |
| Cannot post without items | `post.php` |
| Cannot edit posted bill | `edit.php` redirects unless admin “limited edit” (Phase 4: block all field edits except notes — optional) |
| Cannot cancel if payments exist | `paid_amount > 0` OR rows in `supplier_payments` |
| `balance_due = total_amount - paid_amount` | Recalculated on save and after any future payment |
| `payment_status` | `unpaid` on create/post; updated only by supplier payment logic (Phase 5) |
| Posted bill immutable amounts | No change to `total_amount` or lines after post |
| PO link | If `purchase_order_id` set, PO must belong to same `company_id` and supplier should match (warn or block) |

### Payment status derivation (helper)

| Condition | `payment_status` |
|-----------|------------------|
| `paid_amount <= 0` | `unpaid` |
| `0 < paid_amount < total_amount` | `partially_paid` |
| `paid_amount >= total_amount` | `paid` |
| `paid_amount > total_amount` | `overpaid` (only if policy allows) |

---

## 9. Link to Payment Voucher

**Phase 4F** — button on posted Vendor Bill view only.

### Flow

1. User clicks **Create Payment Voucher** on `view.php`.
2. Redirect to existing voucher create URL with prefill params, e.g.  
   `employee/create-voucher.php?vendor_bill_id=123`  
   (exact path per deployment; may be `/employee/create-voucher.php` or company-scoped route).

### Prefill mapping

| PV field / column | Source |
|-----------------|--------|
| `vendor_bill_id` | Bill id |
| `supplier_id` | Bill supplier |
| `payee_name` | Supplier name (keep for print) |
| `linked_stock_po_id` | Bill `linked_stock_po_id` / `purchase_order_id` |
| `total_amount` | Default full `balance_due` (user can lower for partial pay) |
| `requested_amount` | Same as requested pay amount |
| `approved_amount` | Empty until approval |
| `description` | e.g. `Payment for Vendor Bill {bill_number} / Inv {supplier_invoice_number}` |
| `payment_purpose` | `stock_purchase` |
| `currency` | Bill currency |

### Validation on PV create (Phase 4F / 5)

- Requested amount ? `vendor_bills.balance_due`
- Bill `status = posted`
- Bill `company_id` = PV `company_id`

### Print layout

- **No changes** to `#voucherFull`, `voucher-approvals-table.php`, or PDF export templates.
- Optional: show bill reference only in screen UI on voucher view, not on printed table (Phase 5+).

### mark-paid.php

- **Not modified in Phase 4.** Legacy path remains for PVs without `vendor_bill_id`.

---

## 10. Multi-company safety

| Rule | Implementation |
|------|----------------|
| Every SELECT | `WHERE company_id = :company_id` with `:company_id = (int) currentCompanyId()` |
| Every INSERT/UPDATE | Set `company_id` from session; never from unchecked POST |
| Line items | `vendor_bill_items.company_id` = header `company_id` |
| Joins | PO and supplier must match company (PO `company_id` column where present) |
| Attachments (future) | Path includes `company_id` |
| Ultimate vs Roadmaster | User in company A must not see company B bills (test H from Phase 3 plan) |

Use `getCompanySql()` / existing ERP helpers where already used in voucher module for consistency.

---

## 11. Backward compatibility

| Area | Phase 4 stance |
|------|----------------|
| Payment Voucher print | **No changes** |
| `mark-paid.php` | **No changes** |
| General expense PVs | Unchanged; no `vendor_bill_id` required |
| Existing PO ? PV link | `linked_stock_po_id` on PV still works without bill |
| `supplier-payments.php` mock page | Leave until Phase 5; new module is real AP |
| Phase 3B columns | Used but not required on old PVs |

Vendor Bills are **additive**: old workflows continue until users adopt bills + PV prefill.

---

## 12. Testing plan

Execute on local/staging after each sub-phase.

### Functional

| ID | Test | Expected |
|----|------|----------|
| T1 | Create draft bill with 2 lines | `status=draft`, `payment_status=unpaid`, `paid_amount=0`, `balance_due=total` |
| T2 | Edit draft bill | Lines and totals update |
| T3 | Post draft bill | `status=posted`, `journal_entry_id` set, JE Dr Inventory Cr AP |
| T4 | After post — bank | `financial_accounts` / `account_transactions` unchanged |
| T5 | After post — PV | No PV auto-created; `is_paid` unchanged |
| T6 | List filters | Supplier and status filters return correct rows |
| T7 | Duplicate bill number | Second save rejected per company |
| T8 | Post with zero lines | Blocked |
| T9 | Cancel draft | `status=cancelled` |
| T10 | Cancel posted with paid_amount > 0 | Blocked |

### Payment Voucher link (4F)

| ID | Test | Expected |
|----|------|----------|
| T11 | Create PV from posted bill | PV has `vendor_bill_id`, supplier, amount ? balance |
| T12 | PV print preview | Same layout as before (visual diff) |

### Multi-company

| ID | Test | Expected |
|----|------|----------|
| T13 | User company A | Cannot open company B bill by id (404/403) |
| T14 | Bill numbers | Same number allowed in different companies |

### Regression

| ID | Test | Expected |
|----|------|----------|
| T15 | Create general PV (no bill) | Works as before |
| T16 | Mark paid general PV | Legacy path unchanged |

---

## 13. Implementation phases

### Phase 4A — Functions / helpers

**Deliverables:**

- `stock/modules/purchases/vendor-bills/functions.php`
- Optional `includes/vendor_bill_service.php`

**Functions (suggested names):**

- `vendorBillTableExists()`
- `getVendorBillById($id, $companyId)`
- `listVendorBills($companyId, $filters, $limit, $offset)`
- `generateVendorBillNumber($companyId)`
- `saveVendorBillDraft($header, $lines)` — transaction
- `recalculateVendorBillTotals($billId)`
- `updateVendorBillPaymentStatus($billId)` — for future payments
- `getDefaultApAccountId($companyId)` / `getDefaultInventoryAccountId($companyId)`
- `postVendorBillToJournal($billId, $userId)` — wraps `AccountingService`

**Exit criteria:** Unit-testable helpers; no public pages yet.

---

### Phase 4B — List page

**Deliverables:**

- `index.php`
- Nav link from purchases index
- Filters + pagination + company scope

**Exit criteria:** T6, T13 (list portion).

---

### Phase 4C — Create page

**Deliverables:**

- `create.php` (+ optional `save.php`, `api/po-lines.php`)
- Draft save with line items and bill number generation

**Exit criteria:** T1, T7, T8 (create portion).

---

### Phase 4D — View + edit

**Deliverables:**

- `view.php`
- `edit.php` (draft only)

**Exit criteria:** T2, T9; view shows all sections except live supplier payments.

---

### Phase 4E — Post / cancel

**Deliverables:**

- `post.php`
- `cancel.php`
- Journal integration

**Exit criteria:** T3, T4, T5, T10; accounts configured in test company.

---

### Phase 4F — Create Payment Voucher button

**Deliverables:**

- Button on `view.php` (posted bills)
- Prefill handler in `employee/create-voucher.php` (read `vendor_bill_id` param only — minimal change isolated to create form, not print/mark-paid)

**Note:** 4F is the only touch outside `vendor-bills/` folder; still does not change print or mark-paid.

**Exit criteria:** T11, T12, T15.

---

### Phase 4 completion checklist

- [ ] All pages scoped by `company_id`
- [ ] Draft ? posted ? JE without bank movement
- [ ] No changes to `mark-paid.php` or voucher print HTML
- [ ] Documentation updated in README workflow (optional one paragraph)
- [ ] Ready for **Phase 5: Supplier Payments + mark-paid branch**

---

**End of Phase 4 plan — no PHP or UI implemented in this document.**
