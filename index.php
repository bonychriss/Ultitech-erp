<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    if (isSuperAdmin()) {
        header('Location: ' . app_url('/admin/companies.php'));
        exit;
    }

    $sessionSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
    header('Location: ' . company_dashboard_url($sessionSlug !== '' ? $sessionSlug : null));
    exit;
}

$laravelRoot = __DIR__ . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_HOME_CONTEXT'] = [
    'homeUrl' => app_url('/'),
    'loginUrl' => app_url('/login.php'),
    'accountUrl' => app_url('/my-account.php'),
    'trialUrl' => app_url('/free-trial.php'),
    'year' => (int) date('Y'),
];
$GLOBALS['ERP_HOME_ROUTE'] = '/home';

require $laravelRoot . '/bootstrap/erp-bridge.php';
