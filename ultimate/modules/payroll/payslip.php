<?php
/**
 * Physical alias so /ultimate/modules/payroll/payslip.php works.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}
require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'payroll' . DIRECTORY_SEPARATOR . 'payslip.php';
