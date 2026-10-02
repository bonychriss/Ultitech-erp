<?php
/**
 * Website module entry.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'website';
}
require dirname(__DIR__, 2) . '/website.php';
