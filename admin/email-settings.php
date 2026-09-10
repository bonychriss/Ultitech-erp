<?php
declare(strict_types=1);

/**
 * ERP entry for Email Configuration — erp-laravel Domains/Admin + React UI.
 * URL: /{company}/admin/email-settings.php?module=settings
 */
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'settings';
}
$_SESSION['active_module'] = 'settings';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('admin/settings.php?module=settings', $slug)
    : app_url('/admin/settings.php?module=settings');

$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$GLOBALS['ERP_ADMIN_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'desk' => 'email-settings',
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_ADMIN_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'settings';

$laravelRoot = dirname(__DIR__) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';

$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/admin/desk/email-settings';
$GLOBALS['ERP_ADMIN_ROUTE'] = $GLOBALS['ERP_ROUTE'];
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
