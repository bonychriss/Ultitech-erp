<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/currency_helpers.php';
require_once __DIR__ . '/../includes/balances_integration.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $ctx = expenses_build_desk_form_init($pdo);
    $previewNumber = 'EXP-' . date('Ymd') . '-' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);

    echo json_encode(array_merge($ctx, [
        'csrf_token' => csrf_token(),
        'preview_expense_number' => $previewNumber,
    ]));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
