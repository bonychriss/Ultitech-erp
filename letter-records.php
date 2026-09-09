<?php
/**
 * Legacy My Records URL → React Letter list.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
require __DIR__ . '/letter.php';
