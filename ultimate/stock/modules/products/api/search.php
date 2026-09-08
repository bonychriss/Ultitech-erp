<?php
/**
 * Alias: /ultimate/stock/modules/products/api/search.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'search.php';
