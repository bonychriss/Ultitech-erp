<?php
declare(strict_types=1);

/**
 * ERP entry for Letter — erp-laravel Domains/Letter + React letterhead UI.
 * URL: /{company}/letter or /{company}/modules/letter/index ? letter.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}
$_SESSION['active_module'] = 'letter';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');

$letterPublicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/letter.php');
$letterPublicUrl = strtok($letterPublicUrl, '?') ?: $letterPublicUrl;

$GLOBALS['ERP_LETTER_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'department' => (string) ($_SESSION['department'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'letter_url' => $letterPublicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_LETTER_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'letter';

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
$letterDesks = [
    'compose' => true,
    'stamp' => true,
];

if ($desk !== '' && isset($letterDesks[$desk])) {
    $GLOBALS['ERP_ROUTE'] = '/letter/desk/' . $desk;
    $GLOBALS['ERP_LETTER_ROUTE'] = $GLOBALS['ERP_ROUTE'];
    require $laravelRoot . '/bootstrap/erp-bridge.php';
    exit;
}

if ($desk !== '') {
    http_response_code(404);
    echo 'Letter desk not found.';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/letter';
$GLOBALS['ERP_LETTER_ROUTE'] = $GLOBALS['ERP_ROUTE'];
require $laravelRoot . '/bootstrap/erp-bridge.php';
