<?php
// Copy this file to env.php and fill with your real credentials.
// This file is ignored by version control. Do not commit env.php.

// Example for production (cPanel / StackCP):
// $DB_HOST = 'sql123.epizy.com';
// $DB_NAME = 'epiz_12345678_pvs';
// $DB_USER = 'epiz_12345678';
// $DB_PASS = 'your-strong-password';
// $APP_BASE_PATH = '';

// Example for local development under XAMPP in subfolder:
// $DB_HOST = 'localhost';
// $DB_NAME = 'ultimate_trading_voucher';
// $DB_USER = 'root';
// $DB_PASS = '';
// $APP_BASE_PATH = '/payment-voucher-system';

// AI integration — required to encrypt the system-wide OpenAI API key at rest.
// Generate with: bin2hex(random_bytes(32)) or openssl rand -hex 32
// $AI_ENCRYPTION_KEY = 'your-long-random-secret-here';
