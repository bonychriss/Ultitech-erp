<?php
/**
 * Physical alias so /ultimate/manage-letters works ? letter inbox.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_GET['desk'] = 'inbox';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'letter.php';
