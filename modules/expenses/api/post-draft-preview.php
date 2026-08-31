<?php

require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    } else {
        $input = $_POST;
    }
}

$id = (int) ($input['id'] ?? $input['expense_id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Expense ID is required.']);
    exit;
}

try {
    expenses_ensure_schema($pdo);
    $result = expenses_post_draft_preview($pdo, $id);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'Could not preview post.']);
        exit;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['ok' => true, 'preview' => $result['preview']], $flags);
} catch (Throwable $e) {
    error_log('post-draft-preview: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
