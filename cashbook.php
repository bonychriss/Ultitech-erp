<?php
declare(strict_types=1);

/**
 * ERP entry for Cash Book — erp-laravel Domains/CashBook + React UI.
 * Replaces the old Petty Cash voucher / replenishment workflow.
 * URL: /{company}/modules/petty-cash/index ? cashbook.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

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
    . (string) ($_SERVER['REQUEST_URI'] ?? '/cashbook.php');
$publicUrl = strtok($publicUrl, '?') ?: $publicUrl;

$GLOBALS['ERP_CASHBOOK_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'cashbook_url' => $publicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_CASHBOOK_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'petty_cash';

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
$cashDesks = [
    'books' => true,
    'book' => true,
    'categories' => true,
    'reports' => true,
];

if ($desk !== '' && isset($cashDesks[$desk])) {
    if ($desk === 'book') {
        $bookId = (int) ($_GET['id'] ?? 0);
        if ($bookId <= 0) {
            header('Location: ' . (function_exists('app_url') ? app_url('/modules/petty-cash/index.php') : '/modules/petty-cash/index.php') . '?module=petty_cash');
            exit;
        }
        $GLOBALS['ERP_CASHBOOK_CONTEXT']['book_id'] = $bookId;
        $GLOBALS['ERP_CONTEXT']['book_id'] = $bookId;
    }
    $GLOBALS['ERP_ROUTE'] = '/cashbook/desk/' . $desk;
    $GLOBALS['ERP_CASHBOOK_ROUTE'] = $GLOBALS['ERP_ROUTE'];
    require $laravelRoot . '/bootstrap/erp-bridge.php';
    exit;
}

if ($desk !== '') {
    http_response_code(404);
    echo 'Cash Book desk not found.';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/cashbook';
$GLOBALS['ERP_CASHBOOK_ROUTE'] = '/cashbook';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
