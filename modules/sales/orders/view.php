<?php

require_once __DIR__ . '/includes/orders-view-lib.php';

ordersViewDeskRequireAccess();

$id = ordersViewParseId($_GET);
if ($id <= 0) {
    http_response_code(400);
    die('Order id is required.');
}

if (salesOrderViewShouldUseReact()) {
    salesOrderViewRenderReactShell($id);
}

require __DIR__ . '/view-legacy.php';
