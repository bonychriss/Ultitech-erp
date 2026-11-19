<?php
/**
 * Physical alias so /ultimate/accounting/ultitech-bridge works when the
 * ultimate/accounting/ folder exists on disk (blocks generic rewrites).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('accounting/ultitech-bridge.php');
