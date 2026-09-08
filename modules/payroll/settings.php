<?php
/**
 * Payroll settings — Laravel + React (erp-laravel Domains/Payroll).
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}
$_GET['desk'] = 'settings';
require dirname(__DIR__, 2) . '/payroll.php';
