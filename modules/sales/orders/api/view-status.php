<?php

require_once __DIR__ . '/../includes/orders-view-lib.php';

ordersViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = ordersViewParseId($_GET);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Order id is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = trim((string) ($payload['action'] ?? ''));
if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Action is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $loaded = salesOrderViewLoadOrder($salesDb, $id);
    if ($loaded === null) {
        throw new RuntimeException('Sales Order not found.');
    }

    $result = salesOrderViewApplyStatusAction($salesDb, $id, $action, $loaded['order']);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['error' => $result['error'] ?? 'Status update failed.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $context = salesOrderViewLoadContext($id);
    echo json_encode([
        'ok' => true,
        'message' => $result['message'] ?? 'Status updated',
        'status' => $result['status'] ?? '',
        'data' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
