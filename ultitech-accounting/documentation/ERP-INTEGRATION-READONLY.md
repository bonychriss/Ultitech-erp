# ERP ↔ UltiTech Accounting (read-only link)

## Goal

Connect **UltiTech Accounting** to the existing UltiTech ERP so you can test that data flows correctly — **without changing or deleting anything in ERP accounting**.

## Safety rules

| Rule | Enforcement |
|------|-------------|
| No ERP schema/migrations from Accounting | Separate DB `ultitech_accounting_dev` only |
| No INSERT/UPDATE/DELETE on ERP | `ReadOnlyErpGateway` rejects non-SELECT SQL |
| No edits under `public_html/accounting` or `includes/gl` | Integration lives only in `ultitech-accounting/` |
| Existing `gl_journal_*` / `erp_accounts` untouched | Pull copies into Accounting |

## ERP source (local)

- Database: `new_trading_voucher-35313030c7e2` (Ultimate tenant)
- Tables read: `erp_accounts`, `gl_journal_entries`, `gl_journal_lines`, `customers`, `suppliers`

## How to test

1. Ensure Accounting `.env` has:
   ```env
   ERP_SYNC_ENABLED=true
   ERP_DB_DATABASE=new_trading_voucher-35313030c7e2
   ERP_SYNC_JOURNAL_LIMIT=50
   ```
2. Migrate Accounting DB (never the ERP DB):
   ```powershell
   cd C:\xampp\htdocs\public_html\ultitech-accounting\backend
   C:\xampp\php\php.exe artisan migrate
   C:\xampp\php\php.exe artisan db:seed
   ```
3. Test connection only:
   ```powershell
   C:\xampp\php\php.exe artisan erp:sync --test
   ```
4. Pull a small batch:
   ```powershell
   C:\xampp\php\php.exe artisan erp:sync --journal-limit=20
   ```
5. Or use UI: **ERP Link** in the Accounting sidebar → Test → Run read-only sync.

## What gets created (Accounting only)

- CoA rows mapped from `erp_accounts`
- Customers / suppliers masters
- Imported journals with `source_hash = erp:gl_journal:{id}` (idempotent re-runs skip duplicates)

## After it works

We can later discuss cutover / dual-write / replacing ERP GL screens — **only after** you confirm this read-only path is correct.
