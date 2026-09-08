<?php
/**
 * Physical alias so /ultimate/modules/sales/settings/... works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}

require dirname(__DIR__, 4) . '/modules/sales/settings/index.php';
