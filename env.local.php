<?php
/**
 * Local XAMPP overrides (loaded before env.php on localhost).
 * Maps the three imported StackCP databases to this installation.
 */

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';

// Control / metadata (users, companies, sessions)
$DB_NAME = 'ultimate_trading-35313030f83f';

// Ultimate tenant ERP data (vouchers, sales, stock for "ultimate" company)
$DATA_DB_NAME = 'new_trading_voucher-35313030c7e2';
$SALES_DB_NAME = 'new_trading_voucher-35313030c7e2';

// Roadmaster stock/ERP tenant (companies.db_name also points here)
$ROADMASTER_DB_NAME = 'roadmaster_db-35313030b5e8';
$ROADMASTER_DB_HOST = '127.0.0.1';

$APP_ENV = 'development';

// Empty = app at http://localhost/public_html/
$APP_BASE_PATH = '/public_html';

// Encryption Key for secure OpenAI API key storage
$AI_ENCRYPTION_KEY = 'ultimate_secure_vault_secret_key_2026';
?>
