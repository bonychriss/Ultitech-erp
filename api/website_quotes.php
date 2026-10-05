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
    $quoteNumber = substr(trim((string) ($_POST['quote_number'] ?? '')), 0, 64);
    try {
        $deleted = $quoteNumber !== '' && function_exists('webQuoteDeleteRequest') && webQuoteDeleteRequest($quoteNumber);
    } catch (Throwable $e) {
        $deleted = false;
    }
    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This request was not found. It may already be deleted.']);
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
