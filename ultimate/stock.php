<?php
/**
 * Physical alias so /ultimate/stock works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'stock.php';
