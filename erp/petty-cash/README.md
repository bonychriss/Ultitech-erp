# Petty Cash Module

Simple **custodian-float** tracking: top-ups (replenishment), expense vouchers, and per-user petty cash balances. Independent from **Balances** (`financial_accounts`) and **Expenses** (`erp_expenses`) � no `recordTransaction()` calls.

---

## Quick links

| Page | URL | Purpose |
|------|-----|---------|
| Dashboard | `/erp/petty-cash/index.php?module=petty_cash` | Stats, vouchers, replenishments |
| New voucher | `/erp/petty-cash/create-voucher.php?module=petty_cash` | Submit spending request |
| Replenishment | `/erp/petty-cash/replenishment.php?module=petty_cash` | Request float top-up |
| View voucher | `






/erp/petty-cash/view-voucher.php?id={id}` | Detail + approve/reject/cancel |
| Reports | `/erp/petty-cash/reports.php?module=petty_cash` | By date, category, custodian |

**Core logic:** `erp/petty-cash/includes/petty_cash_functions.php` (loaded via `includes/functions.php`).

---

## Tables

### `petty_cash_balance`
One wallet per custodian (`users.id`).

| Column | Purpose |
|--------|---------|
| `opening_balance` | Initial float (default 0) |
| `current_balance` | Available petty cash |
| `status` | `active` / `inactive` |

### `petty_cash_vouchers`
Spending requests.

**Statuses:** `pending` ? `approved` | `rejected` | `cancelled`

### `petty_cash_replenishments`
Top-up requests.

**Statuses:** `pending` ? `approved` | `rejected` | `cancelled`

---

## Balance rules

| Event | Balance change |
|-------|----------------|
| Voucher created | None (`pending`) |
| Voucher approved | **Subtract** amount (if sufficient balance) |
| Voucher rejected | None |
| Voucher cancelled (was approved) | **Add** amount back |
| Voucher cancelled (was pending) | None |
| Replenishment created | None (`pending`) |
| Replenishment approved | **Add** amount |
| Replenishment rejected | None |
| Replenishment cancelled (was approved) | **Subtract** amount |

All approve/reject/cancel operations use **database transactions** with row locking (`FOR UPDATE`).

---

## Functions

| Function | Purpose |
|----------|---------|
| `ensurePettyCashSchema()` | Create/migrate tables |
| `getPettyCashBalance($custodian_id)` | Read active float |
| `updatePettyCashBalance($custodian_id, $amount, $operation)` | Add/subtract (internal) |
| `createPettyCashVoucher($data)` | Insert pending voucher |
| `approvePettyCashVoucher($id, $approved_by)` | Approve + deduct (returns `true` or error string) |
| `rejectPettyCashVoucher($id, $approved_by, $reason)` | Reject pending |
| `cancelPettyCashVoucher($id)` | Cancel + reverse if approved |
| `createPettyCashReplenishment($data)` | Insert pending top-up |
| `approvePettyCashReplenishment($id, $approved_by)` | Approve + add balance |
| `rejectPettyCashReplenishment($id, $approved_by, $reason)` | Reject pending |
| `cancelPettyCashReplenishment($id)` | Cancel + reverse if approved |
| `getPettyCashDashboardStats($custodian_id?)` | Dashboard KPIs |
| `getAllPettyCashBalances()` | Per-custodian list |
| `pettyCashCanManage()` | Admin/Finance check |

---

## Access control

| Role | Can do |
|------|--------|
| **Custodian** (any logged-in user) | Create vouchers, request replenishment, view own records |
| **Admin / Finance** | Approve, reject, cancel; view all custodians; reports for all |

---

## Not connected (by design)

- Does **not** post to `erp_expenses`
- Does **not** debit `financial_accounts`
- Does **not** call `recordTransaction()`

Future integration with Balances/Expenses is planned but not implemented.

---

## Related

- Expenses module: `modules/expenses/README.md`
- Balances module: `modules/balances/`
