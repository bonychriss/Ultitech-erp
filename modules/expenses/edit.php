<?php
/**
 * Edit draft expense — React shell.
 * modules/expenses/edit.php
 */
require_once __DIR__ . '/includes/expenses-lib.php';

expensesDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'expenses';
}
$_SESSION['active_module'] = 'expenses';

$editId = (int) ($_GET['id'] ?? 0);
if ($editId <= 0) {
    header('Location: index.php?module=expenses');
    exit;
}

$page_title = 'Edit Draft';
$employeeHeaderTitle = 'Edit Draft';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'edit';

$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Edit Draft</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Edit Draft</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$expensesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__EXPENSES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_PAGE__ = ' . json_encode($expensesPage, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_EDIT_ID__ = ' . json_encode($editId, JSON_UNESCAPED_SLASHES) . ';</script>';

require __DIR__ . '/includes/expenses-react-shell.php';
