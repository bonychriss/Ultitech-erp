<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../upload-evidence-handler.php';
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
$result = deliveries_process_evidence_upload($pdo, $orderId, $_FILES);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$refresh = deliveries_load_order_details_payload($pdo, ['order_id' => $orderId]);
if ($refresh['ok'] ?? false) {
    $result['data']['order'] = $refresh['data']['order'] ?? null;
    $result['data']['evidence'] = $refresh['data']['evidence'] ?? [];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
