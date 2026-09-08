<?php
/**
 * Alias: /ultimate/stock/modules/products/categories.php ? erp-laravel categories desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'categories';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
