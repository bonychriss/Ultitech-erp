<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/home-ui/lib.php';

if (isset($_SESSION['user_id'])) {
    if (isSuperAdmin()) {
        header('Location: ' . app_url('/admin/companies.php'));
        exit;
    }

    $sessionSlug = trim((string) ($_SESSION['company_slug'] ?? ''));
    header('Location: ' . company_dashboard_url($sessionSlug !== '' ? $sessionSlug : null));
    exit;
}

homeUiRenderReactShell([
    'homeUrl' => app_url('/'),
    'loginUrl' => app_url('/login.php'),
    'accountUrl' => app_url('/my-account.php'),
    'trialUrl' => app_url('/free-trial.php'),
    'year' => (int) date('Y'),
]);
