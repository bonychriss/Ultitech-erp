<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/includes/orders-lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

// Prefer short desk URL (/sales/orders) like quotations (/sales/quotations).
if (
    empty($GLOBALS['ERP_SALES_CONTEXT'])
    && (!isset($_GET['desk']) || trim((string) $_GET['desk']) === '')
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && function_exists('sales_module_url')
) {
    $legacyQuery = $_GET;
    unset($legacyQuery['company_slug'], $legacyQuery['desk']);
    $target = sales_module_url('orders/index.php', $legacyQuery);
    if (is_string($target) && $target !== '' && !preg_match('#/modules/sales/orders/index\.php(?:$|\?)#i', $target)) {
        header('Location: ' . $target, true, 302);
        exit;
    }
}

if (function_exists('salesOrdersListUsesReactShell') && salesOrdersListUsesReactShell()) {
    salesOrdersListRenderReactShell();
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Sales Orders</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>Sales Orders</h1>';
echo '<p>The React sales orders desk is not available. Build <code>modules/sales/orders/frontend</code> or disable the React shell gate.</p>';
echo '</body></html>';
