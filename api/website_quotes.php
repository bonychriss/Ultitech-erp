<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('isLoggedIn') || !isLoggedIn() || !function_exists('isUltimate') || !isUltimate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'quotes' => []]);
    exit;
}

$lib = __DIR__ . '/../stock/includes/web-services-lib.php';
$stockFn = __DIR__ . '/../stock/config/functions.php';
if (is_file($stockFn)) {
    require_once $stockFn;
}
if (!is_file($lib)) {
    echo json_encode(['success' => true, 'quotes' => []]);
    exit;
}
require_once $lib;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    if ((string) ($_POST['action'] ?? '') !== 'delete') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }
    if (!function_exists('verify_csrf') || !verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Refresh the page and try again.']);
        exit;
    }
    $raw = $_POST['quote_numbers'] ?? [$_POST['quote_number'] ?? ''];
    $quoteNumbers = [];
    foreach (is_array($raw) ? $raw : [$raw] as $value) {
        $value = is_string($value) ? substr(trim($value), 0, 64) : '';
        if ($value !== '') {
            $quoteNumbers[$value] = $value;
        }
    }
    $quoteNumbers = array_slice(array_values($quoteNumbers), 0, 500);
    try {
        $deleted = function_exists('webQuoteDeleteRequests') && webQuoteDeleteRequests($quoteNumbers);
    } catch (Throwable $e) {
        $deleted = false;
    }
    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => count($quoteNumbers) > 1
            ? 'These requests were not found. They may already be deleted.'
            : 'This request was not found. It may already be deleted.']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
$quotes = [];
try {
    if (function_exists('webQuoteRequestGroups')) {
        $quotes = webQuoteRequestGroups($pdo instanceof PDO ? $pdo : null);
    }
} catch (Throwable $e) {
    $quotes = [];
}

echo json_encode([
    'success' => true,
    'quotes' => array_slice($quotes, 0, 20),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
