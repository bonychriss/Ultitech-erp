<?php

require_once __DIR__ . '/../includes/invoices-lib.php';
require_once __DIR__ . '/../includes/quote-direct-create.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = sales_process_direct_quote_create($_POST);
    echo json_encode([
        'ok' => true,
        'order_id' => $result['order_id'],
        'redirect' => $result['redirect'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
