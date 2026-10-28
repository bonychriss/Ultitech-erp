<?php

require_once __DIR__ . '/includes/invoices-view-lib.php';

invoicesViewDeskRequireAccess();

$id = invoicesViewParseId($_GET);
if ($id <= 0) {
    $missingNumber = trim((string) ($_GET['invoice_number'] ?? $_GET['number'] ?? ''));
    if ($missingNumber !== '' && function_exists('sales_module_url')) {
        header('Location: ' . sales_module_url('invoices/index.php', [
            'module' => 'sales',
            'q' => $missingNumber,
        ]));
        exit;
    }
    http_response_code(400);
    die('Invalid invoice id.');
}

if (salesInvoiceViewShouldUseReact()) {
    salesInvoiceViewRenderReactShell($id);
}

require __DIR__ . '/view-legacy.php';
