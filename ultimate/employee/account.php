<?php
/**
 * Tenant URL entry: /{company_slug}/employee/account.php (e.g. /ultimate/employee/account.php)
 */
$__sn = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/employee/account\.php#i', $__sn, $__m)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__m[1];
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'employee' . DIRECTORY_SEPARATOR . 'account.php';
