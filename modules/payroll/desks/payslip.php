<?php
/**
 * Legacy fallback for payslip desk when React dist is missing.
 * Serves the HTML payslip document (same as embed-capable payslip.php).
 */
if (!defined('PAYROLL_PAYSLIP_LEGACY_FALLBACK')) {
    define('PAYROLL_PAYSLIP_LEGACY_FALLBACK', true);
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}
require dirname(__DIR__) . '/payslip.php';
