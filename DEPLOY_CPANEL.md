# Deployment Guide: cPanel Shared Hosting

This guide explains how to deploy the Payment Voucher System to a cPanel environment in a subdirectory (e.g., `https://ultimate.co.tz/staff`).

## 1. Prepare Files
1.  **Rename** `env.example.php` to `env.php`.
2.  **Edit** `env.php` and add your cPanel database credentials:
    ```php
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'your_cpanel_db_name');
    define('DB_USER', 'your_cpanel_db_user');
    define('DB_PASS', 'your_cpanel_db_password');
    ```
3.  **Zip** the entire project folder (excluding `.git`, `storage/logs`, and `node_modules` if any).

## 2. Upload to cPanel
1.  Log in to cPanel and open **File Manager**.
2.  Navigate to **`public_html`**.
3.  Create a new folder named **`staff`**.
4.  Open the **`staff`** folder.
5.  **Upload** your zip file here.
6.  **Extract** the zip file.
7.  Ensure `index.php` is directly inside `public_html/staff`.

## 3. Database Setup
1.  Go to **MySQL Database Wizard** in cPanel.
2.  Create a new database (e.g., `ultimate_voucher`).
3.  Create a new user and assign it to the database with **ALL PRIVILEGES**.
4.  Open **phpMyAdmin**.
5.  Select your new database.
6.  Click **Import** and upload `database_setup.sql`.
7.  (Optional) Import `database_tasks_setup.sql` if it wasn't included in the main setup.

## 4. Verify Configuration
-   The system is designed to auto-detect the base path.
-   If you see 404 errors on links, open `env.php` and uncomment the `APP_BASE_PATH` line:
    ```php
    define('APP_BASE_PATH', ''); // If at root (staff.ultimate.co.tz)
    // OR
    define('APP_BASE_PATH', '/subfolder'); // If in a subfolder
    ```

## 5. Troubleshooting
-   **500 Internal Server Error**: Check `error_log` file in File Manager.
-   **Database Error**: Verify credentials in `env.php`.
-   **403 Forbidden**: Ensure permissions are 755 for folders and 644 for files.
