<?php
declare(strict_types=1);

/**
 * ERP entry for Deliveries ? erp-laravel Domains/Deliveries + React UI.
 * Hub URL: /{company}/deliveries/hub?module=deliveries&company_slug=?
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_SESSION['active_module'] = 'deliveries';

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

$GLOBALS['ERP_DELIVERIES_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'company_name' => (string) ($_SESSION['company_name'] ?? ''),
    'back_url' => $backUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_DELIVERIES_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'deliveries';

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

$desk = strtolower(trim((string) ($_GET['desk'] ?? 'dashboard')));
if ($desk === '' || $desk === 'home' || $desk === 'launcher') {
    $desk = 'dashboard';
}
if ($desk === 'index') {
    $desk = 'dashboard';
}
if ($desk === 'create_delivery' || $desk === 'create-delivery' || $desk === 'create') {
    $desk = 'create_delivery';
}
if ($desk === 'order_details' || $desk === 'order-details' || $desk === 'order') {
    $desk = 'order_details';
}
if ($desk === 'delivery_notes' || $desk === 'delivery-notes' || $desk === 'notes') {
    $desk = 'delivery_notes';
}
if ($desk === 'create_delivery_note' || $desk === 'create-delivery-note' || $desk === 'create_note') {
    $desk = 'create_delivery_note';
}

if ($desk === 'create_delivery' && !empty($_GET['create_dispatch'])) {
    $_SESSION['active_module'] = 'dispatch';
}

$routeDesk = match ($desk) {
    'dashboard' => 'index',
    'create_delivery' => 'create_delivery',
    'order_details' => 'order_details',
    'delivery_notes' => 'delivery_notes',
    'create_delivery_note' => 'create_delivery_note',
    default => $desk,
};
$GLOBALS['ERP_ROUTE'] = '/deliveries/' . $routeDesk;
$GLOBALS['ERP_DELIVERIES_ROUTE'] = $GLOBALS['ERP_ROUTE'];
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
