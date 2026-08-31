# Environments: Local Development & InfinityFree Production

This project now supports running in two environments without manually editing core files each time.

## 1. How Environment Detection Works
`includes/config.php` auto-detects based on the request host:
- InfinityFree: host contains `epizy.com`, `infinityfreeapp.com`, or `rf.gd`.
- Local development: host contains `localhost`, `127.0.0.1`, or `::1`.

Defaults:
- Local: DB `ultimate_trading_voucher`, user `root`, BLANK password, `APP_BASE_PATH='/payment-voucher-system'`.
- InfinityFree (placeholder): `APP_BASE_PATH=''` and placeholder DB credentials you must override.

## 2. Overriding with env.php
Create `includes/env.php` (never commit it) to override any of:
```php
$DB_HOST = '...';
$DB_NAME = '...';
$DB_USER = '...';
$DB_PASS = '...';
$APP_BASE_PATH = ''; // or '/payment-voucher-system'
```
Copy from `includes/env.example.php`.

## 3. APP_BASE_PATH
Used for building portable URLs. Local subfolder installs typically set `/payment-voucher-system`. Root production sets empty string `''`.
Helper: `app_url('/employee/dashboard.php')` returns the correct path for both environments.

## 4. Adding New Redirects or Links
Use `app_url()` instead of hardcoding `/payment-voucher-system/`:
```php
header('Location: ' . app_url('/employee/view-voucher.php?id=' . $id));
```

## 5. Typical Local Setup
1. Clone project into `C:\xampp\htdocs\payment-voucher-system`.
2. Visit `http://localhost/payment-voucher-system/setup.php` to create tables.
3. Login via `http://localhost/payment-voucher-system/login.php`.

## 6. Typical InfinityFree Setup
1. Upload contents into `htdocs/` (root).
2. Create DB in control panel, note host `sqlXXX.epizy.com`.
3. Create `includes/env.php` with production credentials and `$APP_BASE_PATH=''`.
4. Visit `https://yourdomain.com/setup.php` once; then delete/rename `setup.php`.

## 7. Safe Deployment Checklist
| Item | Local | Production |
|------|-------|------------|
| `APP_BASE_PATH` | `/payment-voucher-system` | `''` |
| DB credentials in `env.php` | Optional | Required |
| Remove `setup.php` post-install | Optional | Required |
| Writable directories | yes | yes |
| Change seeded passwords | Recommended | Mandatory |

## 8. Switching Environments Quickly
- Keep `includes/env.php` only on production (do not copy to local unless you want different local DB name).
- On local, rely on defaults in `config.php`.

## 9. Troubleshooting
| Symptom | Cause | Fix |
|---------|-------|-----|
| Paths missing segment | Wrong `APP_BASE_PATH` | Adjust in `env.php` or local default |
| 500 after upload | Missing writable `assets/uploads/...` dirs | Create and set correct permissions |
| Redirect loops | Attendance lock + missing sign page path | Ensure `app_url()` used and `employee/sign.php` exists |
| DB connection error prod | Host/user mismatch | Verify InfinityFree DB host (NOT `localhost`) |

## 10. Next Improvements (Optional)
- Add `APP_ENV` constant (e.g., `production` or `local`) and guard debug output.
- Implement minimal error logging to file in production.
- Add automatic HTTPS enforcement if `APP_ENV=production`.

## 11. Security Notes
- Never commit real credentials: only `env.example.php` is tracked.
- Delete `setup.php` and any temporary diagnostic scripts (`phpinfo.php`) in production.
- Consider adding `.htaccess` in uploads to block PHP execution:
```
Options -Indexes
<FilesMatch "\.(php|phtml|php5)$">
  Deny from all
</FilesMatch>
```

## 12. Example Usage of app_url()
```php
$link = app_url('/employee/my-vouchers.php');
echo '<a href="' . htmlspecialchars($link) . '">My Vouchers</a>';
```

You are now set to develop locally and deploy live on InfinityFree with minimal friction.
