<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/includes/invoices-lib.php';

requireLogin();

if (function_exists('salesInvoicesListUsesReactShell') && salesInvoicesListUsesReactShell()) {
    salesInvoicesListRenderReactShell();
}

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Invoices</title></head><body style="font-family:sans-serif;padding:2rem;">';
echo '<h1>Invoices</h1>';
echo '<p>The React invoices desk is not available. Build <code>modules/sales/invoices/frontend</code> or disable the React shell gate.</p>';
echo '</body></html>';
