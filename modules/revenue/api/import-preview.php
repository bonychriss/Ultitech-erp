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

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$defaultYear = (int) ($_POST['default_year'] ?? date('Y'));
if ($defaultYear < 2000 || $defaultYear > 2100) {
    $defaultYear = (int) date('Y');
}

if (!isset($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please choose a spreadsheet file.']);
    exit;
}

$parsed = revenue_import_read_upload($_FILES['file']);
if (empty($parsed['ok'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $parsed['message'] ?? 'Could not read file.']);
    exit;
}

$headers = $parsed['headers'] ?? [];

$mapped = revenue_import_map_rows(
    $headers,
    $parsed['rows'] ?? [],
    $defaultYear,
    (int) ($parsed['header_row'] ?? 1)
);
$mapped = revenue_import_annotate_matches($pdo, $mapped);

$valid = 0;
$invalid = 0;
$newCustomers = 0;
$newProducts = 0;
foreach ($mapped as $row) {
    if (!empty($row['ok'])) {
        $valid++;
        if (!empty($row['will_create_customer'])) {
            $newCustomers++;
        }
        if (!empty($row['will_create_product'])) {
            $newProducts++;
        }
    } else {
        $invalid++;
    }
}

$payload = [
    'ok' => true,
    'file_name' => (string) ($_FILES['file']['name'] ?? ''),
    'default_year' => $defaultYear,
    'headers' => $headers,
    'rows' => $mapped,
    'summary' => [
        'total' => count($mapped),
        'valid' => $valid,
        'invalid' => $invalid,
        'new_customers' => $newCustomers,
        'new_products' => $newProducts,
    ],
];

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$json = json_encode($payload, $flags);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not encode preview: ' . json_last_error_msg()]);
    exit;
}
echo $json;
