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

$userId = (int) ($_SESSION['user_id'] ?? 0);

// Release the session lock so other desk requests are not blocked while importing.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$rows = $input['rows'] ?? [];
if (!is_array($rows) || $rows === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No rows to import.']);
    exit;
}

$accountId = (int) ($input['account_id'] ?? 0);
$mainAccountId = !empty($input['main_account_id']) ? (int) $input['main_account_id'] : null;
$sourceAccountId = (int) ($input['source_account_id'] ?? 0);
$paymentMethod = (string) ($input['payment_method'] ?? 'cash');
$currencyCode = (string) ($input['currency'] ?? $input['currency_code'] ?? 'TZS');
// Import always saves drafts; balances update only when the user posts later.
$postToLedger = false;

try {
    $result = expenses_import_commit_rows(
        $pdo,
        $rows,
        $accountId,
        $mainAccountId,
        $sourceAccountId,
        $paymentMethod,
        $currencyCode,
        $postToLedger,
        $userId,
        expenses_import_build_account_context($pdo)
    );

    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'Import failed.']);
        exit;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($result, $flags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not encode import result: ' . json_last_error_msg()]);
        exit;
    }
    echo $json;
} catch (Throwable $e) {
    error_log('import-commit: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
