<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/balances_integration.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Expense ID is required.']);
    exit;
}

try {
    expenses_ensure_schema($pdo);

    if (!expenses_soft_delete_draft($pdo, $id)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft expense not found or cannot be deleted.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Draft deleted.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
