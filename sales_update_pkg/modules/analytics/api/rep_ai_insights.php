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
$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId > 0 && !analytics_user_in_company($pdo, $userId)) {
    echo json_encode(['success' => false, 'error' => 'Access denied for this sales employee.']);
    exit;
}
$repName = trim((string) ($_GET['rep_name'] ?? ''));
if ($repName === '') {
    $repName = $userId > 0 ? 'Sales employee' : 'Unassigned';
}

try {
    $quotations = smart_report_rep_quotations($pdo, $filters, $userId);
    $invoices = smart_report_rep_invoices($pdo, $filters, $userId);
    $snapshot = smart_report_rep_performance_snapshot($pdo, $filters, $userId, $repName, $quotations, $invoices);
    $insights = smart_report_rep_fetch_ai_insights($pdo, $snapshot);

    echo json_encode([
        'success' => true,
        'source' => $insights['source'] ?? 'rules',
        'achievements' => $insights['achievements'] ?? [],
        'suggestions' => $insights['suggestions'] ?? [],
        'html' => smart_report_render_rep_ai_insights_html($insights, (string) ($insights['source'] ?? 'rules')),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('rep_ai_insights api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Unable to generate suggestions.']);
}
