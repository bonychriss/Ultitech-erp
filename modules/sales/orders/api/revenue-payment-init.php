<?php

declare(strict_types=1);

/**
 * Order-view proxy for Revenue payment-init.
 */

require_once __DIR__ . '/../includes/orders-view-lib.php';
require_once dirname(__DIR__, 3) . '/revenue/includes/revenue-lib.php';
require_once dirname(__DIR__, 3) . '/revenue/includes/revenue-payment-lib.php';

ordersViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = revenueDeskBootstrap();
    $entryId = (int) ($_GET['id'] ?? $_GET['entry_id'] ?? 0);
    $result = revenue_payment_build_init($pdo, $entryId);
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
