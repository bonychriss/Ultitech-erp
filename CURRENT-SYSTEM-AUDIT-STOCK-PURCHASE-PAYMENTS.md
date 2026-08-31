# Current System Audit — Stock Purchase Payments

**Document type:** Phase 2 discovery (read-only inspection)  
**Companion design doc:** `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`  
**Date:** 2026-05-23  
**Rule:** This audit describes what exists today. It does not change code or database.

---

## Executive summary

The ERP already has a mature **Payment Voucher** module (approvals, print, mark paid, attachments) and a **Stock** module with **two parallel purchase stacks** (modern `stocks_*` tables and legacy `purchases`). They are **partially linked** via `linked_stock_po_id` / `payment_voucher_id`, but the **target workflow** (Vendor Bill ? Payment Voucher ? Supplier Payment ? GL) is **not fully implemented**.

**Critical findings:**

| Area | Current state |
|------|----------------|
| Vendor Bills / AP subledger | **Not present** as a table or posting step |
| Payment Voucher | Acts as **approval + payee payment**; bank reduces on **mark paid**, not on approve |
| Supplier Payment entity | **No dedicated table**; payment is folded into PV `is_paid` + `account_transactions` |
| GL journal on voucher pay | **Inconsistent** (some admin paths post JE; `mark-paid.php` does not) |
| Stock PO ? PV link | **Exists** (`linked_stock_po_id`, `payment_voucher_id`) but optional |
| `company_id` | **Migration exists**; enforcement is **partial** across modules |

---

## Table of contents

