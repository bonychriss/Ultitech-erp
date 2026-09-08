<?php
declare(strict_types=1);

/**
 * ERP entry for Payroll — erp-laravel Domains/Payroll + React UI.
 * URL: /{company}/payroll or /{company}/modules/payroll/index ? payroll.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'payroll';
}
$_SESSION['active_module'] = 'payroll';

if (!isFinanceOrAdmin()) {
    $q = array_merge($_GET ?: [], ['module' => 'payroll']);
    $payslips = function_exists('app_url')
        ? app_url('/modules/payroll/my_payslips.php')
        : '/public_html/modules/payroll/my_payslips.php';
    header('Location: ' . $payslips . '?' . http_build_query($q));
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

$payrollPublicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/payroll.php');
$payrollPublicUrl = strtok($payrollPublicUrl, '?') ?: $payrollPublicUrl;

$GLOBALS['ERP_PAYROLL_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'payroll_url' => $payrollPublicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_PAYROLL_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'payroll';

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
$payrollDesks = [
    'run-payroll' => true,
];

if ($desk !== '' && isset($payrollDesks[$desk])) {
    $GLOBALS['ERP_ROUTE'] = '/payroll/desk/' . $desk;
    $GLOBALS['ERP_PAYROLL_ROUTE'] = $GLOBALS['ERP_ROUTE'];
    require $laravelRoot . '/bootstrap/erp-bridge.php';
    exit;
}

if ($desk !== '') {
    http_response_code(404);
    echo 'Payroll desk not found.';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/payroll';
$GLOBALS['ERP_PAYROLL_ROUTE'] = '/payroll';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
