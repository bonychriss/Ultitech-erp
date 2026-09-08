<?php
/**
 * Physical alias so /ultimate/accounting works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}
require dirname(__DIR__) . '/modules/accounting/index.php';