- [A. Existing relevant files](#a-existing-relevant-files)
- [B. Existing relevant database tables](#b-existing-relevant-database-tables)
- [C. Existing Payment Voucher logic (summary)](#c-existing-payment-voucher-logic-summary)
- [D. Existing Stock Purchase logic](#d-existing-stock-purchase-logic)
- [E. Gap analysis vs README workflow](#e-gap-analysis-vs-readme-workflow)
- [F. Recommended safe implementation order](#f-recommended-safe-implementation-order)
- [G. Risks](#g-risks)
- [Detailed Payment Voucher Existing Structure Audit](#detailed-payment-voucher-existing-structure-audit)

---

## A. Existing relevant files

Paths are under `c:\xampp\htdocs\public_html\` unless noted. Duplicate deploy copies (`deploy/`, `erp/`, `erp_deployment_package/`) are omitted.

### Payment Vouchers (core ERP)

| Path | Purpose |
|------|---------|
| `employee/create-voucher.php` | Create PV: inserts `payment_vouchers` (`confirming`), `approvals`, `voucher_items`, attachments |
| `employee/includes/voucher-form-page.php` | Shared create/edit form UI and client logic |
| `employee/edit-voucher.php` | Edit PV; upload more attachments |
| `create-voucher.php` | Redirect to employee create |
| `edit-voucher.php` | Edit bridge |
| `my-vouchers.php`, `employee/my-vouchers.php` | User voucher lists |
| `all-vouchers.php`, `admin/all-vouchers.php` | All vouchers; admin list may post GL on pay in some paths |
| `view-voucher.php` | Main view: admin approve/reject, mark paid modal, print/PDF, mark posted |
| `employee/view-voucher.php` | Same features with `getCompanySql()` on queries |
| `employee/includes/view-voucher-header-actions.php` | Actions dropdown (download, print, mark paid, etc.) |
| `employee/approve_voucher.php` | AJAX line-approver signatures ? `approvals` |
| `mark-paid.php` | Finance mark paid: **debits `financial_accounts`**, SWIFT upload, syncs `erp_expenses` |
| `employee/mark-paid.php` | Mark paid **without** `ensureVoucherSyncToExpenses()` |
| `includes/functions.php` | Schema ensures, voucher helpers, attachments, expense sync |
| `includes/config.php` | Status constants (`confirming`, `pending`, `approved`, `rejected`) |
| `includes/voucher-approval-flow-data.php` | Approval stage data for view UI |
| `includes/voucher-approvals-table.php` | Printable voucher signatures table (on document) |
| `includes/user-avatar.php` | Avatars on approval flow |
| `includes/voucher-view-actions-styles.php` | Action bar styles |
| `delete_attachment.php` | Delete `voucher_attachments` row + file |
| `delete-voucher.php`, `employee/delete-voucher.php` | Hard delete PV |
| `export_voucher.php`, `includes/export_voucher.php` | Export |
| `voucher-preview.php` | Layout preview |
| `proxy_pdf.php` | Serve attachment PDFs |
| `assets/css/voucher-view-page.css` | View page layout (cards, docs, print shell) |
| `assets/css/voucher-form.css` | Form styling |
| `assets/css/approval-flow.css` | Approval timeline card |
| `assets/js/voucher-v5.v10.js` | Form client script |
| `modules/expenses/api/post_voucher.php` | Post paid voucher to expenses ledger; may **debit bank again** |
| `modules/balances/functions.php` | `recordTransaction()`, `financial_accounts`, balances |
| `modules/balances/accounts.php` | Bank/cash account UI |
| `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md` | Target workflow (Phase 1 design) |

### Stock — Purchases

| Path | Purpose |
|------|---------|
| `stock/modules/purchases/index.php` | Lists **both** `stocks_purchase_orders` and legacy `purchases` |
| `stock/modules/purchases/domestic_create.php` | Modern PO create; links approved PVs; updates `linked_stock_po_id` |
| `stock/modules/purchases/create.php` | **Legacy** PO create ? `purchases` / `purchase_items` |
| `stock/modules/purchases/view_po.php` | Stocks PO detail, supplier portal, emails |
| `stock/modules/purchases/domestic_receive.php` | Receive UI (partial qty) |
| `stock/modules/purchases/domestic_receive_process.php` | Posts receive; updates `stocks_po_items`, `stocks_transactions` |
| `stock/modules/purchases/receive_stock_po.php` | Auto-receive remaining qty (**company_id** scoped) |
| `stock/modules/purchases/receive.php` | **Legacy** full receive |
| `stock/modules/purchases/receipt_audit.php` | Lists `stocks_transactions` for a PO |
| `stock/modules/purchases/approve.php`, `cancel.php` | PO status changes |
| `stock/modules/purchases/edit.php`, `delete.php` | PO edit/delete (**company_id** on some) |
| `stock/modules/purchases/supplier-payments.php` | **Mock/demo UI only** (not real data) |
| `stock/modules/purchases/purchase_workflow.php` | Workflow status helpers |
| `stock/modules/purchases/po_mailer.php` | Supplier email |
| `ultimate/stock/modules/purchases/index.php` | Alias ? `stock/modules/purchases/index.php` |

### Stock — Suppliers, shipments, statements

| Path | Purpose |
|------|---------|
| `stock/modules/suppliers/index.php` | CRUD on legacy **`suppliers`** table (not `stocks_suppliers`) |
| `stock/modules/shipments/*` | Import shipments; `stocks_po_id` FK; receive updates stock |
| `stock/modules/statements/supplier.php` | Supplier statement = **sum of PO lines**; no real payments |
| `stock/config/database.php`, `stock/config/functions.php` | Stock DB + helpers |
| `deploy/database_update.sql` | Baseline `stocks_*` CREATE TABLE |
| `migrations/20260508_multicompany_phase2.sql` | Adds `company_id` to stock tables |

### Accounting / expenses / revenue

| Path | Purpose |
|------|---------|
| `includes/accounting_service.php` | `AccountingService::postEntry()` ? `erp_journal_entries` + lines |
| `includes/AccountingEngine.php` | Invoice/payment journal automation |
| `accounting/chart-of-accounts.php` | GL chart `erp_accounts` UI |
| `accounting/create-journal.php` | Manual journal UI |
| `api/journal-entries.php` | Journal API |
| `modules/expenses/api/expenses.php` | Expense CRUD |
| `revenue_entries.php`, `revenue_process.php` | Revenue + optional GL + liquidity credit |
| `reports/financial.php` | Financial reports; AP aging section (reporting, not subledger) |

### Company / tenant

| Path | Purpose |
|------|---------|
| `includes/functions.php` | `currentCompanyId()`, `getCompanySql()`, `companyScopeSql()` |
| `migrations/20260508_multicompany_phase2.sql` | `ensure_company_scope()` on many tables |
| `stock/config/database.php` | Optional per-company PDO (`stock_company_pdo`) |

---

## B. Existing relevant database tables

Sources: `database/ultimate_trading_voucher (1).sql`, `deploy/database_update.sql`, `erp_complete_schema.sql`, runtime `ensure*` in PHP, `migrations/20260508_multicompany_phase2.sql`.

### Payment Voucher cluster

| Table | Important columns | Notes |
|-------|-------------------|--------|
| **`payment_vouchers`** | `id`, `voucher_no`, `payee_name`, `payee_id`, `description`, `currency`, `total_amount`, `supporting_documents`, `applicant`, `department_manager`, `general_manager`, `prepared_by`, `checked_by`, `status`, `created_by`, `date_created`, `approved_by`, `approved_at`, `is_paid`, `paid_by`, `paid_at`, `swift_document`, `is_posted`, `posted_by`, `posted_at`, `payment_account_id`, `is_restricted` | Runtime: `company_id`, `purpose`, `linked_stock_po_id`, `linked_sales_order_id(s)` |
| **`voucher_items`** | `voucher_id`, `payment_type`, `budget_type`, `name`, `amount`, `description` | Line breakdown on PV |
| **`approvals`** | `voucher_id`, `approver_id`, `approver_name`, `role`, `status`, `signature_path`, `approved_at` | Per-signer workflow |
| **`approval_logs`** | `voucher_id`, `user_id`, `action`, `comments`, `created_at` | Audit trail (separate from `approvals`) |
| **`voucher_attachments`** | `voucher_id`, `file_path`, `original_name`, `mime_type`, `size_bytes`, `uploaded_by` | Supporting documents |

**`payment_vouchers.status` (typical):** `confirming` ? `pending` ? `approved` | `rejected`  
**Flags:** `is_paid`, `is_posted` (not the same as status)

### Stock — modern

| Table | Important columns |
|-------|-------------------|
| **`stocks_purchase_orders`** | `id`, `po_number`, `supplier_id`, `status`, `purchase_type` (`domestic`/`import`), `total_amount`, `currency`, `payment_voucher_id`, `company_id` (if migrated), negotiation/email fields |
| **`stocks_po_items`** | `po_id`, `item_id`, `qty_ordered`, `qty_received`, `unit_cost`, `landed_cost`, `company_id` |
| **`stocks_suppliers`** | `id`, `name`, contact fields, `company_id` |
| **`stocks_items`** | `id`, `sku`, `name`, `stock_quantity`, … |
| **`stocks_transactions`** | `item_id`, `type` (`in`), `quantity`, `reference_type` (`purchase_order`), `reference_id`, `company_id` |

**No `stocks_grn` or `vendor_bills` table found.**

### Stock — legacy

| Table | Important columns |
|-------|-------------------|
| **`purchases`** | `id`, `purchase_no`, `supplier_id`, `status`, `total_amount`, `company_id` (if migrated) |
| **`purchase_items`** | `purchase_id`, `product_id`, `quantity`, amounts |
| **`suppliers`** | Legacy supplier master (used by `stock/modules/suppliers` and legacy purchases) |
| **`products`**, **`stock`**, **`stock_movements`** | Legacy inventory |

### Shipments (import / outdoor)

| Table | Important columns |
|-------|-------------------|
| **`shipments`** | `stocks_po_id`, `supplier_id`, status, tracking fields |
| **`shipment_items`** | Line items |

GRN is represented by **receipt** (`qty_received`, `stocks_transactions`) or **shipment receive** + print label `GRN-{id}` in `stock/modules/shipments/print_receipt.php`.

### Bank / cash (liquidity)

| Table | Important columns |
|-------|-------------------|
| **`financial_accounts`** | `id`, `name`, `type` (bank/cash/mobile), `currency`, `opening_balance`, `current_balance`, `status`, `company_id` (if migrated) |
| **`account_transactions`** | `account_id`, `type` (debit/credit), `amount`, `description`, `reference_type`, `reference_id`, `transaction_date`, `company_id` |

### Chart of accounts (GL)

| Table | Important columns |
|-------|-------------------|
| **`erp_accounts`** | `id`, `code`, `name`, `type` (asset/liability/equity/revenue/expense), `parent_id`, `status`, `company_id` |
| **`erp_account_categories`**, **`erp_reporting_groups`** | COA metadata; reporting group can include “Accounts Payable” label |

**Note:** `modules/balances/coa_create.php` inserts into **`financial_accounts`**, not `erp_accounts` — two “COA” concepts.

### Journal entries (GL)

| Table | Important columns |
|-------|-------------------|
| **`erp_journal_entries`** | `id`, `entry_number` (or `reference` in some code paths), `date`, `description`, `status`, `created_by`, `company_id` |
| **`erp_journal_items`** | `journal_id`, `account_id`, `debit`, `credit`, `company_id` |

**Schema drift:** `AccountingService` vs `AccountingEngine` use different column sets — verify on each environment.

### Expenses (operational ledger)

| Table | Important columns |
|-------|-------------------|
| **`erp_expenses`** | `pv_id`, `source_type` (`voucher`/`receipt`), `amount`, `account_id` (GL expense), `source_account_id` (financial account), `status`, `is_posted`, `company_id` |

### Revenue (out of stock scope but related)

| Table | Important columns |
|-------|-------------------|
| **`revenue_entries`** | Sales revenue rows; optional `journal_entry_id` |
| **`revenue_ledger`** | Mirror ledger |

### Missing tables (per README target)

| Planned table | Status in codebase |
|---------------|-------------------|
| `vendor_bills` | **Not found** |
| `supplier_payments` | **Not found** (only mock PHP page) |
| `goods_received_notes` | **Not found** as table (logic via `qty_received` / shipments) |

---

## C. Existing Payment Voucher logic (summary)

### Creation

1. User submits `employee/create-voucher.php`.
2. Inserts `payment_vouchers` with `status = confirming` (constant `STATUS_CONFIRMING`).
3. Inserts `approvals` rows (Applicant, Department Manager, Checked By) as `pending`.
4. Inserts `voucher_items` and optional `voucher_attachments`.
5. Optional: `linked_stock_po_id` if column exists and form supplies PO id (`ensureVoucherStockPurchaseSchema()`).

### Approvals

1. **Line approvers** sign via `employee/approve_voucher.php` ? updates `approvals` + signature file.
2. When **all** `approvals` are `approved` ? `payment_vouchers.status = pending`.
3. **Admin final approve** via `view-voucher.php` / `employee/view-voucher.php` POST `admin_action=approved`:
   - Sets `status = approved`, `approved_by`, `approved_at`, `general_manager`.
   - Bulk-approves pending `approvals`.
   - **Blocked** if still `confirming`.
4. **Reject** sets `status = rejected`.

### Paid / posted flags

| Action | Updates | Bank/cash? |
|--------|---------|------------|
| Approve | `status = approved` | **No** |
| Mark paid (`mark-paid.php`) | `is_paid=1`, `swift_document`, `paid_at`, `payment_account_id` | **Yes** — `recordTransaction(..., 'debit', ...)` if account selected |
| Mark paid (root) | Also `ensureVoucherSyncToExpenses()` ? `erp_expenses`, sets `is_posted=1` on PV | No extra bank in sync |
| Mark posted (`markVoucherPosted()`) | `is_posted=1` only | **No** |
| `post_voucher.php` | `erp_expenses` + may debit bank again | **Risk of double debit** |

### Is bank affected too early?

- **Approved / pending:** Bank is **not** reduced (correct vs README).
- **Mark paid:** Bank **is** reduced immediately when finance selects `financial_accounts` account — this is the **actual payment moment** in today’s system, **not** a separate Supplier Payment entity.

---

## D. Existing Stock Purchase logic

### Purchase orders

**Two stacks:**

| Stack | Create | Table |
|-------|--------|-------|
| Modern | `stock/modules/purchases/domestic_create.php` | `stocks_purchase_orders` + `stocks_po_items` |
| Legacy | `stock/modules/purchases/create.php` | `purchases` + `purchase_items` |

**List page** (`index.php`) merges both; **“New Purchase”** button still points to **legacy** `create.php` (audit inconsistency).

**Statuses (modern):** `Pending`, `Approved`, `Supplier Responded`, `Received`, `Cancelled`, plus supplier-link states (`Draft`, `Pending Supplier`, etc.).

### Stock receiving (GRN)

- **No GRN table.** Receiving = increment `stocks_po_items.qty_received` + `stocks_transactions` (`type=in`, `reference_type=purchase_order`).
- **Domestic:** `domestic_receive_process.php`, `receive_stock_po.php`.
- **Legacy:** `receive.php` ? `stock` / `stock_movements`.
- **Import:** `stock/modules/shipments/receive.php`; GRN-style print in `print_receipt.php`.

### Supplier purchase amounts

- Recorded on PO: `stocks_po_items` (`qty_ordered`, `unit_cost`) and `stocks_purchase_orders.total_amount`.
- **Not** posted to Accounts Payable or `vendor_bills`.

### Supplier debt tracked?

| Mechanism | Tracks AP/debt? |
|-----------|-----------------|
| `vendor_bills` | **No table** |
| Supplier statement (`statements/supplier.php`) | **PO totals only**; comment says payments not modeled |
| `supplier-payments.php` | **Hardcoded demo data** |
| Payment voucher link | Links spend approval to PO; **not** bill balance |

---

## E. Gap analysis vs README workflow

Comparison against `README-STOCK-PURCHASE-PAYMENT-WORKFLOW.md`.

### Missing tables

| README table | Current system |
|--------------|----------------|
| `vendor_bills` | Missing |
| `supplier_payments` | Missing |
| `goods_received_notes` | Missing (receipt via columns/transactions) |
| Dedicated `purchase_orders` (ERP naming) | Exists as `stocks_purchase_orders` + legacy `purchases` |

### Missing columns (on existing tables)

| Concept | Suggested column | Current |
|---------|------------------|---------|
| Bill link on PV | `vendor_bill_id` | **Missing** |
| GRN link on PV | `grn_id` | **Missing** |
| Supplier on PV | `supplier_id` | **`payee_name` / `payee_id`** only (payee may be supplier) |
| Approved pay amount | `approved_amount` | Uses `total_amount` only |
| Supplier payment link | `supplier_payment_id` | **Missing** |
| JE link | `journal_entry_id` on payment | **Missing** on PV |
| Bill balances | `paid_amount`, `balance_due`, `payment_status` on bill | **No bill table** |

### Partially present

| Concept | Current |
|---------|---------|
| `purchase_order_id` | `payment_vouchers.linked_stock_po_id` ? `stocks_purchase_orders.payment_voucher_id` |
| `bank_or_cash_account_id` | `payment_vouchers.payment_account_id` |
| `company_id` | Added by migration; not all queries filter |

### Missing relationships

- Vendor Bill ? Payment Voucher (no bill entity).
- Payment Voucher ? Supplier Payment (payment is `is_paid` + `account_transactions`).
- PO ? Vendor Bill (no bill step).
- GRN ? Vendor Bill (no bill step).

### Missing status rules (README)

| README rule | Current |
|-------------|---------|
| PV `draft`, `pending_approval`, `paid` as status enum | Uses `confirming`/`pending`/`approved` + **`is_paid` flag** |
| Bank reduces only when `paid` + Supplier Payment | Bank reduces on **mark paid** (aligned in timing, wrong entity model) |
| Bill `partially_paid` | Not implemented |

### Double-posting risks

1. **`mark-paid.php`** debits `financial_accounts` via `recordTransaction`.
2. **`modules/expenses/api/post_voucher.php`** may debit again for same PV.
3. Some **admin** paths also post **`erp_journal_entries`** while (1) already ran — potential **triple** effect if all used.
4. Future **Vendor Bill** posting + current **mark paid** expense debit = risk unless roles are separated.

### Missing `company_id` filters (examples)

| Location | Issue |
|----------|--------|
| `mark-paid.php` voucher SELECT | No `company_id` in query shown |
| `stock/modules/purchases/view_po.php`, `approve.php` | No company filter |
| `stock/modules/suppliers/index.php` | Legacy `suppliers`, no company filter |
| `accounting/chart-of-accounts.php` | May omit company scope |
| Tenant DB mode | `companyScopeSql()` empty when `IS_TENANT_DB` — relies on separate database |

---

## F. Recommended safe implementation order

Aligned with README Phase 3–8 and current gaps:

| Order | Step | Why safe |
|-------|------|----------|
| **1** | **Vendor Bills module** (new table + UI) | Establishes liability without changing PV mark-paid yet |
| **2** | **Link Payment Vouchers to Vendor Bills** (`vendor_bill_id`, amounts, validation) | Extends PV; keep print layout unchanged |
| **3** | **Supplier Payments table** + posting from approved/paid PV | Separates “approval” from “cash movement” cleanly |
| **4** | **Journal entries** (single path: bill post, payment post) | Unify `AccountingService`; avoid duplicate debits |
| **5** | **Reports** (supplier statement, AP aging from bills) | Read-only on stable data |
| **6** | **Stock integration** (PO/GRN ? bill creation) | After bill core works |
| **7** | **Retire/fix** legacy purchase stack routes (`index.php` ? `domestic_create.php`) | Operational consistency |

**Do not start by changing `mark-paid.php` behavior** until Supplier Payment and bill balance rules are defined — otherwise finance loses audit trail.

---

## G. Risks

| Risk | Description |
|------|-------------|
| **Production schema changes** | `ensure*` functions ALTER tables at runtime; formal migrations still need backup |
| **Voucher print layout** | `includes/voucher-approvals-table.php`, inline voucher tables — do not change for layout project |
| **Mixing PV with Vendor Bill** | Users may think paying PV settles supplier debt that was never booked on AP |
| **Bank before “paid”** | Today bank does **not** move on approve (good); do not add debit on approve when implementing bills |
| **Double bank debit** | `mark-paid.php` + `post_voucher.php` + admin GL |
| **Multi-company** | Partial filters; wrong company data if tenant uses shared DB with NULL `company_id` |
| **Dual purchase stacks** | Legacy vs modern PO; wrong receive path breaks inventory |
| **Upload paths** | `assets/uploads/vouchers/{id}/` — must stay company-safe if shared storage |
| **Two supplier masters** | `suppliers` vs `stocks_suppliers` — wrong supplier on bill/link |
| **Two COA systems** | `erp_accounts` vs `financial_accounts` — wrong account picker on payment |

---

# Detailed Payment Voucher Existing Structure Audit

This section documents **only** the Payment Voucher module as it exists today.

---

## 1. Payment Voucher files

### Create / edit

| File | What it does | Accounting impact |
|------|----------------|-------------------|
| `employee/create-voucher.php` | Inserts PV, approvals, items, attachments | None at create |
| `employee/includes/voucher-form-page.php` | Form UI | None |
| `employee/edit-voucher.php` | Edit draft/confirming/pending PV | None |
| `assets/js/voucher-v5.v10.js`, `assets/css/voucher-form.css` | Client UX | None |

**Important functions (create path):** inserts into `payment_vouchers`, `voucher_items`, `approvals`; calls `ensureVoucherStockPurchaseSchema()`, `addVoucherAttachment()`, `generateVoucherNumber()`.

### List pages

| File | What it does |
|------|----------------|
| `my-vouchers.php`, `employee/my-vouchers.php` | User’s vouchers |
| `admin/all-vouchers.php` | All vouchers; may include GL posting on some pay actions |
| `export_vouchers_list.php` | Export list |

### View / print / PDF

| File | What it does |
|------|----------------|
| `view-voucher.php` | Primary view: cards, approval flow, docs, modals |
| `employee/view-voucher.php` | Same; company-scoped SQL on some queries |
| `includes/voucher-approvals-table.php` | **Official printable signature block** on voucher document (do not change layout) |
| `includes/voucher-approval-flow-data.php` | Timeline data |
| `assets/css/voucher-view-page.css` | Page + print shell (hide sidebar on print) |
| `assets/css/approval-flow.css` | Approval card styles |
| Print/PDF | Browser `@media print` + `html2pdf.js` in view pages; `printVoucher()` JS |

**Accounting impact of view:** None (display only). Mark paid modal triggers POST to `mark-paid.php`.

### Approval workflow files

| File | What it does |
|------|----------------|
| `employee/approve_voucher.php` | Line approver JSON API |
| `view-voucher.php` / `employee/view-voucher.php` | Admin `admin_action` approve/reject |
| `includes/functions.php` | `approveVoucherByAdmin()`, `rejectVoucherByAdmin()`, `logVoucherAction()` |

### Mark paid / posting files

| File | What it does | Accounting impact |
|------|----------------|-------------------|
| `mark-paid.php` | SWIFT upload, `recordTransaction` debit, `is_paid=1`, expense sync | **Bank ?**, `erp_expenses`, optional `is_posted=1` |
| `employee/mark-paid.php` | Same without expense sync | **Bank ?** only |
| `includes/functions.php` | `markVoucherPosted()`, `markVoucherPaidStrict()`, `ensureVoucherSyncToExpenses()` | Posted flag / expense row |
| `modules/expenses/api/post_voucher.php` | Expense module post | **Bank ?** again possible |

### Attachments

| File | What it does |
|------|----------------|
| `includes/functions.php` | `addVoucherAttachment()`, `deleteVoucherAttachment()`, `getVoucherAttachments()` |
| `delete_attachment.php` | AJAX delete |
| Upload on create/edit | Writes under `assets/uploads/vouchers/{voucher_id}/` |
| SWIFT proof | `mark-paid.php` ? `assets/uploads/vouchers/{id}/swift-proof-*` |
| DB table | `voucher_attachments` |

### PDF / export

| File | What it does |
|------|----------------|
| `includes/export_voucher.php` | Export bridge |
| Client-side PDF | `html2pdf.js` on view page |

---

## 2. Payment Voucher database structure

### Primary table: `payment_vouchers`

| Column | Purpose |
|--------|---------|
| `id` | Primary key |
| `voucher_no` | Unique reference (e.g. `PV/UGC/2026/239`) |
| `payee_name` | Payee (often supplier name text) |
| `payee_id` | Optional link to payee master |
| `description`, `currency`, `total_amount` | Payment details |
| `supporting_documents` | Legacy count |
| `applicant`, `department_manager`, `general_manager`, `prepared_by`, `checked_by` | Names on printed voucher |
| `status` | `confirming`, `pending`, `approved`, `rejected` |
| `created_by`, `date_created` | Creator audit |
| `approved_by`, `approved_at` | Admin final approval |
| `is_paid`, `paid_by`, `paid_at` | Payment execution flags |
| `swift_document` | Payment proof file path |
| `is_posted`, `posted_by`, `posted_at` | Bookkeeping flag |
| `payment_account_id` | FK to `financial_accounts` used when paid |
| `is_restricted` | Confidential voucher flag |
| `company_id` | Multi-company (if migrated) |
| `purpose` | e.g. `general` vs stock-related (runtime) |
| `linked_stock_po_id` | Link to `stocks_purchase_orders.id` (runtime) |

### Related tables

| Table | Role |
|-------|------|
| `voucher_items` | Line items (budget type, amount) |
| `approvals` | Per-role signer status + signature |
| `approval_logs` | Action history |
| `voucher_attachments` | Supporting files metadata |

### Status values (actual)

**`payment_vouchers.status`:** `confirming` ? `pending` ? `approved` | `rejected`  
**Not the same as README’s** `draft` / `pending_approval` — map mentally:

| README | Current equivalent |
|--------|-------------------|
| draft | `confirming` (or derived “draft” display when incomplete) |
| pending_approval | `pending` (all line approvers done) |
| approved | `approved` |
| rejected | `rejected` |
| paid | **`is_paid = 1`** (not a status enum value) |
| cancelled | **No dedicated value** (use rejected or soft-delete policy) |

---

## 3. Payment Voucher current workflow

```
CREATE (confirming)
    ? line approvers sign (approvals)
ALL APPROVALS approved ? status = pending
    ? admin final approve
status = approved
    ? finance mark paid (+ SWIFT + bank account)
is_paid = 1, bank debited, erp_expenses sync (root path)
    ? optional
is_posted = 1 (via sync or markVoucherPosted or post_voucher API)
```

**Pending/approved vouchers do not reduce bank** — confirmed in `mark-paid.php` (requires `status === approved` before pay).

---

## 4. Approval workflow

| Question | Answer |
|----------|--------|
| Who creates? | Logged-in user via `employee/create-voucher.php` ? `created_by` |
| Who approves (line)? | Users matching `approvals.approver_id` or `approver_name` |
| Who checks? | Role name **Checked By** in `approvals` |
| Multiple levels? | Yes: Applicant, Department Manager, Checked By (+ optional extra approvers) |
| Admin final? | User with admin role via `admin_action` or `approveVoucherByAdmin()` |
| History storage | `approval_logs` + `approvals` statuses |
| Rejected? | `status = rejected`; should not be paid |
| Cancelled? | No standard `cancelled` status on PV |

Signatures stored in `approvals.signature_path` (file path) and shown on print via `voucher-approvals-table.php`.

---

## 5. Payment / mark as paid logic

When finance marks paid via **`mark-paid.php`**:

1. Validates `status === approved` and admin approver (for finance users).
2. Requires **SWIFT** file upload.
3. If `account_id` posted: **`recordTransaction($accountId, 'debit', $amount, ..., 'payment_voucher', $voucher_id)`**
   - Inserts `account_transactions`
   - Recalculates `financial_accounts.current_balance`
4. Updates PV: `swift_document`, `is_paid=1`, `paid_by`, `payment_account_id`, `paid_at`.
5. Calls **`ensureVoucherSyncToExpenses()`** (root only) ? row in `erp_expenses`, sets PV `is_posted=1`.

**Does NOT automatically:**

- Create `erp_journal_entries` in `mark-paid.php` (unless separate admin code path used).
- Update any **vendor bill balance** (no bill table).
- Create **`supplier_payments`** row.

**Employee `mark-paid.php`:** steps 3–4 only (no expense sync).

---

## 6. Accounting impact

| Question | Current behavior |
|----------|------------------|
| Debit expense on approve? | **No** |
| Credit bank on approve? | **No** |
| Debit bank on mark paid? | **Yes** (liquidity module) |
| Credit AP? | **No** (no AP posting on PV) |
| `erp_journal_entries`? | **Sometimes** on admin dashboard / all-vouchers pay — **not** in `mark-paid.php` |
| `erp_expenses`? | **Yes** on root mark-paid via sync |
| Revenue ledger? | **No** |
| Balances module? | **Yes** — primary cash impact |

**Double posting risk when adding Vendor Bills:**

- If Vendor Bill posts **Dr Inventory / Cr AP**, and mark-paid still debits bank + expense without **Dr AP / Cr Bank**, reports will disagree.

---

## 7. Supplier link

| Link type | Supported? |
|-----------|------------|
| `supplier_id` on PV | **No dedicated column** — `payee_id` / `payee_name` |
| Purchase order | **Yes** — `linked_stock_po_id` (runtime); reverse `stocks_purchase_orders.payment_voucher_id` |
| Vendor bill / supplier invoice | **No** |
| Stock purchase (PO) | **Partial** via `domestic_create.php` linking approved PVs |
| GRN | **No** direct column on PV |

Stock procurement can attach an **approved** PV when creating PO (`domestic_create.php` filters vouchers not already linked).

---

## 8. Attachments / supporting documents

| Topic | Detail |
|-------|--------|
| DB | `voucher_attachments` |
| Upload path | `assets/uploads/vouchers/{voucher_id}/` (via `ensureVoucherUploadsDir()`) |
| SWIFT | Same tree, `swift-proof-*` filename |
| Proxy | `proxy_pdf.php?file=...` |
| `company_id` on attachment rows | Verify per deployment; path is per voucher id |
| Cross-company risk | If voucher ids collide across tenants in shared DB, paths could conflict — mitigate with `company_id` on PV and access checks |
| Delete | `deleteVoucherAttachment()`; blocked when PV approved (policy in function) |

---

## 9. Print layout

| Item | Location |
|------|----------|
| Official voucher table | Inline HTML tables in `view-voucher.php` / `employee/view-voucher.php` (`#voucherFull`) |
| Signature block | `includes/voucher-approvals-table.php` |
| Page/print CSS | `assets/css/voucher-view-page.css` (`@media print` hides sidebar, approval card, docs) |
| Approval UI card | `approval-flow.css` — hidden on print |

**Safe to keep unchanged during stock-payment project:**

- Voucher table HTML structure (black borders, columns, signature layout).
- `voucher-approvals-table.php` print structure.
- Print CSS that hides non-voucher chrome.

**Safe to extend:**

- Page **surrounding** cards (preview header, supporting docs, approval flow).
- New fields **outside** the printable `#voucherFull` region.

---

## 10. Required changes later (to support README workflow)

Add via **migrations** (nullable first), not breaking existing rows:

| Field / table | Purpose |
|---------------|---------|
| **`vendor_bills`** table | Supplier invoice / AP liability |
| `payment_vouchers.vendor_bill_id` | Link PV to bill |
| `payment_vouchers.requested_amount`, `approved_amount` | Split request vs approved pay |
| `payment_vouchers.supplier_id` | Normalize supplier FK |
| `payment_vouchers.grn_id` | Optional traceability |
| **`supplier_payments`** table | Actual payment record |
| `supplier_payments.payment_voucher_id` | 1:1 with executed voucher |
| `supplier_payments.journal_entry_id` | GL link |
| `payment_vouchers.supplier_payment_id` | Back-link |
| `vendor_bills.paid_amount`, `balance_due`, `payment_status` | Partial pay |
| Refactor mark-paid | Create `supplier_payment` then debit bank; optional Dr AP / Cr Bank JE |

---

## 11. Final recommendation (Payment Voucher)

### Keep unchanged

- Printable voucher HTML (`#voucherFull`, `voucher-approvals-table.php`).
- Approval chain UX (confirming ? pending ? approved).
- Attachment upload mechanics and paths (add company checks only if missing).
- SWIFT requirement on mark paid (finance control).

### Extend safely

- Add **`vendor_bill_id`** and validation against bill balance before pay.
- Add **`supplier_payments`** and route **bank debit** through that entity.
- Unify **`mark-paid.php`** and **`employee/mark-paid.php`** (both should sync expenses or neither).
- Add **`company_id`** to all PV SELECT/UPDATE in mark-paid and lists.
- Keep **`linked_stock_po_id`**; add bill creation from PO/GRN in stock module.

### Do not touch (without explicit approval)

- Internal voucher table borders/rows for print.
- `approvals` role names used on printed form (Applicant, Dept Manager, etc.).
- Legacy `payee_name` display on voucher face.

### Risks

- Treating PV as supplier bill ? **overstates expense, understates AP**.
- Bank debit without AP clearance ? **cash reports ? supplier statement**.
- Multiple debit paths ? **reconcile `account_transactions` vs `erp_expenses` vs JE**.

### How PV should connect (target)

```
Vendor Bill (INV) ??balance_due???
                                ??? Payment Voucher (approval, ? balance)
                                ??? Supplier Payment (bank movement, JE: Dr AP Cr Bank)
```

**Payment Voucher** remains the **approval and control document**.  
**Vendor Bill** records **debt**.  
**Supplier Payment** records **cash** — replacing the informal `is_paid` + loose `recordTransaction` as the long-term model (can keep `is_paid` as derived flag during transition).

---

## Phase 2 completion checklist

- [x] Payment Voucher files inventoried  
- [x] Stock purchase files inventoried  
- [x] Database tables documented from schema + runtime ensures  
- [x] PV workflow and mark-paid accounting documented  
- [x] Gap analysis vs README completed  
- [x] Implementation order and risks documented  
- [x] Detailed PV audit section completed  

**Next step (Phase 3):** Schema gap matrix + safe migration plan — **do not implement until approved.**

---

**End of audit — no code or database changes were made.**
