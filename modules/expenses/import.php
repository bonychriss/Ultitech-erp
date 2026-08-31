<?php
/**
 * Import expenses � React shell.
 * modules/expenses/import.php
 */
require_once __DIR__ . '/includes/expenses-lib.php';

expensesDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'expenses';
}
$_SESSION['active_module'] = 'expenses';

$page_title = 'Import Expenses';
$employeeHeaderTitle = 'Import Expenses';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'import';

$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Import Expenses</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Import Expenses</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$expensesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__EXPENSES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_PAGE__ = ' . json_encode($expensesPage, JSON_UNESCAPED_SLASHES) . ';</script>';

require __DIR__ . '/includes/expenses-react-shell.php';
