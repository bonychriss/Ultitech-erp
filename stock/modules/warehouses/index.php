<?php
// stock/modules/warehouses/index.php — send Warehouses module to Store Management desk
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
requireLogin();

if (function_exists('company_url')) {
    $target = company_url('store-management-system/index.php') . '?module=warehouses';
} elseif (function_exists('app_url')) {
    $target = app_url('store-management-system/index.php?module=warehouses');
} else {
    $target = '/store-management-system/index.php?module=warehouses';
}

if (function_exists('redirect')) {
    redirect($target);
}

header('Location: ' . $target);
exit;
