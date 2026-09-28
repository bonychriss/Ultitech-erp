<?php

require_once __DIR__ . '/../../orders/includes/order-edit-lib.php';

invoicesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

$invoiceId = (int) ($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid invoice id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = sales_invoice_edit_init_data($invoiceId);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
