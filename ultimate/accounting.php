<?php
/**
 * Physical alias so /ultimate/accounting.php works (on-disk ultimate/ folder).
 * Must NOT be a full copy of accounting.php — that loads ultimate/includes
 * and drops company_slug, bouncing users to /login.php.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'accounting.php';
