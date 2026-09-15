<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    storefrontApiJson(['success' => false, 'error' => 'POST required'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input)) {
    $input = $_POST;
}

try {
    $pdo = storefrontApiPdo();
    $result = storefrontApiCreateOrder($pdo, $input);
    storefrontApiJson($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    storefrontApiJson([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => 'exception',
    ], 500);
}
