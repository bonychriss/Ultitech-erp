<?php
// stock/modules/products/api/import-commit.php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../import_helpers.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (function_exists('verify_csrf') && !verify_csrf($payload['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$mode = stock_import_norm_mode($payload['mode'] ?? (stock_import_is_ultimate() ? 'general' : 'spare_part'));
if (stock_import_is_ultimate()) {
    $mode = 'general';
}

$rows = $payload['rows'] ?? [];
if (!is_array($rows) || count($rows) === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No rows to import.']);
    exit;
}

// Accept either full analysis rows or raw row_data maps
$normalized = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (isset($row['row_data']) && is_array($row['row_data'])) {
        if (isset($row['ok']) && !$row['ok']) {
            continue;
        }
        $normalized[] = $row['row_data'];
    } else {
        $normalized[] = $row;
    }
}

if (!$normalized) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No valid rows to import.']);
    exit;
}

$result = stock_import_commit_rows($pdo, $normalized, $mode);
$imported = (int) ($result['imported'] ?? 0);
$updated = (int) ($result['updated'] ?? 0);

echo json_encode([
    'ok' => true,
    'imported' => $imported,
    'updated' => $updated,
    'skipped' => (int) ($result['skipped'] ?? 0),
    'errors' => $result['errors'] ?? [],
    'message' => "Imported {$imported} new, updated {$updated}.",
    'redirect' => 'index.php?bulk_import=success&imported=' . $imported . '&updated=' . $updated,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
