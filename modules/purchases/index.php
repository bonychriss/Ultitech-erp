<?php
/**
 * Purchases desk — Stock Purchase payment vouchers (expenses layout).
 * modules/purchases/index.php
 */
require_once dirname(__DIR__) . '/expenses/includes/expenses-lib.php';

expensesDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'purchases';
}

$page_title = 'Purchases';
$employeeHeaderTitle = 'Purchases';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'list';
$expensesDeskMode = 'purchases';

$purchasesHomeUrl = function_exists('company_url')
    ? company_url('select-module')
    : (function_exists('app_url') ? app_url('/select-module.php') : '../../select-module.php');
$purchasesBackUrl = function_exists('company_url')
    ? company_url('modules/accounting/index') . '?module=accounting'
    : (function_exists('app_url')
        ? app_url('/modules/accounting/index.php') . '?module=accounting'
        : '../accounting/index.php?module=accounting');
$employeeHeaderSubtitle = '<nav class="vv-breadcrumb exp-desk-breadcrumb" aria-label="Breadcrumb">'
    . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($purchasesHomeUrl, ENT_QUOTES, 'UTF-8') . '">Home</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<a class="vv-breadcrumb-link vv-breadcrumb-link--active erp-nav-back-ignore" href="'
    . htmlspecialchars($purchasesBackUrl, ENT_QUOTES, 'UTF-8') . '">Accounting</a>'
    . '<span class="vv-breadcrumb-sep">/</span>'
    . '<span class="vv-breadcrumb-current">Purchases</span>'
    . '</nav>';

$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Purchases</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Purchases</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

// Always load assets + API from the expenses module (this page only sets desk mode).
$expensesWebBase = function_exists('app_url')
    ? rtrim((string) app_url('/modules/expenses'), '/')
    : '/modules/expenses';
if (function_exists('company_url')) {
    try {
        $expensesWebBase = rtrim((string) company_url('modules/expenses'), '/');
    } catch (Throwable $e) {
        // keep app_url fallback
    }
}
$assets['assetBase'] = $expensesWebBase . '/frontend/dist/assets/';
$expensesApiBase = $expensesWebBase . '/api';

$createVoucherQs = 'module=voucher&purpose=stock_purchase';
$createVoucherUrl = function_exists('company_url')
    ? company_url('employee/create-voucher.php') . '?' . $createVoucherQs
    : (function_exists('app_url')
        ? app_url('/employee/create-voucher.php') . '?' . $createVoucherQs
        : '../../employee/create-voucher.php?' . $createVoucherQs);

$expensesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__EXPENSES_API_BASE__ = ' . json_encode($expensesApiBase, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_PAGE__ = ' . json_encode($expensesPage, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__EXPENSES_DESK_MODE__ = ' . json_encode($expensesDeskMode, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__PURCHASES_CREATE_VOUCHER_URL__ = ' . json_encode($createVoucherUrl, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__PURCHASES_BACK_URL__ = ' . json_encode($purchasesBackUrl, JSON_UNESCAPED_SLASHES) . ';</script>';

// Shell script src also comes from $assets — point JS at expenses dist.
$assets['jsFile'] = $assets['jsFile'];
require dirname(__DIR__) . '/expenses/includes/expenses-react-shell.php';