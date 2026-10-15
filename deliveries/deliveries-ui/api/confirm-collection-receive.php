<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../client-signature-handler.php';
require_once __DIR__ . '/../confirm-collection-receive-handler.php';
require_once __DIR__ . '/../load-order-details-data.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!function_exists('verify_csrf') || !verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$qtyRaw = trim((string) ($_POST['received_quantity'] ?? ''));

if ($qtyRaw === '' || !is_numeric($qtyRaw)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid received quantity.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!deliveries_user_can_access_order($pdo, $orderId, $userId) && !deliveries_user_can_confirm_collection_receive()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = deliveries_process_collection_receive($pdo, $orderId, (float) $qtyRaw, $userId);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$refresh = deliveries_load_order_details_payload($pdo, ['order_id' => $orderId]);
if ($refresh['ok'] ?? false) {
    $result['data']['order'] = $refresh['data']['order'] ?? null;
    $result['data']['documents'] = $refresh['data']['documents'] ?? null;
    $result['data']['collection'] = $refresh['data']['collection'] ?? null;
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
