<?php
/**
 * Physical alias so /ultimate/customer_statement/... works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}

require dirname(__DIR__, 2) . '/customer_statement/index.php';
