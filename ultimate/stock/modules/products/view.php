<?php
/**
 * Alias: /ultimate/stock/modules/products/view.php ? erp-laravel product-view desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'product-view';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
