<?php
/**
 * Credit Vouchers → same voucher dashboard UI (unpaid credit filter).
 * modules/expenses/credit-vouchers.php
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$qs = [
    'module' => 'voucher',
    'payment' => 'credit',
];
$target = function_exists('company_url')
    ? (string) company_url('admin/dashboard.php') . '?' . http_build_query($qs)
    : (function_exists('app_url')
        ? app_url('/admin/dashboard.php') . '?' . http_build_query($qs)
        : '../../admin/dashboard.php?' . http_build_query($qs));

header('Location: ' . $target, true, 302);
exit;
