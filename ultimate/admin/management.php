<?php
/**
 * Alias: /ultimate/admin/management.php ? parent admin/management.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/management.php');
