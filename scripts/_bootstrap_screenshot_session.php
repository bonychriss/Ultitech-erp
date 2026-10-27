<?php
/**
 * Create a local logged-in Ultimate admin session for screenshot capture.
 * Prints JSON: { "cookie": "PVSSESSID=...", "session_id": "..." }
 */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/public_html/ultimate/accounting';
$_SERVER['HTTPS'] = 'off';

require_once dirname(__DIR__) . '/includes/functions.php';

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['email'] = 'admin@ultimatetrading.com';
$_SESSION['full_name'] = 'Ultimate Admin';
$_SESSION['role'] = 'admin';
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';
$_SESSION['active_module'] = 'accounting';

session_write_close();

$name = session_name();
$id = session_id();
$path = (defined('APP_BASE_PATH') && APP_BASE_PATH !== '' && APP_BASE_PATH !== '/')
    ? rtrim((string) APP_BASE_PATH, '/') . '/'
    : '/';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'cookie_name' => $name,
    'session_id' => $id,
    'cookie' => $name . '=' . $id,
    'path' => $path,
    'base' => 'http://localhost' . (defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') : '/public_html'),
], JSON_UNESCAPED_SLASHES);
