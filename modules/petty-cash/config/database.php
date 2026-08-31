<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/erp/petty-cash/includes/petty_cash_functions.php';
require_once dirname(__DIR__) . '/includes/petty-cash-lib.php';
require_once dirname(__DIR__) . '/includes/balances_integration.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection failed. Check includes/config.php');
}

ensurePettyCashSchema();
petty_cash_module_ensure_schema($pdo);
