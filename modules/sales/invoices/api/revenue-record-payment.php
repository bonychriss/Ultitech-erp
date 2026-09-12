<?php

declare(strict_types=1);

/**
 * Invoice-view proxy for Revenue record-payment (keeps company-scoped invoices API base).
 */

require_once __DIR__ . '/../includes/invoices-view-lib.php';
require_once dirname(__DIR__, 3) . '/revenue/includes/revenue-lib.php';
require_once dirname(__DIR__, 3) . '/revenue/includes/revenue-payment-lib.php';

invoicesViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = revenueDeskBootstrap();
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
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
