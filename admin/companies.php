<?php
/**
 * Legacy URL: super-admin company console now lives in management.php (no tenant sidebar).
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
    http_response_code(403);
    die('Only super admin can manage companies.');
}

$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: management.php' . ($q !== '' ? '?' . $q : ''));
exit;
