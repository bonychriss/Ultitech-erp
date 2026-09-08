<?php
/**
 * Alias: /ultimate/stock/edit.php ? erp-laravel product-edit desk.
 * Legacy short URL used by some product edit links.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$_GET['desk'] = 'product-edit';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'stock.php';
