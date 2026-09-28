<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$query = ['module' => 'sales', 'tab' => 'targets'];
$target = function_exists('sales_module_url')
    ? sales_module_url('settings/index.php', $query)
    : '';
if (!is_string($target) || $target === '' || preg_match('#/modules/sales/admin/targets\.php#i', $target)) {
    $target = function_exists('company_url') ? company_url('sales/settings') : '/sales/settings';
    $target .= (str_contains($target, '?') ? '&' : '?') . 'module=sales&tab=targets';
}

header('Location: ' . $target, true, 302);
exit;
