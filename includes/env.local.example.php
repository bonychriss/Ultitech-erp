<?php
// Local development overrides. Copy to ../env.local.php (project root) and adjust as needed.
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';

// Control DB (users, companies, auth)
$DB_NAME = 'ultimate_trading-35313030f83f';

// Ultimate tenant operational data (vouchers, sales, products)
$DATA_DB_NAME = 'new_trading_voucher-35313030c7e2';
$SALES_DB_NAME = 'new_trading_voucher-35313030c7e2';

// Roadmaster tenant (stock / procurement)
$ROADMASTER_DB_NAME = 'roadmaster_db-35313030b5e8';
$ROADMASTER_DB_HOST = 'localhost';

$APP_ENV = 'development';
// e.g. '/public_html' when the app lives at http://localhost/public_html/
$APP_BASE_PATH = '/public_html';
