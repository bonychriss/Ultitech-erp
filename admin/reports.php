<?php
require_once '../includes/functions.php';
requireAdmin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Date Range Filter Logic ---
$filter_mode = isset($_GET['range']) ? $_GET['range'] : 'all_time';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Calculate effective date range for SQL
if ($filter_mode === 'custom' && $start_date && $end_date) {
    // effective condition built later
} else {
    // Default to last 30 days if standard mode
    if ($filter_mode === '7days') {
        $start_date = date('Y-m-d', strtotime('-7 days'));
    } else if ($filter_mode === '30days') {
        $start_date = date('Y-m-d', strtotime('-30 days'));
    } else if ($filter_mode === 'this_month') {
        $start_date = date('Y-m-01');
    } else if ($filter_mode === 'last_month') {
        $start_date = date('Y-m-01', strtotime('first day of last month'));
        $end_date = date('Y-m-t', strtotime('last month'));
    } else if ($filter_mode === 'all_time') {
        $start_date = '2000-01-01';
        $end_date = date('Y-m-d');
    }
    // ensure end_date is set if not custom
    if (empty($end_date)) {
        $end_date = date('Y-m-d');
    }
}

$date_condition = "date_created BETWEEN '$start_date' AND '$end_date'";

// --- 1. Daily Statistics (for Line Chart) ---
$daily_sql = "
    SELECT 
        DATE(date_created) as date,
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as approved_amount
    FROM payment_vouchers 
    WHERE $date_condition
    GROUP BY DATE(date_created)
    ORDER BY date ASC
";
try {
    $stmt = $pdo->query($daily_sql);
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $daily_data = [];
}

// --- 2. Department Statistics (Department Manager's department) ---
$dept_sql = "
    SELECT 
        TRIM(pv.department_manager) as manager_name,
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN pv.status = 'approved' THEN pv.total_amount ELSE 0 END) as approved_amount
    FROM payment_vouchers pv
    WHERE pv.date_created BETWEEN '$start_date' AND '$end_date'
    GROUP BY TRIM(pv.department_manager)
";
$dept_rows = $pdo->query($dept_sql)->fetchAll(PDO::FETCH_ASSOC);
$deptLookup = [];
$deptTotals = [];
$usersHasDepartment = false;
try {
    $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $usersHasDepartment = in_array('department', $userCols, true);
} catch (Exception $e) {
    $usersHasDepartment = false;
}
foreach ($dept_rows as $row) {
    $managerName = trim((string) ($row['manager_name'] ?? ''));
    $deptLabel = 'Unspecified';
    if ($managerName !== '') {
        if (!array_key_exists($managerName, $deptLookup)) {
            $deptLookup[$managerName] = '';
            $managerId = function_exists('resolveVoucherUserIdByDisplayName')
                ? (int) resolveVoucherUserIdByDisplayName($pdo, $managerName)
                : 0;
            if ($managerId > 0 && $usersHasDepartment) {
                try {
                    $deptStmt = $pdo->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
                    $deptStmt->execute([$managerId]);
                    $deptLookup[$managerName] = trim((string) ($deptStmt->fetchColumn() ?: ''));
                } catch (Exception $e) {
                    $deptLookup[$managerName] = '';
                }
            }
        }
        if ($deptLookup[$managerName] !== '') {
            $deptLabel = $deptLookup[$managerName];
        }
    }
    if (!isset($deptTotals[$deptLabel])) {
        $deptTotals[$deptLabel] = [
            'department' => $deptLabel,
            'total_vouchers' => 0,
            'approved_amount' => 0.0,
        ];
    }
    $deptTotals[$deptLabel]['total_vouchers'] += (int) ($row['total_vouchers'] ?? 0);
    $deptTotals[$deptLabel]['approved_amount'] += (float) ($row['approved_amount'] ?? 0);
}
usort($deptTotals, static function ($a, $b) {
    return ($b['approved_amount'] <=> $a['approved_amount']);
});
$dept_data = array_values($deptTotals);

// --- 3. Payee Statistics (Top 10) ---
$payee_sql = "
    SELECT 
        payee_name,
        COUNT(*) as voucher_count,
        SUM(total_amount) as total_paid
    FROM payment_vouchers
    WHERE $date_condition AND status = 'approved'
    GROUP BY payee_name
    ORDER BY total_paid DESC
    LIMIT 10
";
$payee_data = $pdo->query($payee_sql)->fetchAll(PDO::FETCH_ASSOC);

// --- 4. Payment Type Statistics ---
$type_sql = "
    SELECT 
        vi.payment_type,
        COUNT(DISTINCT pv.id) as voucher_count,
        SUM(vi.amount) as total_amount
    FROM voucher_items vi
    JOIN payment_vouchers pv ON vi.voucher_id = pv.id
    WHERE pv.$date_condition AND pv.status = 'approved'
    GROUP BY vi.payment_type
    ORDER BY total_amount DESC
";
$type_data = $pdo->query($type_sql)->fetchAll(PDO::FETCH_ASSOC);
$typeTotal = 0.0;
foreach ($type_data as $row) {
    $typeTotal += (float) ($row['total_amount'] ?? 0);
}
$typePalette = ['#6d5df6', '#3b82f6', '#22c55e', '#fb923c', '#ef4444'];
$typeSlices = [];
$otherAmt = 0.0;
foreach ($type_data as $i => $row) {
    $label = trim((string) ($row['payment_type'] ?? ''));
    if ($label === '') {
        $label = 'Other';
    }
    $amt = (float) ($row['total_amount'] ?? 0);
    if ($i < 3) {
        $typeSlices[] = ['label' => $label, 'amount' => $amt];
    } else {
        $otherAmt += $amt;
    }
}
if ($otherAmt > 0) {
    $typeSlices[] = ['label' => 'Others', 'amount' => $otherAmt];
}
foreach ($typeSlices as $i => &$slice) {
    $slice['color'] = $typePalette[$i] ?? '#94a3b8';
    $slice['pct'] = $typeTotal > 0 ? round(($slice['amount'] / $typeTotal) * 100, 1) : 0;
}
unset($slice);
$typeCenter = $typeSlices[0] ?? ['label' => 'Payments', 'pct' => 0];

// --- 5. Budget Type Statistics ---
$budget_sql = "
    SELECT 
        vi.budget_type,
        SUM(vi.amount) as total_amount
    FROM voucher_items vi
    JOIN payment_vouchers pv ON vi.voucher_id = pv.id
    WHERE pv.$date_condition AND pv.status = 'approved'
    GROUP BY vi.budget_type
    ORDER BY total_amount DESC
";
$budget_data = $pdo->query($budget_sql)->fetchAll(PDO::FETCH_ASSOC);

$pvCols = [];
try {
    $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $pvCols = [];
}
$stockPurposeSql = function_exists('buildPaymentVoucherStockPurchasePurposeWhereSql')
    ? buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $pvCols)
    : '1 = 0';
$purpose_sql = "
    SELECT
        COALESCE(SUM(CASE WHEN {$stockPurposeSql} THEN pv.total_amount ELSE 0 END), 0) AS stock_purchase_amount,
        COALESCE(SUM(CASE WHEN NOT ({$stockPurposeSql}) THEN pv.total_amount ELSE 0 END), 0) AS general_amount
    FROM payment_vouchers pv
    WHERE pv.date_created BETWEEN '$start_date' AND '$end_date'
      AND pv.status = 'approved'
