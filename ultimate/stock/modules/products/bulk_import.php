<?php
/**
 * Alias: /ultimate/stock/modules/products/bulk_import.php ? erp-laravel products-import desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'products-import';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
