<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/orders-view-lib.php';
require_once dirname(__DIR__, 2) . '/invoices/includes/invoice-from-order.php';

ordersViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$module = isset($_GET['module']) ? trim((string) $_GET['module']) : 'sales';
if ($module === '') {
    $module = 'sales';
}

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Order id is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = sales_convert_order_to_invoice($orderId, $module, false);
    echo json_encode([
        'ok' => true,
        'order_id' => $orderId,
        'order_status' => 'invoiced',
        'invoice_id' => (int) ($result['invoice_id'] ?? 0),
        'redirect' => (string) ($result['redirect'] ?? ''),
        'stock_deduction' => $result['stock_deduction'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $message = $e->getMessage() !== '' ? $e->getMessage() : 'Could not create invoice from this order.';
    $code = str_contains(strtolower($message), 'not found') ? 404 : 400;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}
