<?php
/**
 * Alias: /ultimate/stock/modules/products/index.php ? erp-laravel products desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'products';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
