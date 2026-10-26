<?php

require_once __DIR__ . '/../includes/orders-view-lib.php';

ordersViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);

try {
    $data = salesOrderViewInitData();
    $json = json_encode($data, $jsonFlags);
    if ($json === false) {
        throw new RuntimeException('Failed to encode order view: ' . json_last_error_msg());
    }
    echo $json;
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()], $jsonFlags) ?: '{"error":"Order view failed."}';
}
