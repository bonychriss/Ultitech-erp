<?php
/**
 * Alias: /ultimate/admin/company-settings.php ? parent admin/company-settings.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'settings';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/company-settings.php');
