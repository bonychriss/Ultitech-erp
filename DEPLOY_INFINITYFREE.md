# InfinityFree Deployment Guide (Plain PHP Payment Voucher System)

This document explains how to deploy the Payment Voucher System on InfinityFree (or similar free cPanel-style shared hosting that does not allow Composer or custom daemons).

## 1. Hosting Constraints
InfinityFree limitations that affect this project:
- No SSH and no shell access: you cannot run artisan/composer (irrelevant now—project is pure PHP).
- Limited PHP extensions: GD is usually available; Imagick generally not. We only need PDO_MySQL and GD (optional for JPEG → PNG conversion).
- File upload size limits are lower (typically 10MB). Our voucher attachment logic enforces 10MB per file.
- Cron jobs limited (not required by this system).
- No custom .env secrets loader; configuration is hard-coded in `includes/config.php`.

## 2. Prepare Local Copy
1. Ensure you are on the plain PHP edition (no `laravel-*` folders; we already removed them).
2. Confirm `APP_BASE_PATH` in `includes/config.php` is set to '' (empty string) for root deployment.
3. Optional: create a ZIP of the project root (excluding local OS/system files and any old backups).

## 3. Create Database
1. Log in to InfinityFree control panel → MySQL Databases.
2. Create a new database (note the database name, username, and password they provide; host is often like `sqlXXX.epizy.com`).
3. Update `includes/config.php`:
   ```php
   define('DB_HOST', 'sqlXXX.epizy.com');
   define('DB_NAME', 'your_db_name');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_pass');
   define('APP_BASE_PATH', '');
   ```
4. Save the file locally.

## 4. Upload Files
1. In the InfinityFree file manager (or via FTP), navigate to `htdocs/` (root web directory).
2. Upload all project files directly into `htdocs/` so `index.php` is at `htdocs/index.php`.
3. Ensure these writable directories exist:
   - `assets/uploads/vouchers/`
   - `assets/uploads/messages/`
   - `assets/signatures/`
   If missing, create them manually.

## 5. Run Setup
1. Visit: `https://yourdomain.com/setup.php`
2. This will create tables and seed default users.
3. If you get a permission or connection error:
   - Re-check DB credentials in `includes/config.php`.
   - Ensure the database name matches exactly (InfinityFree prefixes names).

## 6. Post-Setup Security Adjustments
- Delete (or rename) `setup.php` after successful installation to prevent re-seeding.
- Optionally restrict direct access to the uploads directories by adding a simple `.htaccess`:
  - Inside `assets/uploads/vouchers/` create `.htaccess`:
    ```
    Options -Indexes
    <FilesMatch "\.(php|phtml)$">
      Deny from all
    </FilesMatch>
    ```
- Same for `assets/uploads/messages/` and `assets/signatures/` if you only serve static images.

## 7. Adjusting Attendance Geofence
Edit `includes/config.php`:
```php
define('OFFICE_LAT', <your_latitude>);
define('OFFICE_LON', <your_longitude>);
define('OFFICE_RADIUS_M', 150); // adjust meters
```
Use Google Maps to get precise coordinates.

## 8. File Upload Troubleshooting
If attachments fail:
- Check `phpinfo()` (create a temporary `phpinfo.php` with `<?php phpinfo(); ?>`) for `upload_max_filesize` and `post_max_size`.
- Reduce uploads if limits < 10MB.
- Verify directories have write permission (InfinityFree usually allows writes under `htdocs`).

## 9. Changing Base Path Later
If you move the app to a subfolder (e.g., `https://yourdomain.com/pvs`):
1. Set `APP_BASE_PATH` to `/pvs` in `includes/config.php`.
2. Update any manually hardcoded links (we’ve replaced critical ones with `APP_BASE_PATH`).
3. Move all files into the `pvs/` directory in `htdocs`.

## 10. Backups
- Use the control panel’s database export weekly.
- Download a ZIP of your `assets/uploads/` directories regularly.

## 11. Minimal Hardening
- Remove `setup.php` after install.
- Create an `.htaccess` at root if not present:
  ```
  Options -Indexes
  <FilesMatch "\.(sql|sh)$">
    Deny from all
  </FilesMatch>
  ```
- Avoid storing credentials anywhere except `includes/config.php`.

## 12. Default Accounts Reminder
After deployment, change passwords for seeded accounts immediately via the admin user management page.

## 13. Optional: Disable Registration
If you want to block new self-registration, remove or rename `register.php` and adjust redirects in `requireLogin()` (in `includes/functions.php`) to point to `login.php` instead of `register.php`.

## 14. Testing After Upload
Checklist:
- Login works.
- Create voucher, upload an attachment (image/PDF), confirm stored in `assets/uploads/vouchers/<id>/`.
- Attendance sign-in/out stores rows in `attendance` table.
- Notifications appear for admin after voucher creation.
- Finance user can mark approved voucher as paid.

## 15. Common InfinityFree Errors
| Error | Cause | Fix |
|-------|-------|-----|
| 500 Internal Server Error | Missing writable directory or bad permissions | Ensure upload directories exist and are writable |
| Access Denied (DB) | Bad credentials or host | Use full host name (e.g., sql123.epizy.com) |
| White page | PHP error display off | Temporarily enable `display_errors` via a custom `php.ini` or inspect logs |
| Upload fails silently | File size exceeds limit | Reduce file size or compress document |

---
Deployment complete – your app should now be reachable at `https://yourdomain.com/`.

If you need a one-click cleanup script or automated backup instructions, let me know.
