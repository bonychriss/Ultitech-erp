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

    $deskMode = strtolower(trim((string) ($_GET['desk'] ?? '')));
    $isPurchasesDesk = ($deskMode === 'purchases');

    if ($isPurchasesDesk) {
        $voucherRows = expenses_fetch_stock_purchase_vouchers_as_list_rows($pdo, []);
        $statusBreakdown = [];
        $counts = [];
        $totalInclusive = 0.0;
        $totalBank = 0.0;
        $totalCash = 0.0;
        $totalPayables = 0.0;
        foreach ($voucherRows as $row) {
            $key = strtolower(trim((string) ($row['display_status'] ?? $row['status'] ?? 'pending')));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $amount = (float) ($row['amount'] ?? 0);
            $totalInclusive += $amount;
            $bucket = expenses_listed_row_payment_bucket($row);
            if ($bucket === 'cash') {
                $totalCash += $amount;
            } elseif ($bucket === 'bank') {
                $totalBank += $amount;
            } else {
                $totalPayables += $amount;
            }
        }
        foreach (['posted', 'paid', 'approved', 'pending', 'confirming', 'draft', 'rejected'] as $key) {
            if (($counts[$key] ?? 0) > 0) {
                $statusBreakdown[] = [
                    'key' => $key,
                    'label' => ucfirst($key),
                    'count' => (int) $counts[$key],
                ];
            }
        }

        echo json_encode([
            'desk' => 'purchases',
            'categories' => [],
            'paymentMethods' => [
                ['value' => 'cash', 'label' => 'Cash'],
                ['value' => 'bank', 'label' => 'Bank transfer'],
            ],
            'stats' => [
                'posted_month_count' => (int) ($counts['posted'] ?? 0),
                'spend_month' => $totalInclusive,
                'total_count' => count($voucherRows),
                'current_month' => $currentMonth,
                'current_month_label' => date('F Y'),
                'status_breakdown' => $statusBreakdown,
                'trend' => [],
                'trend_from' => $currentMonth . '-01',
                'trend_to' => date('Y-m-t'),
                'total_exclusive' => $totalInclusive,
                'input_vat' => 0,
                'total_inclusive' => $totalInclusive,
                'total_bank' => $totalBank,
                'total_cash' => $totalCash,
                'total_payables' => $totalPayables,
            ],
            'updateBadge' => null,
            'summaryTraces' => new stdClass(),
            'aiAvailable' => false,
            'csrf_token' => csrf_token(),
        ]);
        exit;
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
