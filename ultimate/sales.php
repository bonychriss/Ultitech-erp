<?php
/**
 * Physical alias so /ultimate/sales works (on-disk ultimate/ folder).
 * Bridges into sales.php (Laravel + React sales desk).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sales.php';
