<?php
/**
 * Legacy Write Letter → React Letter compose desk.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_GET['desk'] = 'compose';
require __DIR__ . '/letter.php';
