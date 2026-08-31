<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

// Date Range Filter
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-3 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// Calculate previous period for comparison
$dateDiff = (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60);
$prevStartDate = date('Y-m-d', strtotime($startDate . " - " . ($dateDiff + 1) . " days"));
$prevEndDate = date('Y-m-d', strtotime($startDate . " - 1 day"));

// Schema Mapping (Robust support for Local vs Live)
$costCol = resolveExistingColumn('products', 'buying_price', ['cost_price']) ?? 'cost_price';
$imageCol = resolveExistingColumn('products', 'main_image', ['image']) ?? 'image';

// Helper function for growth %
function getGrowth($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return (($current - $previous) / $previous) * 100;
}

// --- 1. CORE KPIs (REVENUE, COST, ORDERS, CUSTOMERS) ---
$stmt = $pdo->prepare("
    SELECT 
        SUM(i.total_amount) as revenue,
        SUM(soi.quantity * p.$costCol) as cost,
        COUNT(DISTINCT i.id) as orders,
        COUNT(DISTINCT i.customer_id) as customers,
        SUM(soi.quantity) as items
    FROM invoices i
    LEFT JOIN sales_order_items soi ON i.order_id = soi.order_id
    LEFT JOIN products p ON soi.product_id = p.id
    WHERE i.status != 'cancelled' AND i.created_at BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$currentMetrics = $stmt->fetch();

$totalSales = $currentMetrics['revenue'] ?: 0;
$totalCost = $currentMetrics['cost'] ?: 0;
$totalProfit = $totalSales - $totalCost;
$totalOrders = $currentMetrics['orders'] ?: 0;
$activeCustomers = $currentMetrics['customers'] ?: 0;
$totalItems = $currentMetrics['items'] ?: 0;
$avgItemsPerOrder = $totalOrders > 0 ? $totalItems / $totalOrders : 0;
$profitMargin = $totalSales > 0 ? ($totalProfit / $totalSales) * 100 : 0;

// --- 2. PREVIOUS PERIOD KPI (FOR GROWTH TRIPLE-CHECK) ---
$stmt = $pdo->prepare("
    SELECT 
        SUM(i.total_amount) as revenue,
        SUM(soi.quantity * p.$costCol) as cost,
        COUNT(DISTINCT i.id) as orders
    FROM invoices i
    LEFT JOIN sales_order_items soi ON i.order_id = soi.order_id
    LEFT JOIN products p ON soi.product_id = p.id
    WHERE i.status != 'cancelled' AND i.created_at BETWEEN ? AND ?
");
$stmt->execute([$prevStartDate, $prevEndDate]);
$prevMetrics = $stmt->fetch();

$prevSales = $prevMetrics['revenue'] ?: 0;
$prevProfit = $prevSales - ($prevMetrics['cost'] ?: 0);
$prevOrders = $prevMetrics['orders'] ?: 0;

// Growth stats
$salesGrowth = getGrowth($totalSales, $prevSales);
$profitGrowth = getGrowth($totalProfit, $prevProfit);
$ordersGrowth = getGrowth($totalOrders, $prevOrders);

// --- 3. SALES TREND (DAILY) ---
$stmt = $pdo->prepare("SELECT DATE(created_at) as date, SUM(total_amount) as total, COUNT(*) as orders 
        FROM invoices 
        WHERE status != 'cancelled' AND created_at BETWEEN ? AND ? 
        GROUP BY DATE(created_at) 
        ORDER BY date ASC");
$stmt->execute([$startDate, $endDate]);
$dailySales = $stmt->fetchAll();

// --- 4. TOP PRODUCTS BY REVENUE & PROFIT ---
$stmt = $pdo->prepare("SELECT 
            p.id, p.name, p.product_code, p.$imageCol as image, 
            SUM(soi.quantity) as total_qty, 
            SUM(soi.line_total) as revenue,
            SUM(soi.line_total - (soi.quantity * p.$costCol)) as profit
        FROM sales_order_items soi 
        JOIN invoices i ON soi.order_id = i.order_id 
        JOIN products p ON soi.product_id = p.id 
        WHERE i.status != 'cancelled' AND i.created_at BETWEEN ? AND ? 
        GROUP BY p.id, p.name, p.product_code, p.$imageCol 
        ORDER BY revenue DESC 
        LIMIT 10");
$stmt->execute([$startDate, $endDate]);
$topProducts = $stmt->fetchAll();

// --- 5. TOTAL CUSTOMERS ---
$stmt = $pdo->query("SELECT COUNT(*) FROM customers");
$totalCustomersCount = $stmt->fetchColumn();

// --- 6. TOP CUSTOMERS ---
$stmt = $pdo->prepare("SELECT c.id, c.company_name, c.contact_person, COUNT(i.id) as orders, SUM(i.total_amount) as revenue 
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.status != 'cancelled' AND i.created_at BETWEEN ? AND ? 
        GROUP BY c.id, c.company_name, c.contact_person 
        ORDER BY revenue DESC 
        LIMIT 10");
$stmt->execute([$startDate, $endDate]);
$topCustomers = $stmt->fetchAll();

// --- 6. SALES BY STATUS (INVOICES) ---
$stmt = $pdo->prepare("SELECT status, COUNT(*) as count, SUM(total_amount) as total 
        FROM invoices 
        WHERE status != 'cancelled' AND created_at BETWEEN ? AND ? 
        GROUP BY status");
$stmt->execute([$startDate, $endDate]);
$salesByStatus = $stmt->fetchAll();

// --- 7. SALES LEADERBOARD (SALES REPS) ---
$stmt = $pdo->prepare("SELECT i.created_by as user_id, u.full_name, COUNT(i.id) as orders, SUM(i.total_amount) as revenue 
        FROM invoices i 
        LEFT JOIN users u ON i.created_by = u.id 
        WHERE i.status != 'cancelled' AND i.created_at BETWEEN ? AND ? 
        GROUP BY i.created_by, u.full_name 
        ORDER BY revenue DESC 
        LIMIT 10");
$stmt->execute([$startDate, $endDate]);
$salesLeaderboard = $stmt->fetchAll();

// --- 8. MONTHLY COMPARISON (LAST 6 MONTHS) ---
$stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(total_amount) as revenue, COUNT(*) as orders 
        FROM invoices 
        WHERE status != 'cancelled' AND created_at BETWEEN DATE_SUB(?, INTERVAL 6 MONTH) AND ? 
        GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
        ORDER BY month");
$stmt->execute([$endDate, $endDate]);
$monthlySales = $stmt->fetchAll();

// --- 9. HANDLE EXPORT ---
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Product Name', 'SKU', 'Units Sold', 'Revenue (TSH)', 'Profit (TSH)', 'Margin %']);
    foreach ($topProducts as $p) {
        $margin = $p['revenue'] > 0 ? ($p['profit'] / $p['revenue']) * 100 : 0;
        fputcsv($output, [$p['name'], $p['product_code'], $p['total_qty'], $p['revenue'], $p['profit'], round($margin, 2)]);
    }
    fclose($output);
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Analytics Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #10b981;
            --accent: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --pink: #ec4899;
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .header {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .filter-form {
            display: flex;
            gap: 12px;
            align-items: center;
            background: var(--bg-primary);
            padding: 8px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .filter-form input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .filter-form button {
            padding: 8px 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .metric-card {
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border-left: 6px solid var(--primary);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .metric-card.success { border-left-color: var(--secondary); }
        .metric-card.warning { border-left-color: var(--accent); }
        .metric-card.danger { border-left-color: var(--danger); }
        .metric-card.purple { border-left-color: var(--purple); }

        .metric-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .metric-subtitle {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 32px;
            margin-bottom: 40px;
        }

        .chart-card {
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .col-8 { grid-column: span 8; }
        .col-4 { grid-column: span 4; }
        .col-6 { grid-column: span 6; }
        .col-12 { grid-column: span 12; }
        
        .rep-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .rep-link:hover {
            text-decoration: underline;
        }

        .chart-card.full-width {
            grid-column: span 12;
        }

        .chart-header {
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .chart-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .leaderboard-table th {
            background: var(--bg-primary);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.875rem;
            border-bottom: 2px solid var(--border);
        }

        .leaderboard-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .leaderboard-table tr:hover {
            background: var(--bg-primary);
        }

        .rank-badge {
            display: inline-block;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            text-align: center;
            line-height: 32px;
            font-size: 0.875rem;
            font-weight: 700;
            margin-right: 12px;
        }

        .rank-1 { background: gold; color: #333; }
        .rank-2 { background: silver; color: #333; }
        .rank-3 { background: #cd7f32; color: white; }
        .rank-default { background: var(--border); color: var(--text-secondary); }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 24px;
            margin-top: 24px;
        }

        .product-card {
            background: var(--bg-primary);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            transition: transform 0.2s;
        }

        .product-card:hover {
            transform: translateY(-2px);
        }

        .product-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            margin: 0 auto 12px;
            object-fit: cover;
            background: var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }

        .product-name {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 0.875rem;
        }

        .product-stats {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .revenue-amount {
            color: var(--secondary);
            font-weight: 600;
        }

        .growth-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .growth-up { background: #dcfce7; color: #166534; }
        .growth-down { background: #fee2e2; color: #991b1b; }

        /* Collapsible Table Support */
        tbody.collapsible-tbody tr:nth-child(n+6) {
            display: none !important;
        }
        tbody.collapsible-tbody.expanded tr:nth-child(n+1) {
            display: table-row !important;
        }
        .show-more-btn {
            display: block;
            width: 100%;
            padding: 12px;
            text-align: center;
            background: #f8fafc;
            border: none;
            border-top: 1px solid var(--border);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
        }
        .show-more-btn:hover {
            background: #f1f5f9;
            color: #1d4ed8;
        }
        .show-more-btn i {
            transition: transform 0.3s;
        }
        .expanded-icon {
            transform: rotate(180deg);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            transform: translateX(-4px);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .export-btn {
            background: white;
            color: var(--text-primary);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .export-btn:hover { border-color: var(--primary); color: var(--primary); }

        @media (max-width: 1024px) {
            .col-8, .col-4, .col-6 { grid-column: span 12; }
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .container { padding: 40px 16px; }
            .header { flex-direction: column; align-items: stretch; }
            .filter-form { justify-content: center; flex-wrap: wrap; }
            .product-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                <div>
                    <h1><i class="fas fa-chart-line"></i> Sales Analytics Dashboard</h1>
                    <p style="color: var(--text-secondary); margin-top: 4px;">Profitability, trends, and performance tracking</p>
                </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <a href="?action=export&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="export-btn">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <form class="filter-form" method="GET">
                    <span style="font-size: 14px; font-weight: 500;">Period:</span>
                    <input type="date" name="start_date" value="<?= $startDate ?>">
                    <span style="color: var(--text-secondary);">to</span>
                    <input type="date" name="end_date" value="<?= $endDate ?>">
                    <button type="submit">Update</button>
                </form>
            </div>
        </div>
    </div>

        <!-- Key Metrics -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Revenue</div>
                <div class="metric-value">
                    <?= number_format($totalSales, 0) ?>
                    <span class="growth-badge <?= $salesGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?= $salesGrowth >= 0 ? 'up' : 'down' ?>"></i> <?= round(abs($salesGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">TSH vs Prev Period</div>
            </div>
            <div class="metric-card success">
                <div class="metric-label">Gross Profit</div>
                <div class="metric-value">
                    <?= number_format($totalProfit, 0) ?>
                    <span class="growth-badge <?= $profitGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?= $profitGrowth >= 0 ? 'up' : 'down' ?>"></i> <?= round(abs($profitGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">TSH (<?= round($profitMargin, 1) ?>% Margin)</div>
            </div>
            <div class="metric-card warning">
                <div class="metric-label">Total Orders</div>
                <div class="metric-value">
                    <?= number_format($totalOrders) ?>
                    <span class="growth-badge <?= $ordersGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?= $ordersGrowth >= 0 ? 'up' : 'down' ?>"></i> <?= round(abs($ordersGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">Efficiency: <?= round($avgItemsPerOrder, 1) ?> items/order</div>
            </div>
            <div class="metric-card purple">
                <div class="metric-label">Active Customers</div>
                <div class="metric-value"><?= number_format($activeCustomers) ?></div>
                <div class="metric-subtitle">Unique buyers in period</div>
            </div>
        </div>

        <!-- DASHBOARD GRID -->
        <div class="charts-grid">
            <!-- Sales Trend Chart -->
            <div class="chart-card col-8">
                <div class="chart-header">
                    <div class="chart-title">Sales Trend</div>
                    <div class="chart-subtitle">Daily revenue and order volume</div>
                </div>
                <div class="chart-container">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>

            <!-- Sales by Status -->
            <div class="chart-card col-4">
                <div class="chart-header">
                    <div class="chart-title">Sales by Status</div>
                    <div class="chart-subtitle">Order distribution</div>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Top Products -->
            <div class="chart-card col-12">
                <div class="chart-header">
                    <div class="chart-title">Top Selling Products</div>
                    <div class="chart-subtitle">Yielding highest revenue</div>
                </div>
                <div class="product-grid">
                    <?php foreach ($topProducts as $index => $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php if ($product['image']): ?>
                                    <img src="/stock/uploads/products/<?= $product['id'] ?>/medium/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">
                                <?php else: ?>
                                    <i class="fas fa-box fa-2x"></i>
                                <?php endif; ?>
                            </div>
                            <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                            <div class="product-stats">
                                <div>Qty: <?= number_format($product['total_qty']) ?></div>
                                <div class="revenue-amount">TSH <?= number_format($product['revenue'], 0) ?></div>
                                <div style="font-size: 11px; color: var(--text-secondary);">
                                    Profit: TSH <?= number_format($product['profit'], 0) ?> 
                                    (<?= $product['revenue'] > 0 ? round(($product['profit']/$product['revenue'])*100, 1) : 0 ?>%)
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($topProducts)): ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-secondary);">
                            <i class="fas fa-box fa-3x" style="margin-bottom: 16px;"></i>
                            <div>No product sales data available</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

                <!-- LEFT COLUMN: Trends and Metrics -->
            <div class="col-6" style="display: flex; flex-direction: column; gap: 32px;">
                <!-- Monthly Comparison -->
                <div class="chart-card" style="height: 380px;">
                    <div class="chart-header">
                        <div class="chart-title">Monthly Comparison</div>
                        <div class="chart-subtitle">Revenue trends over months</div>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <!-- Total Customers Metric -->
                <div class="chart-card" style="justify-content: center; height: auto; min-height: 120px;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 50px; height: 50px; background: #fef3c7; color: #d97706; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 500;">Total Lifetime Customers</div>
                            <div style="font-size: 1.75rem; font-weight: 700; color: var(--text-primary);"><?= number_format($totalCustomersCount) ?></div>
                            <a href="all_customers.php" style="font-size: 0.75rem; color: var(--primary); text-decoration: none;">View full list &rarr;</a>
                        </div>
                    </div>
                </div>

                <!-- Top Customers (Moved Here) -->
                <div class="chart-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h3 class="card-title">Top Customers</h3>
                            <p class="card-subtitle">Highest revenue clients</p>
                        </div>
                        <a href="all_customers.php" class="btn-action" style="font-size: 0.75rem; padding: 6px 12px;">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Customer</th>
                                    <th style="text-align: center;">Orders</th>
                                    <th style="text-align: right;">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="collapsible-tbody">
                                <?php foreach ($topCustomers as $index => $customer): ?>
                                    <tr>
                                        <td>
                                            <span class="rank-badge <?= $index < 3 ? 'rank-' . ($index + 1) : 'rank-default' ?>">
                                                <?= $index + 1 ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold">
                                                <a href="customer_details.php?id=<?= $customer['id'] ?>" class="doc-link">
                                                    <?= htmlspecialchars($customer['company_name']) ?>
                                                </a>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                                <?= htmlspecialchars($customer['contact_person'] ?: 'N/A') ?>
                                            </div>
                                        </td>
                                        <td style="text-align: center;"><?= number_format($customer['orders']) ?></td>
                                        <td class="revenue-amount">TSH <?= number_format($customer['revenue'], 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topCustomers)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                            No customer data available
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($topCustomers) > 5): ?>
                        <button class="show-more-btn" onclick="toggleTableRows(this)">
                            Show All Ranking <i class="fas fa-chevron-down ms-1"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN: Sales Leaderboard -->
            <div class="chart-card col-6">
                <div class="chart-header">
                    <div class="chart-title">Sales Leaderboard</div>
                    <div class="chart-subtitle">Top performing sales representatives</div>
                </div>
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Name</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="collapsible-tbody">
                        <?php foreach ($salesLeaderboard as $index => $rep): ?>
                            <tr>
                                <td>
                                    <span class="rank-badge <?= $index < 3 ? 'rank-' . ($index + 1) : 'rank-default' ?>">
                                        <?= $index + 1 ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="sales_rep_details.php?id=<?= $rep['user_id'] ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="rep-link">
                                        <?= htmlspecialchars($rep['full_name'] ?: 'Unknown') ?>
                                    </a>
                                </td>
                                <td><?= number_format($rep['orders']) ?></td>
                                <td class="revenue-amount"><?= number_format($rep['revenue'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($salesLeaderboard)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                    No sales data available
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if (count($salesLeaderboard) > 5): ?>
                    <button class="show-more-btn" onclick="toggleTableRows(this)">
                        Show All Ranking <i class="fas fa-chevron-down ms-1"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Chart.js Configuration
        Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, sans-serif';
        Chart.defaults.color = '#64748b';

        // Table Toggle Logic
        function toggleTableRows(btn) {
            const card = btn.closest('.chart-card');
            const tbody = card.querySelector('.collapsible-tbody');
            const icon = btn.querySelector('i');
            
            tbody.classList.toggle('expanded');
            
            if (tbody.classList.contains('expanded')) {
                btn.innerHTML = 'Show Less <i class="fas fa-chevron-up ms-1"></i>';
            } else {
                btn.innerHTML = 'Show All Ranking <i class="fas fa-chevron-down ms-1"></i>';
            }
        }

        // Sales Trend Chart
        const salesTrendCtx = document.getElementById('salesTrendChart').getContext('2d');
        new Chart(salesTrendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('M j', strtotime($d['date'])); }, $dailySales)) ?>,
                datasets: [{
                    label: 'Revenue (TSH)',
                    data: <?= json_encode(array_map(function($d) { return $d['total']; }, $dailySales)) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Orders',
                    data: <?= json_encode(array_map(function($d) { return $d['orders']; }, $dailySales)) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            callback: function(value) {
                                return 'TSH ' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: function(value) {
                                return value + ' orders';
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });

        // Sales by Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(function($s) { return ucfirst($s['status']); }, $salesByStatus)) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(function($s) { return $s['total']; }, $salesByStatus)) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 20, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return 'TSH ' + value.toLocaleString() + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Monthly Comparison Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(function($m) { 
                    $date = DateTime::createFromFormat('Y-m', $m['month']);
                    return $date ? $date->format('M Y') : $m['month']; 
                }, $monthlySales)) ?>,
                datasets: [{
                    label: 'Revenue (TSH)',
                    data: <?= json_encode(array_map(function($m) { return $m['revenue']; }, $monthlySales)) ?>,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            callback: function(value) {
                                return 'TSH ' + (value / 1000).toFixed(0) + 'K';
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    </script>
</body>
</html>
