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

// --- 2. Department Statistics ---
$dept_sql = "
    SELECT 
        COALESCE(u.department, pv.department_manager) as department,
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN pv.status = 'approved' THEN pv.total_amount ELSE 0 END) as approved_amount
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    WHERE $date_condition
    GROUP BY department
    ORDER BY approved_amount DESC
";
$dept_data = $pdo->query($dept_sql)->fetchAll(PDO::FETCH_ASSOC);

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

$pending_sql = "SELECT COUNT(*) as count FROM payment_vouchers WHERE $date_condition AND (status = 'pending' OR status = 'confirming')";
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
                'payee' => $payee_data
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
        body.dashboard { background-color: var(--primary-bg); font-family: 'Inter', sans-serif; color: #333; }
        .layout-main-wrapper { display: flex; width: 100%; min-height: 100vh; }
        .main-content { padding: 30px; flex-grow: 1; transition: all 0.3s; }
        
        /* Cards */
        .dash-card { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 25px; border: 1px solid #ededed; box-shadow: var(--card-shadow); transition: all 0.3s; position: relative; overflow: hidden; height: 100%; display: flex; flex-direction: column; justify-content: space-between; }
        .dash-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .dash-widget-icon { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; margin-bottom: 15px; font-size: 18px; }
        .bg-orange { background: linear-gradient(135deg, #FF902F 0%, #ffac63 100%); }
        .bg-purple { background: linear-gradient(135deg, #811ba6 0%, #ae4fd4 100%); }
        .bg-green { background: linear-gradient(135deg, #2ACF7F 0%, #5ee1a3 100%); }
        .bg-blue { background: linear-gradient(135deg, #405189 0%, #6e7db5 100%); }
        .bg-red { background: linear-gradient(135deg, #f62d51 0%, #ff6b87 100%); }
        
        .dash-widget-info h3 { font-size: 22px; font-weight: 700; margin-bottom: 5px; color: #111; }
        .dash-widget-info span { font-size: 13px; color: #777; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Filters Bar */
        .filter-card { background: #fff; border-radius: 10px; padding: 15px 25px; margin-bottom: 25px; border: 1px solid #ededed; box-shadow: var(--card-shadow); }
        .filter-title { font-size: 18px; font-weight: 700; color: #1f1f1f; margin-right: auto; }
        
        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-header h2 { font-size: 22px; font-weight: 700; margin: 0; color: #111; }
        
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
<body class="dashboard">
    <div class="layout-main-wrapper">
        <?php include_once __DIR__ . '/../sidebar.php'; ?>
        <div class="flex-grow-1">
            <?php require_once '../includes/header_admin.php'; ?>
            
            <main class="main-content">
                <div class="page-header">
                    <div>
                        <h2>Reports Dashboard</h2>
                        <p class="text-muted small mb-0">Analytics and spending trends for vouchers</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check form-switch me-2 mb-0">
                            <input class="form-check-input" type="checkbox" id="autoRefreshSwitch">
                            <label class="form-check-label small text-muted" for="autoRefreshSwitch">Auto-update</label>
                        </div>
                        <button onclick="refreshData()" class="btn btn-smart btn-outline-secondary btn-refresh-ui"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                        <a href="dashboard.php" class="btn btn-smart btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
                        <button onclick="downloadPDF()" class="btn btn-smart btn-blue"><i class="fas fa-file-pdf me-1"></i> PDF Export</button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" id="filterForm" class="row g-3 align-items-center">
                        <div class="col-auto me-auto">
                            <span class="filter-title">Advanced Filters</span>
                        </div>
                        <div class="col-auto">
                            <select name="range" class="form-select form-select-sm" onchange="toggleCustomDates(this.value)">
                                <option value="7days" <?= $filter_mode == '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="30days" <?= $filter_mode == '30days' ? 'selected' : '' ?>>Last 30 Days</option>
                                <option value="this_month" <?= $filter_mode == 'this_month' ? 'selected' : '' ?>>This Month</option>
                                <option value="last_month" <?= $filter_mode == 'last_month' ? 'selected' : '' ?>>Last Month</option>
                                <option value="all_time" <?= $filter_mode == 'all_time' ? 'selected' : '' ?>>All Time</option>
                                <option value="custom" <?= $filter_mode == 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>
                        <div class="col-auto" id="custom_dates" style="display:<?= $filter_mode == 'custom' ? 'flex' : 'none' ?>; gap:8px;">
                            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
                            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-smart btn-orange">Apply</button>
                        </div>
                        <div class="col-auto">
                            <button type="button" onclick="exportCSV()" class="btn btn-smart btn-outline-secondary btn-csv">
                                <i class="fas fa-file-csv me-1"></i> CSV Export
                            </button>
                        </div>
                    </form>
                </div>

                <div id="report-content">
                    <!-- Key Metrics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="dash-card">
                                <div class="dash-widget-icon bg-blue"><i class="fas fa-wallet"></i></div>
                                <div class="dash-widget-info">
                                    <h3 id="stat-tzs" style="font-size:16px;">TZS <?= number_format($total_spent_tzs) ?></h3>
                                    <h3 id="stat-usd" style="font-size:16px;">USD <?= number_format($total_spent_usd) ?></h3>
                                    <span>Total Approved Spent</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="dash-card">
                                <div class="dash-widget-icon bg-purple"><i class="fas fa-file-invoice"></i></div>
                                <div class="dash-widget-info">
                                    <h3 id="stat-tx"><?= number_format($total_tx) ?></h3>
                                    <span>Total Vouchers</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="dash-card">
                                <div class="dash-widget-icon bg-orange"><i class="fas fa-clock"></i></div>
                                <div class="dash-widget-info">
                                    <h3 id="stat-pending"><?= number_format($pending_count) ?></h3>
                                    <span>Pending Action</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="dash-card">
                                <div class="dash-widget-icon bg-red"><i class="fas fa-times-circle"></i></div>
                                <div class="dash-widget-info">
                                    <h3 id="stat-rejected"><?= number_format($rejected_count) ?></h3>
                                    <span>Rejected Items</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 1 -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-8">
                            <div class="card-table p-4">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="fw-bold m-0" style="font-size:16px;">Daily Spending Trend</h4>
                                    <span class="badge bg-light text-dark fw-500" style="font-size:11px;">Line Chart</span>
                                </div>
                                <div style="height:350px; position: relative;"><canvas id="dailyChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card-table p-4">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="fw-bold m-0" style="font-size:16px;">Payment Methods</h4>
                                    <span class="badge bg-light text-dark fw-500" style="font-size:11px;">Doughnut</span>
                                </div>
                                <div style="height:350px; position: relative;"><canvas id="typeChart"></canvas></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 2 -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-7">
                            <div class="card-table p-4">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="fw-bold m-0" style="font-size:16px;">Department Spending</h4>
                                    <span class="badge bg-light text-dark fw-500" style="font-size:11px;">Bar Chart</span>
                                </div>
                                <div style="height:350px; position: relative;"><canvas id="deptChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card-table">
                                <div class="card-table-header">
                                    <h4>Top Payees</h4>
                                    <span class="text-muted small">By Value</span>
                                </div>
                                <div class="table-responsive" style="max-height:350px;">
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
                        <div class="col-lg-6">
                            <div class="card-table p-4">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="fw-bold m-0" style="font-size:16px;">Budget Utilization</h4>
                                    <span class="badge bg-light text-dark fw-500" style="font-size:11px;">Bar Chart</span>
                                </div>
                                <div style="height:320px; position: relative;"><canvas id="budgetChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card-table">
                                <div class="card-table-header">
                                    <h4>Daily Statistics Table</h4>
                                    <span class="text-muted small">Historical Data</span>
                                </div>
                                <div class="table-responsive" style="max-height:320px;">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th class="text-end">Approved Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($daily_data as $d): ?>
                                                <tr>
                                                    <td><?= date('d M, Y', strtotime($d['date'])) ?></td>
                                                    <td class="text-end fw-bold"><?= number_format($d['approved_amount']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
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
        function toggleCustomDates(val) {
            document.getElementById('custom_dates').style.display = (val === 'custom') ? 'flex' : 'none';
        }

        // Initialize Charts Globally
        let dailyChart, deptChart, typeChart, budgetChart;

        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const configColors = {
                blue: '#4ba3e3', // Steel Light Blue
                orange: '#FF902F',
                purple: '#811ba6',
                green: '#2ACF7F',
                red: '#f62d51',
                gray: isDark ? '#94a3b8' : '#6b7280',
                light: isDark ? '#334155' : '#f3f4f6'
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
                                label: 'Approved (TZS)',
                                data: <?= json_encode(array_map('floatval', array_column($daily_data, 'approved_amount'))) ?>,
                                borderColor: configColors.blue,
                                backgroundColor: 'rgba(75, 163, 227, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 3,
                                pointHoverRadius: 6
                            }]
                        },
                        options: { 
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false } },
                                y: { grid: { color: configColors.light, borderDash: [5, 5] }, ticks: { callback: v => v.toLocaleString() } }
                            }
                        }
                    });
                }

                // 2. Department Bar Chart
                const deptCtx = document.getElementById('deptChart');
                if (deptCtx) {
                    deptChart = new Chart(deptCtx, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode(array_column($dept_data, 'department')) ?>,
                            datasets: [{
                                label: 'Spent',
                                data: <?= json_encode(array_map('floatval', array_column($dept_data, 'approved_amount'))) ?>,
                                backgroundColor: configColors.blue,
                                borderRadius: 6,
                                barThickness: 25
                            }]
                        },
                        options: { 
                            indexAxis: 'y',
                            plugins: { legend: { display: false } },
                            scales: { 
                                x: { grid: { color: configColors.light }, ticks: { callback: v => v.toLocaleString() } },
                                y: { grid: { display: false } }
                            }
                        }
                    });
                }

                // 3. Payment Type Doughnut
                const typeCtx = document.getElementById('typeChart');
                if (typeCtx) {
                    typeChart = new Chart(typeCtx, {
                        type: 'doughnut',
                        data: {
                            labels: <?= json_encode(array_column($type_data, 'payment_type')) ?>,
                            datasets: [{
                                data: <?= json_encode(array_map('floatval', array_column($type_data, 'total_amount'))) ?>,
                                backgroundColor: [configColors.blue, configColors.orange, configColors.purple, configColors.green, configColors.red],
                                borderWidth: 2,
                                borderColor: isDark ? '#1e293b' : '#fff'
                            }]
                        },
                        options: { 
                            cutout: '75%',
                            plugins: { 
                                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15, font: { size: 11 } } } 
                            } 
                        }
                    });
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
                                backgroundColor: configColors.purple,
                                borderRadius: 6
                            }]
                        },
                        options: { 
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false } },
                                y: { grid: { color: configColors.light, borderDash: [5, 5] } }
                            }
                        }
                    });
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
        document.getElementById('autoRefreshSwitch').addEventListener('change', function(e) {
            if (e.target.checked) {
                refreshInterval = setInterval(refreshData, 30000); // 30s
                Toast.fire({ icon: 'success', title: 'Auto-update enabled (30s)' });
            } else {
                clearInterval(refreshInterval);
                Toast.fire({ icon: 'info', title: 'Auto-update disabled' });
            }
        });

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
                    typeChart.data.labels = data.charts.type.map(d => d.payment_type);
                    typeChart.data.datasets[0].data = data.charts.type.map(d => parseFloat(d.total_amount));
                    typeChart.update('none');
                }
                if (budgetChart) {
                    budgetChart.data.labels = data.charts.budget.map(d => d.budget_type);
                    budgetChart.data.datasets[0].data = data.charts.budget.map(d => parseFloat(d.total_amount));
                    budgetChart.update('none');
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
                if (tables[1]) {
                    let hhtml = '';
                    data.charts.daily.slice().reverse().forEach(d => {
                        const dateStr = new Date(d.date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                        hhtml += `<tr>
                            <td>${dateStr}</td>
                            <td class="text-end fw-bold">${Number(d.approved_amount).toLocaleString()}</td>
                        </tr>`;
                    });
                    tables[1].innerHTML = hhtml;
                }

            } catch (err) {
                console.error("Auto-update failed:", err);
            } finally {
                if(btn) btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Refresh';
            }
        }

        function exportCSV() {
            const btn = document.querySelector('.btn-csv');
            if (!btn) return;
            const originalHtml = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Exporting...';
            btn.disabled = true;

            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });

            Toast.fire({
                icon: 'info',
                title: 'Preparing CSV export...'
            });

            // Construct URL
            const formData = new FormData(document.getElementById('filterForm'));
            const params = new URLSearchParams(formData).toString();
            const exportUrl = `?export=csv&module=voucher&${params}`;

            setTimeout(() => {
                window.location.href = exportUrl;
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    Toast.fire({
                        icon: 'success',
                        title: 'CSV export started'
                    });
                }, 1500);
            }, 800);
        }

        function downloadPDF() {
            const element = document.getElementById('report-content');
            const btn = document.querySelector('button[onclick="downloadPDF()"]');
            if (!btn) return;
            const originalHtml = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
            btn.disabled = true;

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
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                Toast.fire({
                    icon: 'success',
                    title: 'PDF downloaded successfully'
                });
            }).catch(err => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                console.error(err);
                Swal.fire('Error', 'Failed to generate PDF', 'error');
            });
        }
    </script>
</body>
</html>