<?php
/**
 * Alias: /ultimate/stock/delete_bulk.php ? stock product bulk delete handler.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'delete_bulk.php';
