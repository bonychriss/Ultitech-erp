<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$wmHelpers = __DIR__ . '/../../../todo/includes/weekly_mission_helpers.php';
if (is_file($wmHelpers)) {
    require_once $wmHelpers;
    if (function_exists('wm_ensure_tables')) {
        wm_ensure_tables($GLOBALS['pdo']);
    }
}
require_once __DIR__ . '/../includes/analytics_helpers.php';

global $pdo;
$section = trim((string) ($_GET['section'] ?? 'overview'));
$filters = analytics_parse_filters();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics-' . preg_replace('/[^a-z0-9_-]/i', '', $section) . '-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

switch ($section) {
    case 'performance':
        fputcsv($out, ['Employee', 'Department', 'Total Missions', 'Completed', 'Pending', 'Delayed', 'Completion %', 'Award Points', 'Streak']);
        foreach (analytics_performance_rows($pdo, $filters) as $row) {
            fputcsv($out, [
                $row['full_name'],
                $row['department'] ?? '',
                $row['total_missions'],
                $row['completed_missions'],
                $row['pending_missions'],
                $row['delayed_missions'],
                $row['completion_rate'],
                $row['award_points'],
                $row['streak_count'],
            ]);
        }
        break;

    case 'sales':
        fputcsv($out, ['Invoice #', 'Date', 'Customer', 'Total', 'Paid', 'Balance', 'Status']);
        foreach (analytics_sales_rows($pdo, $filters) as $row) {
            fputcsv($out, [
                $row['invoice_number'] ?? '',
                $row['invoice_date'] ?? '',
                $row['customer_name'] ?? '',
                $row['total_amount'] ?? 0,
                $row['amount_paid'] ?? 0,
                $row['balance_due'] ?? 0,
                $row['status'] ?? '',
            ]);
        }
        break;

    case 'finance':
        fputcsv($out, ['Reference', 'Date', 'Party', 'Source', 'Amount', 'Status']);
        foreach (analytics_finance_rows($pdo, $filters) as $row) {
            fputcsv($out, [
                $row['ref'] ?? '',
                $row['txn_date'] ?? '',
                $row['party'] ?? '',
                $row['source'] ?? '',
                $row['amount'] ?? 0,
                $row['status'] ?? '',
            ]);
        }
        break;

    case 'overview':
    default:
        $kpis = analytics_overview_kpis($pdo, $filters);
        fputcsv($out, ['Metric', 'Value']);
        fputcsv($out, ['Period Start', $filters['start_date']]);
        fputcsv($out, ['Period End', $filters['end_date']]);
        fputcsv($out, ['Total Sales', $kpis['total_sales']]);
        fputcsv($out, ['Total Expenses', $kpis['total_expenses']]);
        fputcsv($out, ['Net Profit', $kpis['net_profit']]);
        fputcsv($out, ['Pending Payments', $kpis['pending_payments']]);
        fputcsv($out, ['Low Stock Alerts', $kpis['low_stock_alerts']]);
        fputcsv($out, ['Employee Performance Score', $kpis['employee_performance_score']]);
        fputcsv($out, ['Mission Completion Rate', $kpis['mission_completion_rate']]);
        break;
}

fclose($out);
exit;
