<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = [];
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$action = (string) ($body['action'] ?? $_POST['action'] ?? '');

if ($action !== 'delete_trip') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tripId = (int) ($body['trip_id'] ?? $_POST['trip_id'] ?? 0);
$csrf = (string) ($body['csrf_token'] ?? $_POST['csrf_token'] ?? '');

if (!function_exists('verify_csrf') || !verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tripId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid trip.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmtUnlink = $pdo->prepare(
        "UPDATE delivery_orders SET trip_id = NULL, status = 'request_pending' WHERE trip_id = ?"
    );
    $stmtUnlink->execute([$tripId]);

    $stmtDel = $pdo->prepare('DELETE FROM delivery_trips WHERE id = ?');
    $stmtDel->execute([$tripId]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Trip deleted successfully.',
        'trip_id' => $tripId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
