<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';
require_once __DIR__ . '/includes/orders-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (function_exists('salesOrdersListUsesReactShell') && salesOrdersListUsesReactShell()) {
    salesOrdersListRenderReactShell();
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Sales Orders</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>Sales Orders</h1>';
echo '<p>The React sales orders desk is not available. Build <code>modules/sales/orders/frontend</code> or disable the React shell gate.</p>';
echo '</body></html>';
