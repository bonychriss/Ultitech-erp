# Ultitech ERP (Payment Voucher System)

Multi-company PHP ERP for **Ultimate General Trading** and **Roadmaster**, running on XAMPP locally and on StackCP in production. This document describes how databases, environments, and login are wired together.

**Full system status (accomplished vs remaining, all modules):** see **[SYSTEM-STATUS.md](SYSTEM-STATUS.md)**.

---

## Overview

| Item | Value |
|------|--------|
| Local URL | `http://localhost/public_html/` |
| App base path (local) | `/public_html` |
| Stack | PHP 8.x, MySQL/MariaDB, Apache (XAMPP) |
| Timezone | `Africa/Dar_es_Salaam` (+03:00) |

The app uses a **control database** for shared metadata (users, companies, meetings) and **tenant databases** per company for operational data (vouchers, sales, stock).

---

## Databases

Three MySQL databases are used on this installation:

| Database name | Role | Typical contents |
|---------------|------|------------------|
| `ultimate_trading-35313030f83f` | **Control DB** (`DB_NAME`) | `companies`, `users` (global), meetings, notifications |
| `new_trading_voucher-35313030c7e2` | **Ultimate tenant** (`DATA_DB_NAME` / `SALES_DB_NAME`) | Payment vouchers, sales orders, products, most Ultimate users |
| `roadmaster_db-35313030b5e8` | **Roadmaster tenant** | Stock, procurement, Roadmaster users |

### Company mapping (`companies` table)

| Company | Slug | Tenant database | `db_host` (local) |
|---------|------|-----------------|-------------------|
| Ultimate General Trading | `ultimate` | `new_trading_voucher-35313030c7e2` | `localhost` |
| Roadmaster | `roadmaster` | `roadmaster_db-35313030b5e8` | `localhost` |

---

## Local setup (XAMPP)

### 1. Prerequisites

- XAMPP with **Apache** and **MySQL** running
- Project path: `C:\xampp\htdocs\public_html`
- All three databases imported into MySQL (names must match exactly, including the `-35313030…` suffix)

Verify databases:

```sql
SHOW DATABASES LIKE '%35313030%';
```

### 2. Environment file

Local settings live in **`env.local.php`** at the project root (loaded automatically on `localhost` before `includes/env.php`):

```php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';

$DB_NAME = 'ultimate_trading-35313030f83f';
$DATA_DB_NAME = 'new_trading_voucher-35313030c7e2';
$SALES_DB_NAME = 'new_trading_voucher-35313030c7e2';

$ROADMASTER_DB_NAME = 'roadmaster_db-35313030b5e8';
$ROADMASTER_DB_HOST = 'localhost';

$APP_ENV = 'development';
$APP_BASE_PATH = '/public_html';
```

Copy from `includes/env.local.example.php` if you need a fresh template.

**Production** uses `includes/env.php` (StackCP host `sdb-86.hosting.stackcp.net`). Do not commit real passwords; keep `env.local.php` out of version control if it contains secrets.

### 3. Local tenant credentials

Production stores per-company MySQL users in `companies.db_user` / `companies.db_pass` (e.g. Roadmaster uses `roadmaster86` on StackCP).

On **local development**, the app ignores those fields and uses global `root` credentials when:

- `APP_ENV === 'development'`, or  
- `DB_HOST` is `localhost` / `127.0.0.1`

Implemented in `useGlobalDbCredentialsForTenants()` in `includes/functions.php`.

---

## Configuration load order

`includes/config.php` loads environment files in this order on **localhost**:

1. `env.local.php` (project root)
2. `includes/env.local.php`
3. `includes/env.php`
4. `env.php` (project root)

On **production hosts**, `env.php` / `includes/env.php` take priority over local overrides.

Entry point for app config:

```php
require_once __DIR__ . '/includes/config.php';
```

(`config.php` in the root forwards to that file.)

---

## Login (central index)

### `user_company_index` (control database)

Fast login routing table — **one row per email** (globally unique).

| Column | Purpose |
|--------|---------|
| `email` | Primary login identifier (unique) |
| `company_id` / `company_slug` | Workspace routing |
| `tenant_db_name` / `tenant_db_host` | Tenant connection |
| `tenant_user_id` | User id in tenant `users` table |
| `status` | `active`, `inactive`, `pending`, `blocked` |
| `source` | `tenant` or `control` (super admin) |

Migration: `migrations/20260522_user_company_index.sql`  
Auto-created by `ensureUserCompanyIndexSchema()` on bootstrap.

### Generic login (email + password)

**URL:** `http://localhost/public_html/login.php?next=my-account.php`

1. User enters **email** and **password**.
2. System looks up **`user_company_index`** in the control DB (no tenant DB scan).
3. If found and `status = active`, authenticates **only** that company’s tenant database.
4. Sets session (`user_id`, `email`, `company_id`, `company_slug`, …) and redirects to `/{company_slug}/select-module` or `?next=`.

**Super admin** accounts in the control DB still work (not in index, or `source = control`).

**Bootstrap:** If the index table is **empty**, login falls back once to legacy tenant scanning (`resolveLoginSlugFromIdentifierLegacy`). After sync, scanning is disabled.

### Automatic sync

The login index **syncs automatically** when empty:

- On every app request (`includes/config.php` bootstrap)
- Before login (`performIndexedLogin`)
- When opening the sync page (if still empty)

Manual rebuild (super admin): `admin/sync-user-company-index.php` — use **Force full sync** only after bulk imports.

Optional force URL: `admin/sync-user-company-index.php?force=1`

### Key functions (`includes/functions.php`)

| Function | Purpose |
|----------|---------|
| `findLoginCompanyFromIndex()` | Lookup by email (then username fallback) |
| `performIndexedLogin()` | Full login POST handler |
| `syncUserCompanyIndex()` | Upsert index after user create/update |
| `removeUserCompanyIndex()` | Mark inactive on delete/disable |
| `validateNewUserEmailForIndex()` | Block duplicate emails globally |
| `syncAllTenantUsersToIndex()` | Full rebuild (admin tool) |
| `resolveLoginSlugFromTenantDatabases()` | Legacy repair scan only |

### New user validation

Before creating a user, the app checks `user_company_index`. Duplicate email returns:

> This email is already registered. Please use another email.

Hooks are in `company/register-employee.php`, `company/manage-employees.php`, `admin/manage-users.php`, `admin/register_employee.php`, `admin/company-users.php`.

### Company-specific login URLs

Apache rewrite (`.htaccess`):

```
/{company_slug}/login  →  login.php?company_slug={slug}
```

Examples:

- `http://localhost/public_html/ultimate/login`
- `http://localhost/public_html/roadmaster/login`

Optional `?next=` is preserved via a hidden form field.

### Where users live

| Location | Users (approx.) |
|----------|-----------------|
| Control DB | Global/admin accounts |
| `new_trading_voucher-…` | Ultimate staff (tenant `users` table, no `company_id` column) |
| `roadmaster_db-…` | Roadmaster staff |

---

## Useful URLs

| Purpose | URL |
|---------|-----|
| Home | `/public_html/` |
| Login | `/public_html/login.php` |
| My Account (after login) | `/public_html/my-account.php` |
| Module picker (Ultimate) | `/public_html/ultimate/select-module` |
| Module picker (Roadmaster) | `/public_html/roadmaster/select-module` |
| DB diagnostic (remove after use) | `/public_html/debug_db_connections.php` |

---

## Verifying connections

### Option A: Debug page

Open `debug_db_connections.php` in the browser. Expect:

- Control DB: **OK** → `ultimate_trading-35313030f83f`
- Ultimate tenant: **OK** → `new_trading_voucher-35313030c7e2`
- Roadmaster tenant: **OK** → `roadmaster_db-35313030b5e8` (db_user should show as `root` locally)

**Delete `debug_db_connections.php` when finished** — it exposes environment details.

### Option B: MySQL CLI

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "SELECT company_slug, db_name, db_host FROM \`ultimate_trading-35313030f83f\`.companies;"
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|----------------|-----|
| “Service unavailable” / DB connection error | Wrong `DB_NAME` or MySQL not running | Check XAMPP MySQL; confirm names in `env.local.php` |
| Roadmaster tenant **FAIL** `Access denied for user 'roadmaster86'` | Production creds in `companies` table | Ensure `APP_ENV=development` and `DB_HOST=localhost` in `env.local.php` |
| Login: “Invalid email/username or password” | User only exists in tenant DB | Use correct email; generic login should resolve company automatically |
| Wrong company after login | Email exists in multiple DBs | Prefer company URL login (`/roadmaster/login`) or use distinct emails |
| Broken links / assets | Wrong base path | Set `$APP_BASE_PATH` in `env.local.php` to match your folder under `htdocs` |

---

## Key files

| File | Purpose |
|------|---------|
| `env.local.php` | Local database credentials (XAMPP) |
| `includes/env.php` | Production StackCP credentials |
| `includes/config.php` | PDO bootstrap, tenant switching, session |
| `includes/functions.php` | Auth, company helpers, tenant connections |
| `login.php` | Login UI and POST handler |
| `.htaccess` | Company slug routes (`/ultimate/login`, etc.) |

---

## Production deployment notes

- Host: `sdb-86.hosting.stackcp.net` (see `includes/env.php`).
- Tenant hosts in `companies.db_host` point to StackCP servers.
- Per-company `db_user` / `db_pass` are used when **not** in development mode.
- Site URL in production: `https://ultitech.io` (see `includes/env.php`).

After importing a fresh DB dump locally, you may need to run:

```sql
UPDATE companies SET db_host = 'localhost'
WHERE db_host LIKE '%stackcp%' OR db_host LIKE '%hosting%';
```

---

## Security reminders

- Do not commit `env.local.php` or `includes/env.php` with real passwords to public repos.
- Remove `debug_db_connections.php` after troubleshooting.
- Use HTTPS in production (`forceHttps()` in `includes/functions.php`).

---

## Changelog (setup documented here)

- Connected three StackCP-imported databases to XAMPP.
- Added `env.local.php` mapping for control + tenant DBs.
- Local override: ignore production `companies.db_user` on localhost.
- Updated `companies.db_host` to `localhost` for local tenants.
- Central login index `user_company_index` (email-unique, no per-login tenant scan).
- `performIndexedLogin()` — email-first login via index.
- Admin sync tool: `admin/sync-user-company-index.php`.
- Post-login redirect supports `?next=` (e.g. `my-account.php`).