";
$purpose_row = $pdo->query($purpose_sql)->fetch(PDO::FETCH_ASSOC) ?: [];
$purposeGeneral = (float) ($purpose_row['general_amount'] ?? 0);
$purposeStock = (float) ($purpose_row['stock_purchase_amount'] ?? 0);
$purposeTotal = $purposeGeneral + $purposeStock;
$purposeSlices = [
    [
        'label' => 'General Payment',
        'amount' => $purposeGeneral,
        'color' => '#3b82f6',
        'pct' => $purposeTotal > 0 ? round(($purposeGeneral / $purposeTotal) * 100, 1) : 0,
    ],
    [
        'label' => 'Stock Purchases',
        'amount' => $purposeStock,
        'color' => '#6d5df6',
        'pct' => $purposeTotal > 0 ? round(($purposeStock / $purposeTotal) * 100, 1) : 0,
    ],
];
usort($purposeSlices, static function ($a, $b) {
    return ($b['amount'] <=> $a['amount']);
});
$purposeCenter = $purposeSlices[0] ?? ['label' => 'Payments', 'pct' => 0];

// --- Key Metrics ---
$stats_sql = "SELECT 
    SUM(CASE WHEN status = 'approved' AND currency = 'TZS' THEN total_amount ELSE 0 END) as total_spent_tzs,
    SUM(CASE WHEN status = 'approved' AND currency = 'USD' THEN total_amount ELSE 0 END) as total_spent_usd,
    COUNT(*) as total_tx
    FROM payment_vouchers 
    WHERE $date_condition";
$stats_res = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);
$total_spent_tzs = $stats_res['total_spent_tzs'] ?? 0;
$total_spent_usd = $stats_res['total_spent_usd'] ?? 0;
$total_tx = $stats_res['total_tx'] ?? 0;

$pending_sql = "SELECT COUNT(*) as count FROM payment_vouchers pv
    WHERE pv.status = 'pending'
      AND IFNULL(pv.is_draft,0) = 0
      AND IFNULL(pv.is_paid, 0) = 0
      AND IFNULL(pv.is_posted, 0) = 0
      AND NOT (
          COALESCE(pv.payee_name,'') = ''
          OR COALESCE(pv.payee_name,'') = '(Draft)'
          OR COALESCE(pv.total_amount,0) <= 0
          OR NOT EXISTS(SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = pv.id)
      )";
$pending_count = $pdo->query($pending_sql)->fetchColumn() ?: 0;

