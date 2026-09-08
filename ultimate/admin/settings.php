<?php
/**
 * Alias: /ultimate/admin/settings.php and /ultimate/admin/settings
 * ? parent admin/settings.php (Settings hub) with Ultimate tenant context.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (empty($_GET['company_id'])) {
    $_GET['company_id'] = '1';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'settings';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/settings.php');
