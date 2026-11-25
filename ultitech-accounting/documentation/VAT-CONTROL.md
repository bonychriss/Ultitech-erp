# VAT Control (`#/tax`)

In-ERP desk for sales/purchase tax (mainly VAT) inside UltiTech Accounting.

**Local URL**

`http://localhost/public_html/ultimate/accounting/ultitech-bridge?module=accounting#/tax`

**Live (company folder)**

`https://ultitech.io/ultimate/accounting/ultitech-bridge?module=accounting#/tax`

Sidebar label: **VAT Control**.

---

## What it is for

1. Show jurisdiction **rates** and company **tax codes** (e.g. VAT 18%).
2. List **tax transactions** (output vs input) that make up the VAT register.
3. **Prepare** a VAT return for a date range (sums output, input, net).
4. **Reconcile** ? **File** (TRA reference) ? **Pay** (bank settlement + GL) ? period **locked**.
5. **Add adjustment** for simple credit/debit note VAT (negative tax allowed).

It is not for entering expenses, journals, or payment vouchers. Those modules create the underlying docs; this desk reads the tax register and settles returns.

### Simple status lifecycle

`draft` ? `reconciled` ? `filed` ? `paid` (locked)

| Step | Action | Result |
|------|--------|--------|
| Prepare | Period + tax type | Draft return |
| Reconcile | Compare to GL VAT controls | Status reconciled |
| File | Filing date + TRA reference | Status filed |
| Pay | Amount + bank account | GL journal, status paid, period locked |
| Adjust | Manual VAT line | Tax transaction (`tax_adjustment`) |

---

## Screen layout

| Area | Purpose |
|------|---------|
| KPIs | Output VAT, Input VAT, Net VAT, Returns count, Transactions count |
| Search / filters | Find returns; filter return status (draft / reconciled / filed) |
| **Prepare return** | Modal: tax type + period ? creates a draft return |
| Tabs | Returns ù Transactions ù Rates ù Codes |

---

## Where tax amounts come from

### Output VAT (sales)

- Customer invoices in Accounting that have `tax_total` &gt; 0.
- Includes invoices imported from ERP `invoices.tax_amount`.
- Register source: `customer_invoice` + local invoice id.
- Also created live when you post an invoice **inside Accounting** with a `tax_code_id`.

### Input VAT (purchases)

- ERP stock `purchases` rows with `tax_amount` &gt; 0 (non-cancelled).
- Register source: `erp_purchase` + ERP purchase id.
- Also created when you post a supplier bill **inside Accounting** with a `tax_code_id`.

### Not included (yet)

- Classic payment vouchers with `tax_type = vat` (usually TRA remittances, not purchase input VAT).
- Expense receipts without a taxed Accounting bill.

---

## Sync tax register

Runs **automatically** when you open VAT Control (no button). Same endpoint remains available for scripts.

1. Ensures a company **VAT18** tax code exists for the bookùs jurisdiction (when catalog rates are present).
2. Backfills **output** rows from Accounting customer invoices (`tax_total`).
3. If ERP sync is enabled, imports **input** rows from ERP `purchases`.
4. Upserts by `(company, book, source_type, source_id, direction)` so re-runs do not duplicate.

**API**

```http
POST /api/v1/tax/sync-register
Permission: tax.manage
Headers: Authorization, X-Company-Id, X-Book-Id
```

Response includes `output` stats, `input_synced`, optional `erp_error`, and total `transactions`.

ERP invoice pull (`POST /api/v1/erp/pull` with scope `invoices`) also refreshes output + input tax after invoices upsert.

---

## Prepare & reconcile a return

1. Open VAT Control (register syncs on load).
2. **Prepare return** ? choose tax type (usually VAT) and period.
3. System selects posted tax transactions in that period for the bookùs jurisdiction/type, totals output ? input, and creates a **draft** return with lines.
4. On a draft row, **Reconcile** compares return net to mapped GL accounts `vat_output` / `vat_input` on the trial balance.

**APIs**

| Method | Path | Permission |
|--------|------|------------|
| GET | `/tax-types`, `/tax-rates`, `/tax-codes` | `tax.view` |
| GET | `/tax-transactions`, `/tax-returns` | `tax.view` |
| POST | `/tax-returns` | `tax.manage` |
| POST | `/tax-returns/{id}/reconcile` | `tax.manage` |
| POST | `/tax-returns/{id}/file` | `tax.manage` |
| POST | `/tax-returns/{id}/pay` | `tax.manage` |
| POST | `/tax/adjustments` | `tax.manage` |
| POST | `/tax/calculate` | `tax.view` |
| POST | `/tax/sync-register` | `tax.manage` |

Payment posts: **Dr** `vat_output` / **Cr** bank (payable), or reverse for refund received. Requires account mapping `vat_output` and a bank with `gl_account_id`.

---

## Key backend pieces

| Piece | Role |
|-------|------|
| `TaxEngineService` | Calculate, record/upsert transactions, prepare return, reconcile, backfill invoices, ensure VAT18 |
| `ErpSyncService::syncTaxRegister` / `syncPurchaseTaxTransactions` | ERP purchase input VAT + invoice backfill |
| `ReceivableService` / `PayableService` | Record tax on native Accounting invoice/bill post |
| `TaxController` | HTTP API for the desk |
| `TaxControlPage.tsx` | React UI (`#/tax`) |

Tables (prefix `acc_`): `tax_types`, `tax_rates`, `tax_codes`, `tax_transactions`, `tax_returns`, `tax_return_lines`, `tax_reconciliations`.

---

## Typical workflow

1. Open Accounting ? **VAT Control** (register syncs automatically).
2. Review **Transactions**. Use **Add adjustment** for credit-note VAT.
3. **Prepare return** ? **Reconcile** ? **File** (TRA ref) ? **Pay** (bank).
4. Paid returns are **locked** (no overlapping prepare / re-file).

If KPIs stay at zero, invoices/purchases may have no tax amounts for this company book, or ERP sync may be off.

### Intentionally simple (not built yet)

Full TRA e-filing, WHT/PAYE desks, auto credit-note detection, tax-inclusive pricing rules, and a separate Tax hub nav. Those can layer on later without changing this basic path.

---

## Related docs

- [ERP-INTEGRATION-READONLY.md](./ERP-INTEGRATION-READONLY.md) ù read-only ERP pull
- Milestone 05 session notes ù tax engine and standards tests
