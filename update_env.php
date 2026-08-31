<?php
// Script to fix env.php on the live server automatically
// This will overwrite includes/env.php with the correct cPanel credentials provided by the user.

$targetFile = __DIR__ . '/includes/env.php';

$content = <<<'PHP'
<?php
/**
 * Live Server Environment Configuration
 * 
 * Database credentials for ultimate.co.tz
 * Created: 2025-11-28
 * Updated: 2025-12-21 (Auto-Fix)
 */

// Database Configuration
$DB_HOST = 'localhost';
$DB_NAME = 'ultimate_trading_voucher';
$DB_USER = 'ultimate_voucher';
$DB_PASS = 'Baddyman123';

// Environment Configuration
$APP_ENV = 'production';

// Site URL
$SITE_URL = 'https://ultimate.co.tz';

// Application Base Path (auto-detected by config.php)
// $APP_BASE_PATH = '/';  // Uncomment if needed
?>
PHP;

// 1. Backup old file if exists
if (file_exists($targetFile)) {
    copy($targetFile, $targetFile . '.bak');
    echo "Backed up old env.php to env.php.bak<br>";
}

// 2. Write new file
if (file_put_contents($targetFile, $content)) {
    echo "<h1 style='color:green'>SUCCESS: env.php has been updated!</h1>";
    echo "New Config:<br>";
    echo "Host: localhost<br>";
    echo "User: ultimate_voucher<br>";
    echo "DB: ultimate_trading_voucher<br>";
    echo "<br>";
    echo "Please delete this file (update_env.php) after verifying the site works.";
    echo "<br><br>";
    echo "<a href='check_db_live.php'> > Click here to Verify Connection Now < </a>";
} else {
    echo "<h1 style='color:red'>FAILED: Could not write to $targetFile</h1>";
    echo "Check folder permissions.";
}
?>
