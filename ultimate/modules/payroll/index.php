<?php
/**
 * Physical alias so /ultimate/modules/payroll/index works.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'payroll.php';
