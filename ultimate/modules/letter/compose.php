<?php
/**
 * Physical alias for Ultimate compose letter desk.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_GET['desk'] = 'compose';
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'letter.php';
