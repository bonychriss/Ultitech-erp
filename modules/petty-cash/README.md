# Cash Book

Daily cash-in / cash-out recording (replaces the old Petty Cash voucher & replenishment workflow).

## Stack

- **Laravel domain:** `erp-laravel/app/Domains/CashBook`
- **React UI:** `modules/petty-cash/frontend`
- **Entry:** `cashbook.php` (via `modules/petty-cash/index.php`)
- **API:** `modules/petty-cash/api/index.php` ? `/api/cashbook/{resource}`

Module id remains `petty_cash` for `company_modules` compatibility; UI label is **Cash Book**.

## Features

- Multiple cash books with opening balance
- Cash in / cash out entries with category, party, remark
- Running balance on the ledger
- Categories & date-range reports

## Build UI

```bash
cd modules/petty-cash/frontend
npm install
npm run build
```

## Tables (auto-created)

- `cash_books`
- `cash_book_categories`
- `cash_book_entries`
