<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    echo json_encode(revenue_entries_init_meta($pdo));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
