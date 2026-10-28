<?php
/**
 * Purchases desk  stock purchase voucher preview page.
 * modules/purchases/voucher.php?pv_id=123
 */
require_once dirname(__DIR__) . '/expenses/includes/expenses-lib.php';

expensesDeskRequireAccess();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'purchases';
}

$pvId = (int) ($_GET['pv_id'] ?? $_GET['id'] ?? 0);
if ($pvId <= 0) {
    $listUrl = function_exists('company_url')
        ? company_url('modules/purchases/index.php') . '?module=purchases'
        : (function_exists('app_url')
            ? app_url('/modules/purchases/index.php') . '?module=purchases'
            : './index.php?module=purchases');
    header('Location: ' . $listUrl);
    exit;
}

$page_title = 'Purchase voucher';
$employeeHeaderTitle = 'Purchase voucher';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$bodyExtraClass = 'page-exp-desk';
$expensesPage = 'voucher-preview';
$expensesDeskMode = 'purchases';
$expensesDeskScrollable = true;

$assets = expensesDeskLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Purchase voucher</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Purchase voucher</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/expenses/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

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
    . 'window.__PURCHASES_PV_ID__ = ' . json_encode($pvId, JSON_UNESCAPED_SLASHES) . ';</script>';

require dirname(__DIR__) . '/expenses/includes/expenses-react-shell.php';
