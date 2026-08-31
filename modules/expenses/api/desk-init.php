<?php
require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/update-badge.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$currentMonth = date('Y-m');
$scopeSql = expenses_scope_sql();

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

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql AND is_posted = 1 AND date LIKE ?");
    $stmt->execute(["$currentMonth%"]);
    $postedMonthCount = (int) ($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM erp_expenses WHERE $scopeSql AND is_posted = 1 AND date LIKE ?");
    $stmt->execute(["$currentMonth%"]);
    $spendMonth = (float) $stmt->fetchColumn();

    $totalCount = (int) ($pdo->query("SELECT COUNT(*) FROM erp_expenses WHERE $scopeSql")->fetchColumn() ?: 0);

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
