<?php
declare(strict_types=1);

/**
 * Website module — React page for sending Ultimate products to ultimate.co.tz.
 * URL: /{company}/website
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}

if (!function_exists('isUltimate') || !isUltimate()) {
    header('Location: ' . ($slug !== '' ? company_url('select-module', $slug) : app_url('/select-module.php')));
    exit;
}

$_GET['module'] = 'website';
$_SESSION['active_module'] = 'website';

$ajax = (string) ($_GET['ajax'] ?? '');
if (in_array($ajax, ['sync', 'cancel'], true)) {
    require __DIR__ . '/stock/web-services.php';
    exit;
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (stripos($requestUri, '/stock/web-services') !== false) {
    header('Location: ' . company_url('website', $slug !== '' ? $slug : null));
    exit;
}

$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');

$GLOBALS['ERP_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'module' => 'website',
];

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

$GLOBALS['ERP_ROUTE'] = '/website';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
