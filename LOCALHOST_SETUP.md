# Localhost Setup Guide

## ✅ Configuration Complete!

Your website has been successfully configured to work on localhost. Here's what was done:

### 1. **Environment Configuration**
- **Backed up production credentials**: `env.php` → `env.production.backup.php`
- **Created local environment file**: `env.php` with localhost settings:
  - Database Host: `localhost`
  - Database Name: `ultimate_trading_voucher`
  - Database User: `root`
  - Database Password: `` (empty - XAMPP default)
  - Base Path: `/staff`

### 2. **Fixed File Paths**
- Corrected `index.php` to use proper relative paths for the root directory

### 3. **Database Verified**
- Confirmed database `ultimate_trading_voucher` exists on localhost
- Found 26 tables already present in the database

---

## 🚀 How to Use

### Access Your Website
- **Homepage**: http://localhost/staff/
- **Login Page**: http://localhost/staff/login.php
- **Test Connection**: http://localhost/staff/test_connection.php

### Workflow for Development

1. **Make Changes Locally**
   - Edit files in `c:\xampp\htdocs\staff\`
   - Test changes at `http://localhost/staff/`

2. **Upload to Live Site**
   - Use FTP/cPanel File Manager to upload changed files
   - **IMPORTANT**: Do NOT upload `env.php` (your local config)
   - The live server should use `env.production.backup.php` renamed to `env.php`

---

## 🔄 Switching Between Environments

### For Localhost (Current Setup)
```php
// env.php (already configured)
$DB_HOST = 'localhost';
$DB_NAME = 'ultimate_trading_voucher';
$DB_USER = 'root';
$DB_PASS = '';
$APP_BASE_PATH = '/staff';
```

### For Live Server
```php
// env.php (on live server - use env.production.backup.php as reference)
$DB_HOST = 'localhost';
$DB_NAME = 'ultimate_trading_voucher';
$DB_USER = 'ultimate_voucher';
$DB_PASS = 'ULTIMATE@2025';
$APP_BASE_PATH = '';  // Empty for root installation
```

---

## 📁 Important Files

### Configuration Files
- `env.php` - **Current environment config** (localhost)
- `env.production.backup.php` - **Production credentials** (backup)
- `config.php` - Main configuration (auto-loads env.php)
- `includes/config.php` - Duplicate of config.php

### Do NOT Upload to Live Server
- `env.php` (your local version)
- `test_connection.php`
- Any `test_*.php` files
- `.git/` folder (if present)
- `LOCALHOST_SETUP.md` (this file)

### MUST Upload to Live Server
- All `.php` application files
- `assets/` folder
- `includes/` folder
- `admin/` folder
- `employee/` folder
- `.htaccess` file

---

## 🔐 Security Notes

1. **Never commit real credentials to Git**
   - `env.php` should be in `.gitignore`
   - Only `env.example.php` should be tracked

2. **Production Security**
   - Delete `test_connection.php` from live server
   - Delete `setup.php` after initial setup
   - Ensure `.htaccess` is protecting sensitive files

3. **Database Sync**
   - The database name is the same on both environments: `ultimate_trading_voucher`
   - Only credentials differ (root vs ultimate_voucher)

---

## 🛠️ Troubleshooting

### Issue: "Database connection failed"
**Solution**: Check that:
- XAMPP MySQL is running
- Database name is correct: `ultimate_trading_voucher`
- `env.php` has correct credentials

### Issue: "404 Not Found" errors
**Solution**: Check that:
- `APP_BASE_PATH` is set to `/staff` in `env.php`
- Files are in `c:\xampp\htdocs\staff\`

### Issue: CSS/Images not loading
**Solution**: Check that:
- `assets/` folder exists
- Paths in HTML use relative paths (no hardcoded `/staff/`)

---

## 📋 Deployment Checklist

Before uploading to live server:

- [ ] Test all changes on localhost
- [ ] Verify database queries work
- [ ] Check that forms submit correctly
- [ ] Test user login/logout
- [ ] Backup live server files first
- [ ] Upload changed files only
- [ ] **DO NOT** upload `env.php` (local version)
- [ ] Verify `env.php` on live server has production credentials
- [ ] Test live site after upload

---

## 🎯 Quick Reference

| Environment | Database User | Database Pass | Base Path | env.php Location |
|-------------|---------------|---------------|-----------|------------------|
| **Localhost** | root | (empty) | /staff | Use current env.php |
| **Live Server** | ultimate_voucher | ULTIMATE@2025 | (empty) | Use env.production.backup.php |

---

## 📞 Need Help?

If you encounter issues:
1. Check `test_connection.php` to verify database connection
2. Check XAMPP error logs: `c:\xampp\apache\logs\error.log`
3. Enable debug mode by adding `?debug=1` to URL: `http://localhost/staff/login.php?debug=1`

---

**Last Updated**: 2025-11-25
**Status**: ✅ Ready for Development
