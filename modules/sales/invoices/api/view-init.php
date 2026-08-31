<?php

require_once __DIR__ . '/../includes/invoices-view-lib.php';

invoicesViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $data = salesInvoiceViewInitData();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
