<?php

require_once __DIR__ . '/../../includes/my-sales-lib.php';

mySalesDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $data = mySalesInitData();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
