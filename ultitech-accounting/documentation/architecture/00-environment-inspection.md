# Environment Inspection Report

**Date:** 2026-11-17  
**Inspector:** UltiTech Accounting bootstrap  
**Scope:** Local XAMPP development host only. No ERP files modified.

## Host paths

| Path | Status | Notes |
|---|---|---|
| `C:\xampp\htdocs\public_html\` | Present | Existing UltiTech ERP root — **read-only for this project** |
| `C:\xampp\htdocs\public_html\accounting\` | Present (~36 items) | Existing ERP accounting area — **do not modify** |
| `C:\xampp\htdocs\public_html\ultitech-accounting\` | Created | New standalone product root |
| `C:\xampp\htdocs\Accounting\` | Empty workspace | Cursor workspace; product lives under `public_html\ultitech-accounting` |

## Runtime tooling

| Tool | Version / Path | PATH? | Verdict |
|---|---|---|---|
| PHP | 8.2.12 — `C:\xampp\php\php.exe` | No | OK for Laravel 11 |
| MariaDB | 10.4.32 — `C:\xampp\mysql\bin\mysql.exe` | No | OK as MySQL-compatible engine |
| Apache | `httpd` running | — | OK for local serving |
| Composer | 2.10.3 — `C:\xampp\htdocs\composer.phar` | No | OK; invoke via PHP |
| Node.js | v24.18.0 | Yes | OK for Vite/React 19 |
| npm | 11.16.0 | Yes | OK |
| Redis | Not installed | — | Use database queue in M01; add Redis later |
| redis-cli / php-redis | Missing | — | Deferred |

## PHP extensions

Present and relevant: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.

Missing (noted, not blockers for M01):

- `intl` — useful for localization; can enable later in `php.ini`
- `redis` — only needed when Redis queues are introduced

## Databases observed (do not use for this product)

Existing DBs include ERP/trading copies such as `ultimate_live_copy`, `ultimate_trading-*`, `ultitech_trial`, etc.

**New dedicated database (to create after design approval):**

```sql
CREATE DATABASE ultitech_accounting_dev
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

## Isolation commitments

1. No writes under ERP application folders outside `ultitech-accounting/`.
2. No connection strings pointing at ERP production or live-copy DBs for application runtime.
3. Fresh credentials for local M01 (root/local XAMPP only in `.env.example` patterns; secrets never committed).
4. Shared-DB multi-tenant model inside `ultitech_accounting_dev` only, with `company_id` scoping.

## Risks / setup actions before coding

1. Add Composer/PHP to shell aliases or scripts for convenience.
2. Create `ultitech_accounting_dev`.
3. Decide local API URL (suggested: `http://localhost/ultitech-accounting/backend/public` or `php artisan serve` on a free port).
4. Optionally enable `intl` in XAMPP `php.ini`.
5. Redis is optional until background webhook/outbox workers are required (M08+); M01 outbox can flush synchronously or via database queue.

## Conclusion

Local environment is sufficient to begin Milestone 01 implementation after design approval. No ERP modifications were made.
