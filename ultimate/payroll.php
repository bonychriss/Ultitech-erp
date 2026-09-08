<?php
/**
 * Physical alias so /ultimate/payroll and /ultimate/modules/payroll/index work
 * (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'payroll.php';
