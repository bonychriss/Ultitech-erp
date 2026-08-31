<?php
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/analytics_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/smart_report_sales_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

$filters = smart_report_sales_parse_filters();
$customerId = (int) ($_GET['customer_id'] ?? 0);
$customerLabel = trim((string) ($_GET['customer_label'] ?? ''));

if ($customerId <= 0 && $customerLabel === '') {
    echo json_encode(['success' => false, 'error' => 'Customer is required.']);
    exit;
}

try {
    $invoices = smart_report_customer_invoices($pdo, $filters, $customerId, $customerLabel);
    $viewBase = '../sales/view.php?module=sales&id=';
    $months = smart_report_sales_month_columns($filters['start_date'], $filters['end_date']);
    echo json_encode([
        'success' => true,
        'count' => count($invoices),
        'html' => smart_report_render_customer_invoices_html($invoices, $viewBase, $months),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('customer_invoices api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Unable to load invoices.']);
}
