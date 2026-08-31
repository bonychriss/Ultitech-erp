<?php
/**
 * Toggle product label placed star marking.
 */
require_once __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/label-lib.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$placed = filter_var($_POST['placed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($productId < 1 || $placed === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

try {
    $isPlaced = sms_toggle_label_placed($pdo, $productId, $placed);
    echo json_encode([
        'success' => true,
        'product_id' => $productId,
        'placed' => $isPlaced,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
