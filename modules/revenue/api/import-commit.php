<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';
require_once __DIR__ . '/../includes/revenue-import-lib.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = revenueDeskBootstrap();
requireLogin();
if (!isFinance() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$rows = $input['rows'] ?? [];
if (!is_array($rows) || $rows === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No rows to import.']);
    exit;
}

$options = [
    // Imports always create unpaid receivables; users pay them from the revenue desk.
    'payment_mode' => 'Account Receivable',
    'tax_treatment' => (string) ($input['tax_treatment'] ?? 'Exclusive'),
    'vat_rate' => (string) ($input['vat_rate'] ?? '18'),
    'currency' => (string) ($input['currency'] ?? 'TZS'),
    'exchange_rate' => (float) ($input['exchange_rate'] ?? 1),
    'revenue_sub_account_id' => (int) ($input['revenue_sub_account_id'] ?? 0),
    'account_id' => 0,
];

try {
    $result = revenue_import_commit_rows($pdo, $rows, $options);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    $message = (string) ($result['message'] ?? 'Import complete');
    $message .= ' Record payments from the list below.';
    $result['message'] = $message;
    // Relative redirect keeps tenant slug and opens unpaid revenues ready to pay.
    $result['redirect'] = './revenue_entries.php?module=revenue&status=unpaid&success=' . rawurlencode($message);

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($result, $flags);
} catch (Throwable $e) {
    error_log('revenue import-commit: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
