<?php

require_once __DIR__ . '/../../orders/includes/order-edit-lib.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = sales_quote_edit_init_data($orderId);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
