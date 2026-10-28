<?php
/**
 * Purchases desk voucher link  use the real payment voucher page.
 * modules/purchases/voucher.php?pv_id=123
 */
require_once dirname(__DIR__) . '/expenses/includes/expenses-lib.php';

expensesDeskRequireAccess();

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

$qs = 'id=' . $pvId . '&module=purchases&from=purchases';
$target = function_exists('company_url')
    ? company_url('employee/view-voucher.php') . '?' . $qs
    : (function_exists('app_url')
        ? app_url('/employee/view-voucher.php') . '?' . $qs
        : '../../employee/view-voucher.php?' . $qs);

header('Location: ' . $target);
exit;
