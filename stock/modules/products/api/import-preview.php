<?php
// stock/modules/products/api/import-preview.php
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

if (function_exists('verify_csrf') && !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$mode = stock_import_norm_mode($_POST['mode'] ?? (stock_import_is_ultimate() ? 'general' : 'spare_part'));
if (stock_import_is_ultimate()) {
    $mode = 'general';
}

if (!isset($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please choose a spreadsheet file.']);
    exit;
}

$parsed = stock_import_read_upload($_FILES['file']);
if (empty($parsed['ok'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $parsed['message'] ?? 'Could not read file.']);
    exit;
}

$shapeErrors = [];
if (!stock_import_validate_shape($parsed['rows'] ?? [], $mode, $shapeErrors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $shapeErrors[0] ?? 'Invalid template.']);
    exit;
}

$outRows = [];
$valid = 0;
$invalid = 0;
foreach (($parsed['rows'] ?? []) as $idx => $r) {
    $analysis = stock_import_validate_row($pdo, (array) $r, $idx + 2, $mode);
    if (!empty($analysis['skip'])) {
        continue;
    }
    if (!empty($analysis['ok'])) {
        $valid++;
    } else {
        $invalid++;
    }
    $outRows[] = [
        'row_no' => (int) $analysis['row_no'],
        'ok' => !empty($analysis['ok']),
        'name' => (string) ($analysis['name'] ?? ''),
        'product_code' => (string) ($analysis['product_code'] ?? ''),
        'category' => (string) ($analysis['category'] ?? ''),
        'will_update' => !empty($analysis['will_update']),
        'issues' => $analysis['issues'] ?? [],
        'row_data' => $analysis['row_data'] ?? $r,
    ];
}

echo json_encode([
    'ok' => true,
    'file_name' => (string) ($parsed['file_name'] ?? ''),
    'mode' => $mode,
    'summary' => [
        'total' => count($outRows),
        'valid' => $valid,
        'invalid' => $invalid,
    ],
    'rows' => $outRows,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
