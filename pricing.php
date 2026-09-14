<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

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
    'page' => 'pricing',
    'homeUrl' => app_url('/'),
    'pricingUrl' => app_url('/pricing.php'),
    'loginUrl' => app_url('/login.php'),
    'accountUrl' => app_url('/my-account.php'),
    'trialUrl' => app_url('/free-trial.php'),
    'year' => (int) date('Y'),
];
$GLOBALS['ERP_HOME_ROUTE'] = '/pricing';

require $laravelRoot . '/bootstrap/erp-bridge.php';
