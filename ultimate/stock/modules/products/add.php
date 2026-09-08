<?php
/**
 * Alias: /ultimate/stock/modules/products/add.php ? erp-laravel product-create desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'product-create';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
