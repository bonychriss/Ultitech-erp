<?php
require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/update-badge.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$currentMonth = date('Y-m');

try {
    expenses_balances_bootstrap();
    expenses_backfill_pending_records($pdo);

    $categories = [];
    foreach (expenses_fetch_expense_sub_accounts($pdo) as $catRow) {
        $categories[] = [
            'id' => (int) ($catRow['id'] ?? 0),
            'name' => (string) ($catRow['label'] ?? $catRow['name'] ?? ''),
        ];
    }
    if ($categories === []) {
        try {
            $legacy = $pdo->query("SELECT id, name FROM erp_accounts WHERE type = 'expense' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($legacy as $row) {
                $categories[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $categories = [];
        }
    }

    // Same universe as the expenses table (receipts + General Payment vouchers).
    $listedStats = expenses_build_listed_status_breakdown($pdo, []);
    $totalCount = (int) ($listedStats['total'] ?? 0);
    $statusBreakdown = is_array($listedStats['breakdown'] ?? null) ? $listedStats['breakdown'] : [];

    $trendStats = expenses_build_listed_trend_stats($pdo, []);
    $postedMonthCount = (int) ($trendStats['posted_month_count'] ?? 0);
    $spendMonth = (float) ($trendStats['spend_month'] ?? 0);
    $trendRows = is_array($trendStats['trend'] ?? null) ? $trendStats['trend'] : [];
    $trendFrom = (string) ($trendStats['trend_from'] ?? ($currentMonth . '-01'));
    $trendTo = (string) ($trendStats['trend_to'] ?? date('Y-m-t', strtotime($trendFrom)));

    $summaryStats = expenses_build_listed_summary_stats($pdo, []);

    echo json_encode([
        'categories' => $categories,
        'paymentMethods' => [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'bank', 'label' => 'Bank transfer'],
        ],
        'stats' => [
            'posted_month_count' => $postedMonthCount,
            'spend_month' => $spendMonth,
            'total_count' => $totalCount,
            'current_month' => $currentMonth,
            'current_month_label' => date('F Y'),
            'status_breakdown' => $statusBreakdown,
            'trend' => $trendRows,
            'trend_from' => $trendFrom,
            'trend_to' => $trendTo,
            'total_exclusive' => (float) ($summaryStats['total_exclusive'] ?? 0),
            'input_vat' => (float) ($summaryStats['input_vat'] ?? 0),
            'total_inclusive' => (float) ($summaryStats['total_inclusive'] ?? 0),
            'total_bank' => (float) ($summaryStats['total_bank'] ?? 0),
            'total_cash' => (float) ($summaryStats['total_cash'] ?? 0),
            'total_payables' => (float) ($summaryStats['total_payables'] ?? 0),
        ],
        'updateBadge' => expenses_module_update_badge(),
        'summaryTraces' => expenses_build_kpi_traces($pdo),
        'aiAvailable' => function_exists('balances_ai_is_connected') && balances_ai_is_connected(),
        'csrf_token' => csrf_token(),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
