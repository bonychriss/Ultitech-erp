<?php
/**
 * Physical alias so /ultimate/revenue_create works.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}
$_GET['desk'] = 'create';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'revenue_entries.php';
