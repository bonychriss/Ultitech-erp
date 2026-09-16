<?php

declare(strict_types=1);

require_once __DIR__ . '/../../orders/includes/orders-lib.php';
require_once __DIR__ . '/../includes/quote-requests-lib.php';

ordersDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
    $rows = salesQuoteRequestsFetchAll($salesDb instanceof PDO ? $salesDb : null);

    $groups = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['quote_number'] ?? ''));
        if ($key === '') {
            $key = 'row-' . (int) ($row['id'] ?? 0);
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'quote_number' => $key,
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_phone' => (string) ($row['customer_phone'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'new'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'items' => [],
                'item_count' => 0,
            ];
        }
        $groups[$key]['items'][] = [
            'id' => (int) ($row['id'] ?? 0),
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
            'product_sku' => (string) ($row['product_sku'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'quantity' => (float) ($row['quantity'] ?? 1),
        ];
        $groups[$key]['item_count'] = count($groups[$key]['items']);
        if (
            $groups[$key]['created_at'] === ''
            || strcmp((string) ($row['created_at'] ?? ''), $groups[$key]['created_at']) > 0
        ) {
            $groups[$key]['created_at'] = (string) ($row['created_at'] ?? '');
        }
    }

    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
    $nothingAnimation = function_exists('app_url')
        ? app_url('/assets/animations/nothing.lottie')
        : '/assets/animations/nothing.lottie';
    echo json_encode([
        'requests' => array_values($groups),
        'count' => count($groups),
        'module' => $module,
        'nothing_animation' => $nothingAnimation,
        'urls' => [
            'list' => function_exists('sales_module_url')
                ? sales_module_url('quote-requests/index.php', ['module' => $module])
                : '',
            'quotations' => function_exists('sales_module_url')
                ? sales_module_url('orders/create.php', ['module' => $module])
                : '',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
