<?php

require_once __DIR__ . '/includes/invoices-view-lib.php';

invoicesViewDeskRequireAccess();

$id = invoicesViewParseId($_GET);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid invoice id.');
}

if (salesInvoiceViewShouldUseReact()) {
    salesInvoiceViewRenderReactShell($id);
}

require __DIR__ . '/view-legacy.php';
