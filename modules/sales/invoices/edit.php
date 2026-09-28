<?php

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../orders/includes/order-edit-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . (function_exists('sales_module_url') ? sales_module_url('invoices/index.php', ['module' => 'sales']) : 'index.php?module=sales'));
    exit;
}

salesInvoiceEditRenderReactShell($id);
