<?php
/**
 * Alias: /ultimate/admin/sync-user-company-index.php ? parent admin/sync-user-company-index.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/sync-user-company-index.php');
