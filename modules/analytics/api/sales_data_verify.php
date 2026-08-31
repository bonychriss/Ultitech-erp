<?php
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/analytics_helpers.php';
require_once __DIR__ . '/../includes/smart_report_sales_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

$filters = smart_report_sales_parse_filters();

try {
    $displayed = smart_report_sales_drilldown($pdo, $filters);
    $displayed['pipeline_matrices'] = smart_report_pipeline_matrices($pdo, $filters);

    $verification = smart_report_sales_verify_displayed_data($pdo, $filters, $displayed);
    $analysis = smart_report_sales_ai_verify_analysis($pdo, $verification);

    echo json_encode([
        'success' => true,
        'accurate' => !empty($verification['accurate']),
        'check_count' => (int) ($verification['check_count'] ?? 0),
        'issue_count' => (int) ($verification['issue_count'] ?? 0),
        'issues' => $verification['issues'] ?? [],
        'company' => $verification['company'] ?? null,
        'period' => $verification['period'] ?? [],
        'verified_at' => $verification['verified_at'] ?? null,
        'analysis' => $analysis,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('sales_data_verify api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Unable to verify sales analytics data.']);
}
