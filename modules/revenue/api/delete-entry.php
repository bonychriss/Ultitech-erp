<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    $input = $_POST;
    if (empty($input) && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input') ?: '[]', true);
        $input = is_array($decoded) ? $decoded : [];
    }

    if (!verify_csrf($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your session has expired. Refresh the page and try again.']);
        exit;
    }

    $result = revenue_entries_delete($pdo, (int) ($input['id'] ?? 0));
    if (!$result['ok']) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['message']]);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => $result['message']]);
} catch (Throwable $e) {
    error_log('revenue delete-entry: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The entry could not be deleted. Please try again.']);
}
