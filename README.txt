# ERP SYSTEM DEPLOYMENT PACKAGE

## 📦 Package Contents

This package contains everything you need to deploy the complete ERP system to your live server.

### Included Files:
- **erp/** - Complete ERP system (all modules)
- **includes/** - Core functions and database connection
- **assets/** - CSS, JavaScript, images
- **uploads/** - Empty folder for user uploads
- **env.php** - Database configuration (MUST EDIT)
- **config.php** - System configuration
- **.htaccess** - Apache configuration
- **index.php, login.php, logout.php** - Entry points

### Database:
- **erp_complete_schema.sql** - Run this on your live database

---

## 🚀 DEPLOYMENT STEPS

### 1. Upload Files
Upload all files from this package to your live server (via FTP or cPanel File Manager)

### 2. Configure Database
Edit `env.php` with your live database credentials:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_live_database');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');
define('BASE_URL', 'https://yourdomain.com/staff/');
```

### 3. Import Database
- Login to cPanel > phpMyAdmin
- Select your database
- Click Import
- Choose `erp_complete_schema.sql`
- Click Go

### 4. Set Permissions
```bash
chmod 755 erp/
chmod 777 uploads/
```

### 5. Test
Visit: `https://yourdomain.com/staff/erp/`

---

## ✅ Your ERP is Ready!

All 9 phases are included:
✓ Customers & Products
✓ Inventory Management
✓ Purchasing
✓ HR & Payroll
✓ Sales (Quotes, Invoices, Deliveries)
✓ Accounting
✓ CRM (Leads, Opportunities)
✓ Bank Reconciliation
✓ Settings & Configuration
