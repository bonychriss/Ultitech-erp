<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $result = revenue_entries_fetch($pdo, $_GET);
    echo json_encode([
        'data' => $result['entries'],
        'total' => $result['total'],
        'showing_from' => $result['showing_from'],
        'showing_to' => $result['showing_to'],
        'kpi' => $result['kpi'],
        'kpi_prev' => $result['kpi_prev'],
        'invoice_kpi' => $result['invoice_kpi'],
        'month' => $result['month'],
        'filters' => $result['filters'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
