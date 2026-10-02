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
