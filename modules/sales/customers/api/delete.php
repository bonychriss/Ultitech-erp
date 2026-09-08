<?php

require_once __DIR__ . '/../includes/catalogue-lib.php';

customerCatalogueDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$customerId = (int) ($input['id'] ?? $input['customer_id'] ?? 0);
$result = customerDeleteById($customerId);

if (!empty($result['error'])) {
    http_response_code(422);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
