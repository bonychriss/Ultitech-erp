# XAMPP Integration Guide for Ultimate General Trading Payment Voucher System

## 🚀 Your Project is Now Connected to XAMPP!

### Current Status:
✅ Project copied to XAMPP htdocs directory
✅ Apache and MySQL services started
✅ File permissions set correctly
✅ Web interface accessible at: http://localhost/payment-voucher-system/

### 🔧 XAMPP Configuration:

**Database Settings (Already Configured):**
- Host: localhost
- Port: 3306 (default)
- Username: root
- Password: (empty - XAMPP default)
- Database: ultimate_trading_vouchers

**Web Server:**
- Apache running on port 80
- PHP version: 8.2.12
- Document root: /opt/lampp/htdocs/

### 📋 Quick Setup Steps:

1. **Access the System:**
   Open your browser and go to: http://localhost/payment-voucher-system/test.php

2. **Initialize Database:**
   Go to: http://localhost/payment-voucher-system/setup.php

3. **Start Using:**
   Go to: http://localhost/payment-voucher-system/login.php

### 🔐 Default Login Credentials:

**Admin Accounts:**
- Username: `admin` | Password: `admin123`
- Username: `rajab` | Password: `password123`

**Employee Accounts:**
- Username: `maureen` | Password: `password123`
- Username: `saida` | Password: `password123`
- Username: `mase` | Password: `password123`

### 🛠️ XAMPP Control Commands:

**Start XAMPP:**
```bash
sudo /opt/lampp/lampp start
```

**Stop XAMPP:**
```bash
sudo /opt/lampp/lampp stop
```

**Restart XAMPP:**
```bash
sudo /opt/lampp/lampp restart
```

**Check Status:**
```bash
sudo /opt/lampp/lampp status
```

### 📁 File Locations:

- **Project Location:** `/opt/lampp/htdocs/payment-voucher-system/`
- **XAMPP Control Panel:** `http://localhost/dashboard/`
- **phpMyAdmin:** `http://localhost/phpmyadmin/`
- **Apache Logs:** `/opt/lampp/logs/error_log`
- **MySQL Logs:** `/opt/lampp/logs/mysql_error.log`

### 🔍 Troubleshooting:

**If Apache won't start:**
- Check if port 80 is in use: `sudo netstat -tulpn | grep :80`
- Stop conflicting services: `sudo systemctl stop apache2`

**If MySQL won't start:**
- Check if port 3306 is in use: `sudo netstat -tulpn | grep :3306`
- Stop conflicting MySQL: `sudo systemctl stop mysql`

**Permission Issues:**
```bash
sudo chown -R www-data:www-data /opt/lampp/htdocs/payment-voucher-system/
sudo chmod -R 755 /opt/lampp/htdocs/payment-voucher-system/
```

### 🌐 Access URLs:

- **Test Page:** http://localhost/payment-voucher-system/test.php
- **Setup Database:** http://localhost/payment-voucher-system/setup.php
- **Login:** http://localhost/payment-voucher-system/login.php
- **XAMPP Dashboard:** http://localhost/dashboard/
- **phpMyAdmin:** http://localhost/phpmyadmin/

### 📊 Database Management:

You can manage your database using phpMyAdmin at:
http://localhost/phpmyadmin/

- Server: localhost
- Username: root
- Password: (leave empty)

### 🔄 Making Changes:

If you need to update files, edit them in:
`/opt/lampp/htdocs/payment-voucher-system/`

Or you can edit in your original location and copy:
```bash
sudo cp -r "/home/wigans/Desktop/ultimate project 2/payment-voucher-system"/* /opt/lampp/htdocs/payment-voucher-system/
sudo chown -R www-data:www-data /opt/lampp/htdocs/payment-voucher-system/
```

### ✅ Everything Ready!

Your Ultimate General Trading Payment Voucher System is now fully integrated with XAMPP and ready to use!

**Next Steps:**
1. Visit http://localhost/payment-voucher-system/test.php
2. Run the database setup if needed
3. Login and start creating vouchers
4. Test the approval workflow
5. Try printing a voucher to verify the layout

Enjoy your professional payment voucher system! 🎉