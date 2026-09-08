<?php
/**
 * Alias: /ultimate/stock/modules/brands/index.php ? erp-laravel brands desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
$_GET['module'] = 'stocks';
$_GET['desk'] = 'brands';
require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stock.php';
