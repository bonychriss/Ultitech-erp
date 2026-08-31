<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    pettyCashDeskRequireAccess();
    global $pdo;

    $scope = pettyCashDeskScope();
    $payload = petty_cash_build_create_form_init($pdo, (int) $scope['user_id']);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
