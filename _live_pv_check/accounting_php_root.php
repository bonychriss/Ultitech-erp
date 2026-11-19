<?php
declare(strict_types=1);

/**
 * ERP entry for Accounting — erp-laravel Domains/Accounting + React hub.
 * URL: /{company}/accounting or /{company}/modules/accounting/index ? accounting.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}
$_SESSION['active_module'] = 'accounting';

if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
    $_SESSION['error'] = 'Access denied.';
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $dest = $slug !== '' && function_exists('company_url')
        ? company_url('select-module', $slug)
        : app_url('/select-module.php');
    header('Location: ' . $dest);
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');

$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/accounting.php');
$publicUrl = strtok($publicUrl, '?') ?: $publicUrl;

$GLOBALS['ERP_ACCOUNTING_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'accounting_url' => $publicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'is_admin' => function_exists('isAdmin') && isAdmin(),
    'is_finance' => function_exists('isFinance') && isFinance(),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_ACCOUNTING_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'accounting';

$laravelRoot = __DIR__ . '/erp-laravel';
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

$GLOBALS['ERP_ROUTE'] = '/accounting';
$GLOBALS['ERP_ACCOUNTING_ROUTE'] = '/accounting';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
