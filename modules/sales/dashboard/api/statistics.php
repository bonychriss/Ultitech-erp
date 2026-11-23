<?php

require_once __DIR__ . '/../../includes/dashboard-lib.php';

dashboardDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $dateFrom = date('Y-01-01');
    $dateTo = date('Y-m-d');
    $rows = getMostOutgoingProducts(100, 1, null, $dateFrom, $dateTo, true) ?: [];
    $products = dashboardDeskNormalizeProducts($rows, 'fa-box');
    $totalUnits = 0.0;
    $productOrderCount = 0;
    foreach ($rows as $row) {
        $totalUnits += (float) ($row['total_qty'] ?? 0);
        $productOrderCount += (int) ($row['outgoing_count'] ?? 0);
    }

    echo json_encode([
        'ok' => true,
        'period' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'year' => (int) date('Y'),
        ],
        'summary' => [
            'product_count' => count($products),
            'total_units' => $totalUnits,
            'product_order_count' => $productOrderCount,
        ],
        'products' => $products,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('sales statistics: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load year-to-date product statistics.',
    ], JSON_UNESCAPED_UNICODE);
}
