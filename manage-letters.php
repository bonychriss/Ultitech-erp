<?php
/**
 * Legacy Manage Letters URL → React Letter inbox.
 */
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (!function_exists('isAdmin') || !isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access Denied: Management Only.';
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_GET['desk'] = 'inbox';
require __DIR__ . '/letter.php';
