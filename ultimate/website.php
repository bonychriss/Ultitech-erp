<?php
/**
 * Physical alias so /ultimate/website works.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'website';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'website.php';
