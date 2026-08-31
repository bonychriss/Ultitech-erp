<?php
/**
 * Bootstrap for ERP petty-cash module (staff/erp/petty-cash/config/).
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../includes/petty_cash_functions.php';

if (!isset($pdo)) {
    die('Database connection failed. Check includes/config.php');
}

ensurePettyCashSchema();
