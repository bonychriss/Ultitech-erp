<?php
declare(strict_types=1);

/**
 * ERP entry for Revenue — erp-laravel Domains/Revenue + existing React UI.
 * URL: /{company}/revenue_entries or /ultimate/revenue_entries?module=revenue
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}
$_SESSION['active_module'] = 'revenue';

if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
    header('Location: ' . app_url('/select-module.php?error=access_denied'));
    exit;
}

// Keep CSV export on the classic entry URL (React download links hit this file).
require_once __DIR__ . '/modules/revenue/includes/revenue-lib.php';
if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
    revenue_entries_export_csv(revenueDeskBootstrap(), $_GET);
    exit;
}

if (!empty($_GET['ren_probe'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $pdo = revenueDeskBootstrap();
    echo 'REVENUE_ENTRIES_LARAVEL=1' . "\n";
    echo 'db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
    echo 'rows=' . (int) $pdo->query('SELECT COUNT(*) FROM revenue_entries')->fetchColumn() . "\n";
    echo "OK\n";
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
    . (string) ($_SERVER['REQUEST_URI'] ?? '/revenue_entries.php');
$publicUrl = strtok($publicUrl, '?') ?: $publicUrl;

$GLOBALS['ERP_REVENUE_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'revenue_url' => $publicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'is_admin' => function_exists('isAdmin') && isAdmin(),
    'is_finance' => function_exists('isFinance') && isFinance(),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_REVENUE_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'revenue';

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

$desk = strtolower(trim((string) ($_GET['desk'] ?? '')));
$revenueDesks = [
    'list' => true,
    'create' => true,
    'import' => true,
];

if ($desk !== '' && isset($revenueDesks[$desk])) {
    if ($desk === 'list') {
        $GLOBALS['ERP_ROUTE'] = '/revenue';
        $GLOBALS['ERP_REVENUE_ROUTE'] = '/revenue';
    } else {
        $GLOBALS['ERP_ROUTE'] = '/revenue/desk/' . $desk;
        $GLOBALS['ERP_REVENUE_ROUTE'] = $GLOBALS['ERP_ROUTE'];
    }
    require $laravelRoot . '/bootstrap/erp-bridge.php';
    exit;
}

if ($desk !== '') {
    http_response_code(404);
    echo 'Revenue desk not found.';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/revenue';
$GLOBALS['ERP_REVENUE_ROUTE'] = '/revenue';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
