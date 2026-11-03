<?php
/**
 * Expenses list — React shell.
 * modules/expenses/index.php
 */
require_once __DIR__ . '/includes/expenses-lib.php';

expensesDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'expenses';
}

$page_title = 'Expenses';
$employeeHeaderTitle = 'Expenses';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'list';
$expensesDeskScrollable = true;

$expensesHomeUrl = function_exists('company_url')
    ? company_url('select-module')
    : (function_exists('app_url') ? app_url('/select-module.php') : '../../select-module.php');
$expensesBackUrl = function_exists('company_url')
    ? company_url('modules/accounting/index') . '?module=accounting'
    : (function_exists('app_url')
        ? app_url('/modules/accounting/index.php') . '?module=accounting'
        : '../accounting/index.php?module=accounting');
$employeeHeaderSubtitle = '<nav class="vv-breadcrumb exp-desk-breadcrumb" aria-label="Breadcrumb">'
    . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($expensesHomeUrl, ENT_QUOTES, 'UTF-8') . '">Home</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<a class="vv-breadcrumb-link vv-breadcrumb-link--active erp-nav-back-ignore" href="'
    . htmlspecialchars($expensesBackUrl, ENT_QUOTES, 'UTF-8') . '">Accounting</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<span class="vv-breadcrumb-current">Expenses</span>'
    . '</nav>';
$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Expenses</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Expenses</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$expensesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__EXPENSES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_PAGE__ = ' . json_encode($expensesPage, JSON_UNESCAPED_SLASHES) . ';</script>';

require __DIR__ . '/includes/expenses-react-shell.php';
