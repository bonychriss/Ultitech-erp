<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../client-signature-handler.php';
require_once __DIR__ . '/../load-order-details-data.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_GET['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!deliveries_user_can_access_order($pdo, $orderId, $userId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = deliveries_check_client_signature($pdo, $orderId);
    if (($result['ok'] ?? false) && ($result['data']['signed'] ?? false)) {
        $refresh = deliveries_load_order_details_payload($pdo, ['order_id' => $orderId]);
        if ($refresh['ok'] ?? false) {
            $result['data']['order'] = $refresh['data']['order'] ?? null;
            $result['data']['documents'] = $refresh['data']['documents'] ?? null;
        }
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
