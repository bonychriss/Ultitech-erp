<?php

require_once __DIR__ . '/../includes/invoices-lib.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $data = sales_invoice_create_init_data();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
