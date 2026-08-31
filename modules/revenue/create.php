<?php
/**
 * Record revenue � React shell.
 */
require_once __DIR__ . '/includes/revenue-lib.php';

revenueDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}
$_SESSION['active_module'] = 'revenue';

$page_title = 'create Revenue';
$employeeHeaderTitle = 'create Revenue';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--rev-desk employee-header--rev-create';
$bodyExtraClass = 'page-rev-desk page-rev-create';
$revenuePage = 'create';

$assets = revenueDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Create Revenue</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Create Revenue</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/revenue/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$revenueHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__REVENUE_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__REVENUE_PAGE__ = ' . json_encode($revenuePage, JSON_UNESCAPED_SLASHES) . ';</script>';

require __DIR__ . '/includes/revenue-react-shell.php';
