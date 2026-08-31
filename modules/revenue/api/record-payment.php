<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';
require_once __DIR__ . '/../includes/revenue-payment-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    $post = $_POST;
    if (empty($post) && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '[]', true);
        if (is_array($decoded)) {
            $post = $decoded;
        }
    }

    $result = revenue_payment_process($pdo, $post, $_FILES);
    if (empty($result['ok'])) {
        http_response_code(422);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => [$e->getMessage()]]);
}
