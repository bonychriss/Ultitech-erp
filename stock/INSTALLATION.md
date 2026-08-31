# 📥 Installation & Setup Guide

## Requirements
*   **Server:** Apache (XAMPP/WAMP/LAMP)
*   **PHP:** Version 7.4 or 8.x
*   **Database:** MySQL 5.7 or 8.0
*   **Browser:** Chrome, Firefox, or Edge

## Installation Steps

### 1. Database Setup
1.  Open phpMyAdmin.
2.  Create a database named `stock_management_system`.
3.  Import `database.sql` to create core tables.
4.  Import `landed_cost_schema.sql` for cost modules.
5.  Import `receipt_schema_update.sql` for advanced receipt features.

### 2. Configuration
1.  Open `config/database.php`.
2.  Update credentials:
    ```php
    $host = 'localhost';
    $db   = 'stock_management_system';
    $user = 'root';
    $pass = '';
    ```

### 3. First Login
*   **URL:** `http://localhost/stock/`
*   **Default Admin:**
    *   Email: `admin@example.com`
    *   Password: `password` (Change immediately after login!)

## Backup & Maintenance
*   **Daily:** The system does *not* auto-backup. Use `mysqldump` or phpMyAdmin to export the database daily.
*   **Logs:** Check `php_error.log` in your server folder for backend errors.
