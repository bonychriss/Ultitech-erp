<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    revenue_entries_export_csv($pdo, $_GET);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
}
