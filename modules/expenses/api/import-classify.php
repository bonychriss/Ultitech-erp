<?php

require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/currency_helpers.php';
require_once __DIR__ . '/../includes/import_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

if (!verify_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$rows = $input['rows'] ?? [];
if (!is_array($rows) || $rows === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No rows to classify.']);
    exit;
}

try {
    $result = expenses_import_ai_classify_rows($pdo, $rows);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($result, $flags);
    if ($json === false) {
        throw new RuntimeException('Could not encode classify response: ' . json_last_error_msg());
    }
    echo $json;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