$rejected_sql = "SELECT COUNT(*) as count FROM payment_vouchers WHERE $date_condition AND status = 'rejected'";
$rejected_count = $pdo->query($rejected_sql)->fetchColumn() ?: 0;

    // --- Export Handler ---
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report_' . $start_date . '_to_' . $end_date . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM
        fputcsv($out, ['Voucher No', 'Date', 'Payee', 'Status', 'Total Amount', 'Department', 'Prepared By', 'Description']);
        
        $exp_sql = "
            SELECT pv.voucher_no, pv.date_created, pv.payee_name, pv.status, pv.total_amount, 
                   COALESCE(u.department, '') as department, pv.prepared_by, pv.description
            FROM payment_vouchers pv
            LEFT JOIN users u ON pv.created_by = u.id
            WHERE pv.$date_condition
            ORDER BY pv.date_created DESC
        ";
        $stmt = $pdo->query($exp_sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // --- AJAX Real-time Handler ---
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        header('Content-Type: application/json');
        echo json_encode([
            'metrics' => [
                'total_spent_tzs' => $total_spent_tzs,
                'total_spent_usd' => $total_spent_usd,
                'total_tx' => $total_tx,
                'pending_count' => $pending_count,
                'rejected_count' => $rejected_count
            ],
            'charts' => [
                'daily' => $daily_data,
                'dept' => $dept_data,
                'type' => $type_data,
                'budget' => $budget_data,
                'payee' => $payee_data,
                'purpose' => $purposeSlices,
            ]
        ]);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - SmartHR Style</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body.page-admin-reports.dashboard {
            background: #f8fafc !important;
            font-family: var(--erp-font-family, Inter, ui-sans-serif, system-ui, sans-serif);
            color: #0f172a;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk {
            background: #f8fafc !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 1.25rem !important;
            margin-bottom: 0;
            height: auto !important;
            min-height: 64px !important;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk .header-content {
            display: flex !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            padding: 8px 0 !important;
            min-height: 64px;
            background: transparent !important;
            gap: 0.5rem 1rem;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk .employee-header-page-heading {
            margin-left: 0 !important;
            min-width: 0;
            flex: 1 1 auto;
            gap: 4px !important;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk .employee-header-page-title {
            font-family: var(--erp-font-family, Inter, ui-sans-serif, system-ui, sans-serif) !important;
            font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            letter-spacing: -0.02em !important;
            line-height: 1.2 !important;
            white-space: nowrap;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk .employee-header-page-subtitle {
            font-size: 0.82rem !important;
            color: inherit !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            line-height: 1.3 !important;
            margin: 0 !important;
        }
        body.page-admin-reports .admin-header.employee-header--exp-desk .header-right.header-actions-tray {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            margin-left: auto !important;
            flex: 0 0 auto !important;
            align-self: center;
            gap: 0.55rem !important;
            overflow: visible;
        }
        body.page-admin-reports .rp-header-tools {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.55rem;
            min-width: 0;
        }
        .vv-breadcrumb {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            margin: 0;
            padding: 0;
            list-style: none;
            font-family: var(--erp-font-family, Inter, ui-sans-serif, system-ui, sans-serif);
            font-size: 0.82rem;
            line-height: 1.3;
        }
        .vv-breadcrumb-link {
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.15s ease;
        }
        .vv-breadcrumb-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }
        .vv-breadcrumb-link--active {
            color: #4f46e5;
            font-weight: 600;
        }
        .vv-breadcrumb-link--active:hover {
            color: #4338ca;
        }
        .vv-breadcrumb-sep {
            color: #94a3b8;
            user-select: none;
        }
        .vv-breadcrumb-current {
            color: #64748b;
            font-weight: 600;
        }
        .rp-export-menu { position: relative; display: inline-flex; align-items: center; flex: 0 0 auto; }
        .rp-export-btn {
            width: 2.15rem;
            height: 2.15rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.65rem;
            background: #fff;
            color: #334155;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            padding: 0;
            flex-shrink: 0;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .rp-export-btn:hover:not(:disabled),
        .rp-export-menu.is-open .rp-export-btn {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .rp-export-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .rp-export-panel {
            position: fixed;
            z-index: 1400;
            min-width: 10.5rem;
            padding: 0.3rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
            display: none;
        }
        .rp-export-panel.is-open { display: block; }
        .rp-export-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 0.45rem;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: #334155;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.55rem 0.65rem;
            cursor: pointer;
            text-align: left;
            font-family: inherit;
        }
        .rp-export-item:hover:not(:disabled) {
            background: #eef2ff;
            color: #4338ca;
        }
        .rp-export-item:disabled { opacity: 0.5; cursor: not-allowed; }
        .rp-spin { animation: rp-spin 0.8s linear infinite; }
        @keyframes rp-spin { to { transform: rotate(360deg); } }
        html[data-theme="dark"] body.page-admin-reports .rp-export-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
            box-shadow: none;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-export-btn:hover:not(:disabled),
        html[data-theme="dark"] body.page-admin-reports .rp-export-menu.is-open .rp-export-btn {
            background: #334155;
            border-color: #475569;
            color: #f8fafc;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-export-panel {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
        }
        html[data-theme="dark"] body.page-admin-reports .rp-export-item { color: #e2e8f0; }
        html[data-theme="dark"] body.page-admin-reports .rp-export-item:hover:not(:disabled) {
            background: rgba(99, 102, 241, 0.18);
            color: #c7d2fe;
        }
        .rp-filter-wrap { position: relative; flex: 0 0 auto; }
        .rp-filter-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            padding: 0;
            border-radius: 0.7rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .rp-filter-btn:hover,
        .rp-filter-btn.is-active {
            border-color: #c7d2fe;
            color: #4f46e5;
            background: #fff;
        }
        .rp-filter-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #4f46e5;
        }
        .rp-filter-popover {
            position: fixed; width: min(360px, calc(100vw - 24px));
            background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12); padding: 18px; z-index: 1300;
            display: none;
            font-family: var(--erp-font-family, Inter, ui-sans-serif, system-ui, sans-serif);
        }
        .rp-filter-popover.is-open { display: block; }
        .rp-filter-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .rp-filter-title { font-size: 15px; font-weight: 700; color: #0f172a; }
        .rp-filter-sub { font-size: 12px; color: #64748b; margin-top: 2px; }
        .rp-filter-close {
            width: 30px; height: 30px; border: 0; background: #f8fafc; color: #64748b;
            border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .rp-filter-close:hover { background: #eef2f7; color: #0f172a; }
        .rp-filter-section { margin-bottom: 14px; }
        .rp-filter-label {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;
        }
        .rp-filter-help { font-size: 12px; color: #64748b; margin: 0 0 10px; line-height: 1.4; }
        .rp-filter-select, .rp-filter-input {
            width: 100%; height: 42px; border-radius: 10px; border: 1px solid #dbe3ee;
            padding: 0 12px; font-size: 13px; color: #0f172a; background: #fff; outline: none;
            font-family: inherit;
        }
        .rp-filter-select:focus, .rp-filter-input:focus { border-color: #a78bfa; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); }
        .rp-custom-dates { grid-template-columns: 1fr 1fr; gap: 12px; }
        .rp-filter-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #eef2f7; }
        .rp-filter-reset { font-size: 13px; font-weight: 600; color: #64748b; text-decoration: none; }
        .rp-filter-reset:hover { color: #0f172a; }
        .rp-filter-apply {
            height: 40px; padding: 0 16px; border: 0; border-radius: 10px;
            background: #4f46e5; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .rp-filter-apply:hover { background: #4338ca; }
        html[data-theme="dark"] body.page-admin-reports.dashboard {
            background: #111b21 !important;
            color: #e9edef;
        }
        html[data-theme="dark"] body.page-admin-reports .admin-header.employee-header--exp-desk {
            background: #111b21 !important;
        }
        html[data-theme="dark"] body.page-admin-reports .admin-header.employee-header--exp-desk .employee-header-page-title {
            color: #e9edef !important;
        }
        html[data-theme="dark"] body.page-admin-reports .admin-header.employee-header--exp-desk a.vv-breadcrumb-link:not(.btn):not(.company-logo-link):not(.logout-btn):not(.action-btn):not(.app-item):not(.rd-btn-primary):not(.rd-btn-success):not(.rd-btn-danger) {
            color: #8696a0 !important;
        }
        html[data-theme="dark"] body.page-admin-reports .admin-header.employee-header--exp-desk a.vv-breadcrumb-link:not(.btn):not(.company-logo-link):not(.logout-btn):not(.action-btn):not(.app-item):hover {
            color: #e9edef !important;
            text-decoration: underline;
        }
        html[data-theme="dark"] body.page-admin-reports .admin-header.employee-header--exp-desk a.vv-breadcrumb-link--active:not(.btn):not(.company-logo-link):not(.logout-btn):not(.action-btn):not(.app-item):not(.rd-btn-primary):not(.rd-btn-success):not(.rd-btn-danger) {
            color: #a5b4fc !important;
        }
        html[data-theme="dark"] body.page-admin-reports .vv-breadcrumb-sep,
        html[data-theme="dark"] body.page-admin-reports .vv-breadcrumb-current {
            color: #8696a0 !important;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-btn {
            background: #111b21 !important;
            border: 1px solid #2a3942 !important;
            color: #e9edef !important;
            box-shadow: none !important;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-btn:hover,
        html[data-theme="dark"] body.page-admin-reports .rp-filter-btn.is-active {
            background: #202c33 !important;
            border-color: #3b4a54 !important;
            color: #e9edef !important;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-popover {
            background: #1f2c33;
            border-color: #2a3942;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-title,
        html[data-theme="dark"] body.page-admin-reports .rp-filter-label {
            color: #e9edef;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-sub,
        html[data-theme="dark"] body.page-admin-reports .rp-filter-help,
        html[data-theme="dark"] body.page-admin-reports .rp-filter-reset {
            color: #8696a0;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-close {
            background: #111b21;
            color: #8696a0;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-select,
        html[data-theme="dark"] body.page-admin-reports .rp-filter-input {
            background: #111b21;
            border-color: #2a3942;
            color: #e9edef;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-filter-footer {
            border-top-color: #2a3942;
        }
        @media (max-width: 640px) { .rp-custom-dates { grid-template-columns: 1fr; } }

        :root {
            --primary-bg: #f7f7f7;
            --c-orange: #FF902F;
            --c-purple: #811ba6;
            --c-green: #2ACF7F;
            --c-blue: #405189;
            --c-red: #f62d51;
            --c-light-gray: #f2f2f2;
            --card-shadow: 0 4px 6px rgba(0,0,0,0.02), 0 1px 3px rgba(0,0,0,0.05);
        }
        body.dashboard { background-color: var(--primary-bg); font-family: var(--erp-font-family, Inter, ui-sans-serif, system-ui, sans-serif); color: #333; }
        .layout-main-wrapper { display: flex; width: 100%; min-height: 100vh; }
        .main-content { padding: 30px; flex-grow: 1; transition: all 0.3s; }
        body.page-admin-reports .main-content {
            padding: 14px 1.25rem 2rem !important;
        }
        
        /* Summary cards — same compact size as credit voucher KPIs */
        body.page-admin-reports .rp-kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-top: 0;
            margin-bottom: 16px;
        }
        body.page-admin-reports .rp-charts {
            padding-top: 4px;
        }
        body.page-admin-reports .rp-kpis + .row,
        body.page-admin-reports .rp-charts > .row {
            --bs-gutter-y: 1.5rem;
            margin-top: 0;
        }
        body.page-admin-reports .dash-card {
            background: #fff;
            border: 1px solid #edf0f5;
            border-radius: 12px;
            padding: 8px 12px;
            margin-bottom: 0;
            min-height: 64px;
            height: auto;
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .03);
            transition: transform .15s ease, box-shadow .15s ease;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }
        body.page-admin-reports a.dash-card:hover,
        body.page-admin-reports a.dash-card:focus {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, .06);
            color: inherit;
            text-decoration: none;
        }
        body.page-admin-reports .dash-widget-info {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        body.page-admin-reports .dash-widget-info span {
            font-size: 11px;
            color: #111827;
            font-weight: 500;
            line-height: 1.2;
            text-transform: none;
            letter-spacing: 0;
        }
        body.page-admin-reports .dash-widget-info h3 {
            font-size: 15px;
            font-weight: 500;
            letter-spacing: -0.02em;
            margin: 1px 0 0;
            color: #111827;
            white-space: nowrap;
            line-height: 1.2;
        }
        body.page-admin-reports .dash-card--wide .dash-widget-info h3 {
            font-size: 12.5px;
        }
        body.page-admin-reports .dash-widget-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: 0;
            font-size: 12px;
            color: inherit;
        }
        body.page-admin-reports .dash-widget-icon.bg-blue { background: #eff6ff; color: #3b82f6; }
        body.page-admin-reports .dash-widget-icon.bg-purple { background: #eef2ff; color: #6366f1; }
        body.page-admin-reports .dash-widget-icon.bg-orange { background: #fff7ed; color: #f59e0b; }
        body.page-admin-reports .dash-widget-icon.bg-red { background: #fef2f2; color: #ef4444; }
        body.page-admin-reports .dash-widget-icon.bg-green { background: #ecfdf5; color: #10b981; }
        html[data-theme="dark"] body.page-admin-reports .dash-card {
            background: #1e293b;
            border-color: #334155;
            box-shadow: none;
        }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-info span,
        html[data-theme="dark"] body.page-admin-reports .dash-widget-info h3 {
            color: #f8fafc;
        }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-icon.bg-blue { background: rgba(59, 130, 246, 0.22); color: #93c5fd; }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-icon.bg-purple { background: rgba(99, 102, 241, 0.22); color: #a5b4fc; }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-icon.bg-orange { background: rgba(245, 158, 11, 0.22); color: #fcd34d; }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-icon.bg-red { background: rgba(239, 68, 68, 0.22); color: #fca5a5; }
        html[data-theme="dark"] body.page-admin-reports .dash-widget-icon.bg-green { background: rgba(16, 185, 129, 0.22); color: #6ee7b7; }
        @media (max-width: 1100px) {
            body.page-admin-reports .rp-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        
        /* Filters Bar */
        .filter-card { background: #fff; border-radius: 10px; padding: 15px 25px; margin-bottom: 25px; border: 1px solid #ededed; box-shadow: var(--card-shadow); }
        .filter-title { font-size: 18px; font-weight: 700; color: #1f1f1f; margin-right: auto; }
        
        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-header h2 { font-size: 22px; font-weight: 700; margin: 0; color: #111; }
        
        body.page-admin-reports .rp-chart-card {
            background: #fff;
            border: 1px solid #eef2f7;
            border-radius: 22px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            padding: 18px 20px 12px;
            margin-bottom: 0;
            height: 100%;
        }
        body.page-admin-reports .rp-chart-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        body.page-admin-reports .rp-chart-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        body.page-admin-reports .rp-chart-title svg { color: #6d5df6; flex-shrink: 0; }
        body.page-admin-reports .rp-chart-toggle {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 3px;
            border-radius: 999px;
            background: #f4f6fb;
        }
        body.page-admin-reports .rp-chart-toggle button {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            padding: 7px 12px;
            border-radius: 999px;
            cursor: pointer;
            font-family: inherit;
        }
        body.page-admin-reports .rp-chart-toggle button.is-active {
            background: #fff;
            color: #6d5df6;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
        }
        body.page-admin-reports .rp-chart-pill {
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            background: #f4f6fb;
            border-radius: 999px;
            padding: 7px 12px;
            line-height: 1;
            white-space: nowrap;
        }
        body.page-admin-reports .rp-chart-canvas--tall { height: 280px; }
        body.page-admin-reports .rp-chart-card .table-responsive {
            max-height: 280px;
            margin: 0 -4px;
        }
        body.page-admin-reports .rp-chart-card .table-custom th {
            background: transparent;
            color: #94a3b8;
            font-size: 11px;
            padding: 8px 10px;
        }
        body.page-admin-reports .rp-chart-card .table-custom td {
            padding: 10px;
            font-size: 13px;
        }
        body.page-admin-reports .rp-chart-canvas {
            position: relative;
            height: 240px;
        }
        body.page-admin-reports .rp-donut-layout {
            display: flex;
            align-items: center;
            gap: 18px;
            min-height: 240px;
        }
        body.page-admin-reports .rp-donut-wrap {
            position: relative;
            width: 180px;
            height: 180px;
            flex: 0 0 180px;
        }
        body.page-admin-reports .rp-donut-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            text-align: center;
            padding: 12px;
        }
        body.page-admin-reports .rp-donut-center strong {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
            line-height: 1;
        }
        body.page-admin-reports .rp-donut-center span {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            max-width: 88px;
            line-height: 1.25;
        }
        body.page-admin-reports .rp-donut-legend {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
            flex: 1 1 auto;
        }
        body.page-admin-reports .rp-donut-legend li {
            display: grid;
            grid-template-columns: 10px 1fr auto;
            align-items: start;
            gap: 8px;
            font-size: 12px;
            color: #334155;
        }
        body.page-admin-reports .rp-donut-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 5px;
        }
        body.page-admin-reports .rp-donut-name { font-weight: 600; line-height: 1.25; }
        body.page-admin-reports .rp-donut-pct { font-weight: 700; color: #0f172a; }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-card {
            background: #1e293b;
            border-color: #334155;
            box-shadow: none;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-title,
        html[data-theme="dark"] body.page-admin-reports .rp-donut-center strong,
        html[data-theme="dark"] body.page-admin-reports .rp-donut-pct {
            color: #f8fafc;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-toggle { background: #0f172a; }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-toggle button { color: #94a3b8; }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-toggle button.is-active {
            background: #334155;
            color: #c4b5fd;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-chart-pill {
            background: #0f172a;
            color: #94a3b8;
        }
        html[data-theme="dark"] body.page-admin-reports .rp-donut-name { color: #cbd5e1; }
        @media (max-width: 991px) {
            body.page-admin-reports .rp-donut-layout { flex-direction: column; }
        }

        /* Table Styles */
        .card-table { background: #fff; border-radius: 10px; border: 1px solid #ededed; box-shadow: var(--card-shadow); overflow: hidden; margin-bottom: 25px; }
        .card-table-header { padding: 20px 25px; border-bottom: 1px solid #f2f2f2; display: flex; justify-content: space-between; align-items: center; }
        .card-table-header h4 { font-size: 16px; font-weight: 700; margin: 0; color: #111; }
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th { background: #fafafa; padding: 12px 25px; font-size: 12px; font-weight: 600; color: #777; text-transform: uppercase; text-align: left; border-bottom: 1px solid #f2f2f2; }
        .table-custom td { padding: 15px 25px; font-size: 14px; color: #444; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .table-custom tr:last-child td { border-bottom: none; }
        .table-custom tr:hover td { background: #fefefe; }
        
        .btn-smart { border-radius: 6px; padding: 5px 12px; font-size: 13px; font-weight: 600; transition: all 0.3s; }
        .btn-orange { background: var(--c-orange); color: #fff; border: none; }
        .btn-orange:hover { background: #e67e22; color: #fff; transform: translateY(-1px); }
        .btn-blue { background: var(--c-blue); color: #fff; border: none; }
        .btn-blue:hover { background: #344475; color: #fff; transform: translateY(-1px); }
        .btn-outline-secondary { border-color: #ddd; color: #666; }
        .btn-outline-secondary:hover { background: #f9f9f9; color: #333; border-color: #ccc; }

        @media (max-width: 991px) {
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .filter-card { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body class="dashboard page-admin-reports">
            <?php
            $reportModule = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
            $reportModuleLabel = $reportModule !== '' ? ucwords(str_replace(['_', '-'], ' ', $reportModule)) : 'Accounting';
            $employeeHeaderTitle = 'Reports';
            $employeeHeaderSubtitle = '<nav class="vv-breadcrumb exp-desk-breadcrumb" aria-label="Breadcrumb">'
                . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars(function_exists('app_url') ? app_url('/') : '../index.php', ENT_QUOTES, 'UTF-8') . '">Home</a>'
                . '<span class="vv-breadcrumb-sep">/</span>'
                . '<a class="vv-breadcrumb-link vv-breadcrumb-link--active erp-nav-back-ignore" href="' . htmlspecialchars(function_exists('company_url') ? company_url('admin/dashboard.php') : 'dashboard.php', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($reportModuleLabel, ENT_QUOTES, 'UTF-8') . '</a>'
                . '<span class="vv-breadcrumb-sep">/</span>'
                . '<span class="vv-breadcrumb-current">Reports</span>'
                . '</nav>';
            $employeeHeaderExtraClass = 'employee-header--exp-desk';
            $hideHeaderCompanyBranding = true;

            $filterIsActive = ($filter_mode !== 'all_time');
            $rangeLabels = [
                '7days' => 'Last 7 Days',
                '30days' => 'Last 30 Days',
                'this_month' => 'This Month',
                'last_month' => 'Last Month',
                'all_time' => 'All Time',
                'custom' => 'Custom Range',
            ];
            $activeRangeLabel = $rangeLabels[$filter_mode] ?? 'All Time';
            ob_start();
            ?>
            <div class="rp-header-tools">
            <div class="rp-export-menu" id="rpExportMenu">
                <button type="button" class="rp-export-btn" id="rpExportBtn" aria-haspopup="menu" aria-expanded="false" aria-label="Export" title="Export">
                    <svg class="rp-export-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <svg class="rp-export-spinner rp-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
                </button>
                <div class="rp-export-panel" id="rpExportPanel" role="menu" aria-label="Export options">
                    <button type="button" class="rp-export-item" id="rpExportCsv" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M8 13h2"></path><path d="M8 17h2"></path><path d="M14 13h2"></path><path d="M14 17h2"></path></svg>
                        Export CSV
                    </button>
                    <button type="button" class="rp-export-item" id="rpExportPdf" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M12 18v-6"></path><path d="m9 15 3 3 3-3"></path></svg>
                        Export PDF
                    </button>
                </div>
            </div>
            <div class="rp-filter-wrap" id="rpFilterWrap">
                <button type="button" class="rp-filter-btn <?= $filterIsActive ? 'is-active' : '' ?>" id="rpFilterBtn" aria-label="Filters" title="Filters" aria-haspopup="dialog" aria-expanded="false">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="21" y1="4" x2="14" y2="4"></line><line x1="10" y1="4" x2="3" y2="4"></line><line x1="21" y1="12" x2="12" y2="12"></line><line x1="8" y1="12" x2="3" y2="12"></line><line x1="21" y1="20" x2="16" y2="20"></line><line x1="12" y1="20" x2="3" y2="20"></line><line x1="14" y1="2" x2="14" y2="6"></line><line x1="8" y1="10" x2="8" y2="14"></line><line x1="16" y1="18" x2="16" y2="22"></line></svg>
                    <?php if ($filterIsActive): ?><span class="rp-filter-dot" aria-hidden="true"></span><?php endif; ?>
                </button>
                <div class="rp-filter-popover" id="rpFilterPopover" role="dialog" aria-label="Report filters">
                    <form method="GET" id="filterForm" class="rp-filter-form">
                        <?php if ($reportModule !== ''): ?>
                            <input type="hidden" name="module" value="<?= htmlspecialchars($reportModule, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <div class="rp-filter-head">
                            <div>
                                <div class="rp-filter-title">Filters</div>
                                <div class="rp-filter-sub">Refine report data by time period.</div>
                            </div>
                            <button type="button" class="rp-filter-close" id="rpFilterClose" aria-label="Close filters">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>

                        <div class="rp-filter-section">
                            <label class="rp-filter-label" for="rpRangeSelect">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                Date range
                            </label>
                            <p class="rp-filter-help">Set a date range below to filter report data.</p>
                            <select name="range" id="rpRangeSelect" class="rp-filter-select" onchange="toggleCustomDates(this.value)">
                                <option value="7days" <?= $filter_mode == '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="30days" <?= $filter_mode == '30days' ? 'selected' : '' ?>>Last 30 Days</option>
                                <option value="this_month" <?= $filter_mode == 'this_month' ? 'selected' : '' ?>>This Month</option>
                                <option value="last_month" <?= $filter_mode == 'last_month' ? 'selected' : '' ?>>Last Month</option>
                                <option value="all_time" <?= $filter_mode == 'all_time' ? 'selected' : '' ?>>All Time</option>
                                <option value="custom" <?= $filter_mode == 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>

                        <div class="rp-filter-section rp-custom-dates" id="custom_dates" style="display:<?= $filter_mode == 'custom' ? 'grid' : 'none' ?>;">
                            <div>
                                <label class="rp-filter-label" for="rpStartDate">From</label>
                                <input type="date" id="rpStartDate" name="start_date" class="rp-filter-input" value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="rp-filter-label" for="rpEndDate">To</label>
                                <input type="date" id="rpEndDate" name="end_date" class="rp-filter-input" value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="rp-filter-footer">
                            <a href="<?= htmlspecialchars(function_exists('company_url') ? company_url('admin/reports.php' . ($reportModule !== '' ? '?module=' . urlencode($reportModule) : '')) : ('reports.php' . ($reportModule !== '' ? '?module=' . urlencode($reportModule) : '')), ENT_QUOTES, 'UTF-8') ?>" class="rp-filter-reset">Reset</a>
                            <button type="submit" class="rp-filter-apply">Apply filters</button>
                        </div>
                    </form>
                </div>
            </div>
            </div>
            <?php
            $employeeHeaderRightHtml = ob_get_clean();
            require_once '../includes/header_admin.php';
            ?>
            
            <main class="main-content">
                <div id="report-content">
                    <!-- Key Metrics -->
                    <?php
                    $kpiModuleQs = $reportModule !== '' ? ['module' => $reportModule] : ['module' => 'voucher'];
                    $kpiDashUrl = (function_exists('company_url') ? company_url('admin/dashboard.php') : 'dashboard.php')
                        . '?' . http_build_query($kpiModuleQs);
                    $kpiAllUrl = (function_exists('company_url') ? company_url('admin/all-vouchers.php') : 'all-vouchers.php')
                        . '?' . http_build_query($kpiModuleQs);
                    $kpiListBase = function_exists('company_url') ? company_url('admin/all-vouchers.php') : 'all-vouchers.php';
                    $kpiPendingUrl = $kpiListBase . '?' . http_build_query(['status' => 'pending'] + $kpiModuleQs);
                    $kpiRejectedUrl = $kpiListBase . '?' . http_build_query(['status' => 'rejected'] + $kpiModuleQs);
                    ?>
                    <div class="rp-kpis">
                            <a class="dash-card dash-card--wide" href="<?= htmlspecialchars($kpiDashUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="dash-widget-info">
                                    <span>Total Approved Spent</span>
                                    <h3 id="stat-tzs">TZS <?= number_format($total_spent_tzs) ?></h3>
                                    <h3 id="stat-usd">USD <?= number_format($total_spent_usd) ?></h3>
                                </div>
                                <div class="dash-widget-icon bg-blue"><i class="fas fa-wallet"></i></div>
                            </a>
                            <a class="dash-card" href="<?= htmlspecialchars($kpiAllUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="dash-widget-info">
                                    <span>Total Vouchers</span>
                                    <h3 id="stat-tx"><?= number_format($total_tx) ?></h3>
                                </div>
                                <div class="dash-widget-icon bg-purple"><i class="fas fa-file-invoice"></i></div>
                            </a>
                            <a class="dash-card" href="<?= htmlspecialchars($kpiPendingUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="dash-widget-info">
                                    <span>Pending Action</span>
                                    <h3 id="stat-pending"><?= number_format($pending_count) ?></h3>
                                </div>
                                <div class="dash-widget-icon bg-orange"><i class="fas fa-clock"></i></div>
                            </a>
                            <a class="dash-card" href="<?= htmlspecialchars($kpiRejectedUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="dash-widget-info">
                                    <span>Rejected Items</span>
                                    <h3 id="stat-rejected"><?= number_format($rejected_count) ?></h3>
                                </div>
                                <div class="dash-widget-icon bg-red"><i class="fas fa-times-circle"></i></div>
                            </a>
                    </div>

                    <div class="rp-charts">
                    <!-- Charts Row 1 -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-8">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"></path><path d="M7 16v-5"></path><path d="M12 16V8"></path><path d="M17 16v-8"></path></svg>
                                        Daily Spending Trend
                                    </h4>
                                    <div class="rp-chart-toggle" role="tablist" aria-label="Spending chart type">
                                        <button type="button" class="is-active" data-chart-type="line" id="rpDailyLineBtn">Line Chart</button>
                                        <button type="button" data-chart-type="bar" id="rpDailyBarBtn">Bar Chart</button>
                                    </div>
                                </div>
                                <div class="rp-chart-canvas"><canvas id="dailyChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
                                        Payment Methods
                                    </h4>
                                </div>
                                <div class="rp-donut-layout">
                                    <div class="rp-donut-wrap">
                                        <canvas id="typeChart"></canvas>
                                        <div class="rp-donut-center">
                                            <strong id="rpDonutPct"><?= htmlspecialchars(rtrim(rtrim(number_format((float) ($typeCenter['pct'] ?? 0), 1), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span id="rpDonutLabel"><?= htmlspecialchars((string) ($typeCenter['label'] ?? 'Payments'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <ul class="rp-donut-legend" id="rpDonutLegend">
                                        <?php foreach ($typeSlices as $slice): ?>
                                            <li>
                                                <span class="rp-donut-dot" style="background: <?= htmlspecialchars($slice['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                                <span class="rp-donut-name"><?= htmlspecialchars($slice['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="rp-donut-pct"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $slice['pct'], 1), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 2 -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-7">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>
                                        Department Spending
                                    </h4>
                                    <span class="rp-chart-pill">Bar Chart</span>
                                </div>
                                <div class="rp-chart-canvas rp-chart-canvas--tall"><canvas id="deptChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                        Top Payees
                                    </h4>
                                    <span class="rp-chart-pill">By Value</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th>Payee Name</th>
                                                <th class="text-end">Total (TZS)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payee_data as $index => $p): ?>
                                                <tr>
                                                    <td>
                                                        <span class="text-muted me-2 small">#<?= $index + 1 ?></span>
                                                        <span class="fw-600"><?= htmlspecialchars($p['payee_name']) ?></span>
                                                    </td>
                                                    <td class="text-end fw-bold text-dark"><?= number_format($p['total_paid']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Row -->
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1v22"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                        Budget Utilization
                                    </h4>
                                    <span class="rp-chart-pill">Bar Chart</span>
                                </div>
                                <div class="rp-chart-canvas rp-chart-canvas--tall"><canvas id="budgetChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="rp-chart-card">
                                <div class="rp-chart-head">
                                    <h4 class="rp-chart-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                                        PV Purpose
                                    </h4>
                                    <span class="rp-chart-pill">Approved spend</span>
                                </div>
                                <div class="rp-donut-layout">
                                    <div class="rp-donut-wrap">
                                        <canvas id="purposeChart"></canvas>
                                        <div class="rp-donut-center">
                                            <strong id="rpPurposePct"><?= htmlspecialchars(rtrim(rtrim(number_format((float) ($purposeCenter['pct'] ?? 0), 1), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span id="rpPurposeLabel"><?= htmlspecialchars((string) ($purposeCenter['label'] ?? 'Payments'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <ul class="rp-donut-legend" id="rpPurposeLegend">
                                        <?php foreach ($purposeSlices as $slice): ?>
                                            <li>
                                                <span class="rp-donut-dot" style="background: <?= htmlspecialchars($slice['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                                <span class="rp-donut-name"><?= htmlspecialchars($slice['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="rp-donut-pct"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $slice['pct'], 1), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div> <!-- End report-content -->
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });

        function toggleCustomDates(val) {
            const el = document.getElementById('custom_dates');
            if (el) el.style.display = (val === 'custom') ? 'grid' : 'none';
        }

        function setReportExportBusy(busy) {
            const trigger = document.getElementById('rpExportBtn');
            const icon = trigger && trigger.querySelector('.rp-export-icon');
            const spinner = trigger && trigger.querySelector('.rp-export-spinner');
            if (trigger) trigger.disabled = !!busy;
            if (icon) icon.hidden = !!busy;
            if (spinner) spinner.hidden = !busy;
        }

        (function initReportFilterPopover() {
            const wrap = document.getElementById('rpFilterWrap');
            const btn = document.getElementById('rpFilterBtn');
            const pop = document.getElementById('rpFilterPopover');
            const closeBtn = document.getElementById('rpFilterClose');
            if (!wrap || !btn || !pop) return;
            const positionPopover = () => {
                const r = btn.getBoundingClientRect();
                const pad = 12;
                const w = Math.min(360, window.innerWidth - pad * 2);
                const left = Math.min(Math.max(pad, r.right - w), window.innerWidth - w - pad);
                const top = Math.min(r.bottom + 10, window.innerHeight - 120);
                pop.style.width = w + 'px';
                pop.style.left = left + 'px';
                pop.style.top = top + 'px';
            };
            const close = () => { pop.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false'); };
            const open = () => { positionPopover(); pop.classList.add('is-open'); btn.setAttribute('aria-expanded', 'true'); };
            btn.addEventListener('click', (e) => { e.stopPropagation(); pop.classList.contains('is-open') ? close() : open(); });
            closeBtn && closeBtn.addEventListener('click', close);
            document.addEventListener('click', (e) => { if (!wrap.contains(e.target) && !pop.contains(e.target)) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
            window.addEventListener('resize', () => { if (pop.classList.contains('is-open')) positionPopover(); });
            window.addEventListener('scroll', () => { if (pop.classList.contains('is-open')) positionPopover(); }, true);
        })();

        (function initReportExportMenu() {
            const menu = document.getElementById('rpExportMenu');
            const btn = document.getElementById('rpExportBtn');
            const panel = document.getElementById('rpExportPanel');
            const csvBtn = document.getElementById('rpExportCsv');
            const pdfBtn = document.getElementById('rpExportPdf');
            if (!menu || !btn || !panel) return;
            const positionPanel = () => {
                const r = btn.getBoundingClientRect();
                const gap = 6;
                const w = panel.offsetWidth || 168;
                const h = panel.offsetHeight || 96;
                const spaceBelow = window.innerHeight - r.bottom;
                const openUp = spaceBelow < h + gap + 8 && r.top > spaceBelow;
                const top = openUp ? r.top - gap - h : r.bottom + gap;
                const left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
                panel.style.top = top + 'px';
                panel.style.left = left + 'px';
            };
            const close = () => {
                panel.classList.remove('is-open');
                menu.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            };
            const open = () => {
                panel.classList.add('is-open');
                menu.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                positionPanel();
            };
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.contains('is-open') ? close() : open();
            });
            csvBtn && csvBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                close();
                exportCSV();
            });
            pdfBtn && pdfBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                close();
                downloadPDF();
            });
            document.addEventListener('click', (e) => {
                if (!menu.contains(e.target) && !panel.contains(e.target)) close();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
            window.addEventListener('resize', () => { if (panel.classList.contains('is-open')) positionPanel(); });
            window.addEventListener('scroll', () => { if (panel.classList.contains('is-open')) positionPanel(); }, true);
        })();

        // Initialize Charts Globally
        let dailyChart, deptChart, typeChart, budgetChart, purposeChart;
        const rpTrendPurple = '#6d5df6';
        const rpTypePalette = ['#6d5df6', '#3b82f6', '#22c55e', '#fb923c', '#ef4444'];

        function rpFormatCompact(v) {
            const n = Number(v) || 0;
            if (Math.abs(n) >= 1e6) return Math.round(n / 1e6) + 'M';
            if (Math.abs(n) >= 1e3) return Math.round(n / 1e3) + 'K';
            return String(n);
        }
        function rpEscapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (ch) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[ch]));
        }
        function rpFormatDateLabel(value) {
            const d = new Date(value);
            if (Number.isNaN(d.getTime())) return String(value || '');
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        function rpMonthTick(value) {
            const d = new Date(value);
            if (Number.isNaN(d.getTime())) return String(value || '');
            return d.toLocaleDateString('en-GB', { month: 'short' });
        }
        function rpApplyDailyType(type) {
            if (!dailyChart) return;
            dailyChart.config.type = type;
            const ds = dailyChart.data.datasets[0];
            if (type === 'bar') {
                ds.fill = false;
                ds.tension = 0;
                ds.borderRadius = 6;
                ds.borderWidth = 0;
                ds.backgroundColor = rpTrendPurple;
                ds.borderColor = rpTrendPurple;
                ds.pointRadius = 0;
            } else {
                ds.fill = true;
                ds.tension = 0.35;
                ds.borderRadius = 0;
                ds.borderWidth = 2.5;
                ds.backgroundColor = 'rgba(109, 93, 246, 0.14)';
                ds.borderColor = rpTrendPurple;
                ds.pointRadius = 3;
                ds.pointHoverRadius = 6;
                ds.pointBackgroundColor = rpTrendPurple;
                ds.pointBorderColor = '#fff';
                ds.pointBorderWidth = 1;
            }
            dailyChart.update();
        }
        function rpBuildTypeSlices(rows) {
            const list = Array.isArray(rows) ? rows : [];
            const total = list.reduce((sum, row) => sum + (parseFloat(row.total_amount) || 0), 0);
            const slices = [];
            let other = 0;
            list.forEach((row, i) => {
                const amount = parseFloat(row.total_amount) || 0;
                const label = String(row.payment_type || '').trim() || 'Other';
                if (i < 3) slices.push({ label, amount });
                else other += amount;
            });
            if (other > 0) slices.push({ label: 'Others', amount: other });
            return slices.map((slice, i) => ({
                ...slice,
                color: rpTypePalette[i] || '#94a3b8',
                pct: total > 0 ? Math.round((slice.amount / total) * 10) / 10 : 0
            }));
        }
        function rpRenderDonutLegend(slices, legendId, pctId, labelId) {
            const legend = document.getElementById(legendId);
            const pctEl = document.getElementById(pctId);
            const labelEl = document.getElementById(labelId);
            if (legend) {
                legend.innerHTML = slices.map((slice) => {
                    const pct = String(slice.pct).replace(/\.0$/, '') + '%';
                    return `<li><span class="rp-donut-dot" style="background:${rpEscapeHtml(slice.color)}"></span><span class="rp-donut-name">${rpEscapeHtml(slice.label)}</span><span class="rp-donut-pct">${pct}</span></li>`;
                }).join('');
            }
            if (pctEl) pctEl.textContent = slices[0] ? String(slices[0].pct).replace(/\.0$/, '') + '%' : '0%';
            if (labelEl) labelEl.textContent = slices[0] ? slices[0].label : 'Payments';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const configColors = {
                blue: '#4ba3e3', // Steel Light Blue
                orange: '#FF902F',
                purple: '#811ba6',
                green: '#2ACF7F',
                red: '#f62d51',
                gray: isDark ? '#94a3b8' : '#6b7280',
                light: isDark ? '#334155' : '#eef2f7'
            };

            if (typeof Chart !== 'undefined') {
                Chart.defaults.font.family = "'Inter', sans-serif";
                Chart.defaults.color = configColors.gray;
                Chart.defaults.responsive = true;
                Chart.defaults.maintainAspectRatio = false;

                // 1. Daily Line Chart
                const dailyCtx = document.getElementById('dailyChart');
                if (dailyCtx) {
                    dailyChart = new Chart(dailyCtx, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode(array_column($daily_data, 'date')) ?>,
                            datasets: [{
                                label: 'TZS',
                                data: <?= json_encode(array_map('floatval', array_column($daily_data, 'approved_amount'))) ?>,
                                borderColor: rpTrendPurple,
                                backgroundColor: 'rgba(109, 93, 246, 0.14)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.35,
                                pointRadius: 3,
                                pointHoverRadius: 6,
                                pointBackgroundColor: rpTrendPurple,
                                pointBorderColor: '#fff',
                                pointBorderWidth: 1
                            }]
                        },
                        options: {
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: '#fff',
                                    titleColor: '#64748b',
                                    bodyColor: '#0f172a',
                                    borderColor: '#e2e8f0',
                                    borderWidth: 1,
                                    cornerRadius: 12,
                                    padding: 10,
                                    displayColors: true,
                                    boxPadding: 4,
                                    callbacks: {
                                        title: (items) => items[0] ? rpFormatDateLabel(items[0].label) : '',
                                        label: (ctx) => 'TZS ' + Number(ctx.parsed.y || 0).toLocaleString()
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    border: { display: false },
                                    ticks: {
                                        maxRotation: 0,
                                        autoSkip: true,
                                        maxTicksLimit: 8,
                                        callback: function(value) {
                                            return rpMonthTick(this.getLabelForValue(value));
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: configColors.light, borderDash: [4, 4] },
                                    ticks: { callback: (v) => rpFormatCompact(v) }
                                }
                            }
                        }
                    });
                    document.querySelectorAll('.rp-chart-toggle button').forEach((btn) => {
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.rp-chart-toggle button').forEach((b) => b.classList.remove('is-active'));
                            btn.classList.add('is-active');
                            rpApplyDailyType(btn.getAttribute('data-chart-type') || 'line');
                        });
                    });
                }

                // 2. Department Bar Chart
                const rpWhiteTooltip = {
                    backgroundColor: '#fff',
                    titleColor: '#0f172a',
                    bodyColor: '#64748b',
                    borderColor: '#e2e8f0',
                    borderWidth: 1,
                    cornerRadius: 12,
                    padding: 10,
                    displayColors: true,
                    boxPadding: 4
                };
                const deptCtx = document.getElementById('deptChart');
                if (deptCtx) {
                    deptChart = new Chart(deptCtx, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode(array_column($dept_data, 'department')) ?>,
                            datasets: [{
                                label: 'Spent',
                                data: <?= json_encode(array_map('floatval', array_column($dept_data, 'approved_amount'))) ?>,
                                backgroundColor: '#3b82f6',
                                borderRadius: 8,
                                barThickness: 22
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    ...rpWhiteTooltip,
                                    callbacks: {
                                        label: (ctx) => 'Spent: ' + Number(ctx.parsed.x || 0).toLocaleString()
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: configColors.light, borderDash: [4, 4] },
                                    ticks: { callback: (v) => rpFormatCompact(v) }
                                },
                                y: {
                                    border: { display: false },
                                    grid: { display: false }
                                }
                            }
                        }
                    });
                }

                // 3. Payment Type Doughnut
                const typeCtx = document.getElementById('typeChart');
                if (typeCtx) {
                    const typeSlices = <?= json_encode($typeSlices, JSON_UNESCAPED_UNICODE) ?>;
                    typeChart = new Chart(typeCtx, {
                        type: 'doughnut',
                        data: {
                            labels: typeSlices.map((s) => s.label),
                            datasets: [{
                                data: typeSlices.map((s) => s.amount),
                                backgroundColor: typeSlices.map((s) => s.color),
                                borderWidth: 0,
                                hoverOffset: 4
                            }]
                        },
                        options: {
                            cutout: '72%',
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: '#fff',
                                    titleColor: '#0f172a',
                                    bodyColor: '#64748b',
                                    borderColor: '#e2e8f0',
                                    borderWidth: 1,
                                    cornerRadius: 10,
                                    callbacks: {
                                        label: (ctx) => {
                                            const slices = (typeChart && typeChart.$rpSlices) || [];
                                            const slice = slices[ctx.dataIndex];
                                            const pct = slice ? slice.pct : 0;
                                            return ' ' + pct + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                    typeChart.$rpSlices = typeSlices;
                }

                // 4. Budget Utilization Bar
                const budgetCtx = document.getElementById('budgetChart');
                if (budgetCtx) {
                    budgetChart = new Chart(budgetCtx, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode(array_column($budget_data, 'budget_type')) ?>,
                            datasets: [{
                                label: 'Utilized',
                                data: <?= json_encode(array_map('floatval', array_column($budget_data, 'total_amount'))) ?>,
                                backgroundColor: rpTrendPurple,
                                borderRadius: 8
                            }]
                        },
                        options: {
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    ...rpWhiteTooltip,
                                    callbacks: {
                                        label: (ctx) => 'Utilized: ' + Number(ctx.parsed.y || 0).toLocaleString()
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    border: { display: false },
                                    grid: { display: false },
                                    ticks: { maxRotation: 40, minRotation: 0, autoSkip: true }
                                },
                                y: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: configColors.light, borderDash: [4, 4] },
                                    ticks: { callback: (v) => rpFormatCompact(v) }
                                }
                            }
                        }
                    });
                }

                const purposeCtx = document.getElementById('purposeChart');
                if (purposeCtx) {
                    const purposeSlices = <?= json_encode($purposeSlices, JSON_UNESCAPED_UNICODE) ?>;
                    purposeChart = new Chart(purposeCtx, {
                        type: 'doughnut',
                        data: {
                            labels: purposeSlices.map((s) => s.label),
                            datasets: [{
                                data: purposeSlices.map((s) => s.amount),
                                backgroundColor: purposeSlices.map((s) => s.color),
                                borderWidth: 0,
                                hoverOffset: 4
                            }]
                        },
                        options: {
                            cutout: '72%',
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: '#fff',
                                    titleColor: '#0f172a',
                                    bodyColor: '#64748b',
                                    borderColor: '#e2e8f0',
                                    borderWidth: 1,
                                    cornerRadius: 10,
                                    callbacks: {
                                        label: (ctx) => {
                                            const slices = (purposeChart && purposeChart.$rpSlices) || [];
                                            const slice = slices[ctx.dataIndex];
                                            const amount = Number(ctx.parsed || 0).toLocaleString();
                                            const pct = slice ? slice.pct : 0;
                                            return ' ' + amount + ' (' + pct + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                    purposeChart.$rpSlices = purposeSlices;
                }
            }

            // Real-time theme change updates for charts
            window.addEventListener('themeChanged', function(e) {
                const currentTheme = e.detail.theme;
                const isDarkTheme = currentTheme === 'dark';
                const newGray = isDarkTheme ? '#94a3b8' : '#6b7280';
                const newLight = isDarkTheme ? '#334155' : '#f3f4f6';
                const newDoughnutBorder = isDarkTheme ? '#1e293b' : '#fff';

                if (typeof Chart !== 'undefined') {
                    Chart.defaults.color = newGray;

                    if (dailyChart) {
                        dailyChart.options.scales.y.grid.color = newLight;
                        dailyChart.update('none');
                    }
                    if (deptChart) {
                        deptChart.options.scales.x.grid.color = newLight;
                        deptChart.update('none');
                    }
                    if (budgetChart) {
                        budgetChart.options.scales.y.grid.color = newLight;
                        budgetChart.update('none');
                    }
                    if (typeChart) {
                        typeChart.data.datasets[0].borderColor = newDoughnutBorder;
                        typeChart.update('none');
                    }
                }
            });
        });

        // Real-time Update Logic
        let refreshInterval;
        const autoRefreshSwitch = document.getElementById('autoRefreshSwitch');
        if (autoRefreshSwitch) {
            autoRefreshSwitch.addEventListener('change', function(e) {
                if (e.target.checked) {
                    refreshInterval = setInterval(refreshData, 30000); // 30s
                    Toast.fire({ icon: 'success', title: 'Auto-update enabled (30s)' });
                } else {
                    clearInterval(refreshInterval);
                    Toast.fire({ icon: 'info', title: 'Auto-update disabled' });
                }
            });
        }

        async function refreshData() {
            const btn = document.querySelector('.btn-refresh-ui');
            if(btn) btn.innerHTML = '<i class="fas fa-sync-alt fa-spin me-1"></i> refreshing...';

            try {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('ajax', '1');
                const response = await fetch(currentUrl.toString());
                const data = await response.json();

                // 1. Update Metrics
                document.getElementById('stat-tzs').innerText = 'TZS ' + Number(data.metrics.total_spent_tzs).toLocaleString();
                document.getElementById('stat-usd').innerText = 'USD ' + Number(data.metrics.total_spent_usd).toLocaleString();
                document.getElementById('stat-tx').innerText = Number(data.metrics.total_tx).toLocaleString();
                document.getElementById('stat-pending').innerText = Number(data.metrics.pending_count).toLocaleString();
                document.getElementById('stat-rejected').innerText = Number(data.metrics.rejected_count).toLocaleString();

                // 2. Update Charts
                if (dailyChart) {
                    dailyChart.data.labels = data.charts.daily.map(d => d.date);
                    dailyChart.data.datasets[0].data = data.charts.daily.map(d => parseFloat(d.approved_amount));
                    dailyChart.update('none');
                }
                if (deptChart) {
                    deptChart.data.labels = data.charts.dept.map(d => d.department);
                    deptChart.data.datasets[0].data = data.charts.dept.map(d => parseFloat(d.approved_amount));
                    deptChart.update('none');
                }
                if (typeChart) {
                    const typeSlices = rpBuildTypeSlices(data.charts.type);
                    typeChart.data.labels = typeSlices.map((s) => s.label);
                    typeChart.data.datasets[0].data = typeSlices.map((s) => s.amount);
                    typeChart.data.datasets[0].backgroundColor = typeSlices.map((s) => s.color);
                    typeChart.$rpSlices = typeSlices;
                    typeChart.update('none');
                    rpRenderDonutLegend(typeSlices, 'rpDonutLegend', 'rpDonutPct', 'rpDonutLabel');
                }
                if (budgetChart) {
                    budgetChart.data.labels = data.charts.budget.map(d => d.budget_type);
                    budgetChart.data.datasets[0].data = data.charts.budget.map(d => parseFloat(d.total_amount));
                    budgetChart.update('none');
                }
                if (purposeChart && Array.isArray(data.charts.purpose)) {
                    const purposeSlices = data.charts.purpose;
                    purposeChart.data.labels = purposeSlices.map((s) => s.label);
                    purposeChart.data.datasets[0].data = purposeSlices.map((s) => s.amount);
                    purposeChart.data.datasets[0].backgroundColor = purposeSlices.map((s) => s.color);
                    purposeChart.$rpSlices = purposeSlices;
                    purposeChart.update('none');
                    rpRenderDonutLegend(purposeSlices, 'rpPurposeLegend', 'rpPurposePct', 'rpPurposeLabel');
                }

                // 3. Update Tables
                const tables = document.querySelectorAll('.table-custom tbody');
                if (tables[0]) {
                    let phtml = '';
                    data.charts.payee.forEach((p, idx) => {
                        phtml += `<tr>
                            <td><span class="text-muted me-2 small">#${idx + 1}</span><span class="fw-600">${p.payee_name}</span></td>
                            <td class="text-end fw-bold text-dark">${Number(p.total_paid).toLocaleString()}</td>
                        </tr>`;
                    });
                    tables[0].innerHTML = phtml;
                }

            } catch (err) {
                console.error("Auto-update failed:", err);
            } finally {
                if(btn) btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Refresh';
            }
        }

        function exportCSV() {
            const form = document.getElementById('filterForm');
            if (!form) return;
            setReportExportBusy(true);
            Toast.fire({
                icon: 'info',
                title: 'Preparing CSV export...'
            });
            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();
            const exportUrl = `?export=csv&module=voucher&${params}`;
            setTimeout(() => {
                window.location.href = exportUrl;
                setTimeout(() => {
                    setReportExportBusy(false);
                    Toast.fire({
                        icon: 'success',
                        title: 'CSV export started'
                    });
                }, 1500);
            }, 800);
        }

        function downloadPDF() {
            const element = document.getElementById('report-content');
            if (!element) return;
            setReportExportBusy(true);
            Toast.fire({
                icon: 'info',
                title: 'Generating PDF report...'
            });
            const opt = {
                margin: 10,
                filename: 'Unified_Reports.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
            };
            html2pdf().set(opt).from(element).save().then(() => {
                setReportExportBusy(false);
                Toast.fire({
                    icon: 'success',
                    title: 'PDF downloaded successfully'
                });
            }).catch(err => {
                setReportExportBusy(false);
                console.error(err);
                Swal.fire('Error', 'Failed to generate PDF', 'error');
            });
        }
    </script>
</body>
</html>