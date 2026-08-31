# Roadmaster DB import (`roadmaster_db-3530393454a2`)

## What was applied locally (XAMPP)

1. **`database/_roadmaster_reset_db.sql`** ù drops and recreates the database (quoted name required because of the hyphen).
2. **`database/roadmaster_db-3530393454a2 (1).sql`** ù phpMyAdmin dump imported into that database via MariaDB CLI.

### Fixes applied to the dump file (required for import)

- **`SET SQL_MODE`**: cleared strict zero handling so rows with `id = 0` can receive auto-increment values where appropriate (matches typical phpMyAdmin behaviour without `NO_AUTO_VALUE_ON_ZERO`).
- **`account_transactions`**: removed a **duplicate block** of rows `(1ù10)` that appeared twice in one `INSERT`, which would cause primary key errors.

### Commands used (repeat anytime)

Reset DB only:

```bat
"C:\xampp\mysql\bin\mysql.exe" -u root < "C:\xampp\htdocs\public_html\database\_roadmaster_reset_db.sql"
```

Import dump (PowerShell):

```powershell
Get-Content -LiteralPath 'C:\xampp\htdocs\public_html\database\roadmaster_db-3530393454a2 (1).sql' -Raw -Encoding UTF8 |
  & 'C:\xampp\mysql\bin\mysql.exe' -u root --default-character-set=utf8mb4 '--database=roadmaster_db-3530393454a2'
```

Adjust `-u root` / password if your XAMPP MySQL uses a password:

```powershell
& '...\mysql.exe' -u root -pYourPass ...
```

## Multi-tenant app note

Your app switches tenant DB using **`companies.db_name`** (see `includes/config.php`). The Roadmaster row must point at the database where you imported this dump.

**Common pitfall:** locally, `companies` had `db_name = roadmaster_db` (empty) while the phpMyAdmin dump creates **`roadmaster_db-3530393454a2`** (with data). Visiting `/roadmaster/payment-vouchers` then showed empty lists until `db_name` was updated:

```sql
USE ultimate_trading_voucher;
UPDATE companies SET db_name = 'roadmaster_db-3530393454a2' WHERE company_slug = 'roadmaster';
```

Adjust `ultimate_trading_voucher` if your control database name differs (see root `env.local.php` ? `DB_NAME`).

## cPanel / new server

1. Create database `roadmaster_db-3530393454a2` (or the name your host allows).
2. Import the **same** SQL file in phpMyAdmin (or MySQL CLI).
3. Use the same small fixes above if import errors on duplicates or `id = 0` / `NO_AUTO_VALUE_ON_ZERO`.
