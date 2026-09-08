<?php
/**
 * Alias: /ultimate/admin/list-company-users.php ? parent admin/list-company-users.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_tenant_require.php';
ultimate_tenant_require('admin/list-company-users.php');
