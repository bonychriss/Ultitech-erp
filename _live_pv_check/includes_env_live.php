<?php
/**
 * Live Server Environment Configuration
 * 
 * Database credentials for ultimate.co.tz
 * Created: 2025-11-28
 * Updated: 2025-12-21 (Auto-Fix)
 */

// Database Configuration
$DB_HOST = 'sdb-86.hosting.stackcp.net';
$DB_NAME = 'ultimate_trading-35313030f83f';
$DB_USER = 'ultimategeneraltrading';
$DB_PASS = 'Baddyman2';

// Full ERP data (vouchers, sales_orders, customers) — from debug_company_data.php scan
$DATA_DB_NAME = 'new_trading_voucher-35313030c7e2';
$SALES_DB_NAME = 'new_trading_voucher-35313030c7e2';

// Environment Configuration
$APP_ENV = 'production';

// Site URL
$SITE_URL = 'https://ultitech.io';

// URLs are at domain root — NOT /public_html/
$APP_BASE_PATH = '';

// Encryption Key for secure OpenAI API key storage
$AI_ENCRYPTION_KEY = 'ultimate_secure_vault_secret_key_2026';
?>