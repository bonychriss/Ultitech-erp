<?php
/**
 * Alias: /ultimate/stock/index.php ? erp-laravel Stock home (stock.php).
 * Physical ultimate/stock/ dir otherwise has no index and hits ErrorDocument 404.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stock.php';
