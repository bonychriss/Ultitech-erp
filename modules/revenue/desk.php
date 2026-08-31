<?php
/**
 * Revenue entries list � React shell (served from revenue_entries.php).
 */
require_once __DIR__ . '/includes/revenue-lib.php';

revenueDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}

$page_title = 'Revenues';
$employeeHeaderTitle = 'Revenues';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--rev-desk';
$bodyExtraClass = 'page-rev-desk';
$revenuePage = 'list';

$assets = revenueDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Revenues</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Revenues</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/revenue/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$revenueHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__REVENUE_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__REVENUE_PAGE__ = ' . json_encode($revenuePage, JSON_UNESCAPED_SLASHES) . ';</script>';

require __DIR__ . '/includes/revenue-react-shell.php';
