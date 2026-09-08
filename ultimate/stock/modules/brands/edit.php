<?php
/**
 * Alias: /ultimate/stock/modules/brands/edit.php ? erp-laravel brand-edit desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'brand-edit';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
