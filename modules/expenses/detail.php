<?php
/**
 * Full-page detail for a direct expense entry.
 */
require_once __DIR__ . '/includes/expenses-lib.php';

expensesDeskRequireAccess();

$expenseId = (int) ($_GET['id'] ?? 0);
if ($expenseId <= 0) {
    header('Location: index.php?module=expenses');
    exit;
}

$_SESSION['active_module'] = 'expenses';
$page_title = 'Expense Details';
$employeeHeaderTitle = 'Expense Details';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'detail';
$expensesDeskScrollable = true;

$expensesHomeUrl = function_exists('company_url')
    ? company_url('select-module')
    : (function_exists('app_url') ? app_url('/select-module.php') : '../../select-module.php');
$expensesBackUrl = function_exists('company_url')
    ? company_url('modules/expenses/index') . '?module=expenses'
    : (function_exists('app_url') ? app_url('/modules/expenses/index.php') . '?module=expenses' : 'index.php?module=expenses');
$employeeHeaderSubtitle = '<nav class="vv-breadcrumb exp-desk-breadcrumb" aria-label="Breadcrumb">'
    . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($expensesHomeUrl, ENT_QUOTES, 'UTF-8') . '">Home</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($expensesBackUrl, ENT_QUOTES, 'UTF-8') . '">Expenses</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<span class="vv-breadcrumb-current">Expense Details</span>'
    . '</nav>';

$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Expense Details</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Expense Details</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$expensesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__EXPENSES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_PAGE__ = "detail";'
    . 'window.__EXPENSES_DETAIL_ID__ = ' . json_encode($expenseId) . ';</script>';

require __DIR__ . '/includes/expenses-react-shell.php';
