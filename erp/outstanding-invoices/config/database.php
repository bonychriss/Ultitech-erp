<?php
/**
 * Bootstrap for ERP outstanding-invoices (path: staff/erp/outstanding-invoices/config/).
 */
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($pdo)) {
    die('Database connection failed. Check includes/config.php');
}

ensureOutstandingInvoicesSchema();
