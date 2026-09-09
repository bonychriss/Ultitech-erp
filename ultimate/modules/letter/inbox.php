<?php
/**
 * Physical alias for Ultimate letter inbox desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_GET['desk'] = 'inbox';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'letter.php';
