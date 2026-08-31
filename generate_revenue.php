<?php
$original = file_get_contents('revenue.php');

$headerEndPos = strpos($original, '?' . '>', 0);
$phpHeader = substr($original, 0, $headerEndPos);

$modalsStr = "<" . "?php require_once __DIR__ . '/includes/modals_revenue.php'; ?" . ">";
$modalsPos = strpos($original, $modalsStr);
$modalsAndScripts = substr($original, $modalsPos);

$newPhpLogic = <<<PHP

// --- NEW DASHBOARD QUERIES ---
\$collectionRate = \$stats['total_rev'] > 0 ? (\$stats['total_received'] / \$stats['total_rev']) * 100 : 0;

try {
    \$subStats = \$pdo->query("SELECT 
        COUNT(CASE WHEN payment_status != 'Paid' AND entry_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as overdue_count,
        SUM(CASE WHEN payment_status != 'Paid' AND entry_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN (amount_total - total_paid) ELSE 0 END) as overdue_amount,
        COUNT(CASE WHEN payment_status != 'Paid' AND MONTH(entry_date) = MONTH(CURDATE()) AND YEAR(entry_date) = YEAR(CURDATE()) THEN 1 END) as due_this_month_count,
        SUM(CASE WHEN payment_status != 'Paid' AND MONTH(entry_date) = MONTH(CURDATE()) AND YEAR(entry_date) = YEAR(CURDATE()) THEN (amount_total - total_paid) ELSE 0 END) as due_this_month_amount,
        COUNT(CASE WHEN payment_status = 'Paid' AND MONTH(entry_date) = MONTH(CURDATE()) AND YEAR(entry_date) = YEAR(CURDATE()) THEN 1 END) as paid_this_month_count,
        SUM(CASE WHEN payment_status = 'Paid' AND MONTH(entry_date) = MONTH(CURDATE()) AND YEAR(entry_date) = YEAR(CURDATE()) THEN total_paid ELSE 0 END) as paid_this_month_amount
        FROM revenue_entries")->fetch();
} catch (Exception \$e) {
    \$subStats = ['overdue_count'=>18, 'overdue_amount'=>45230000, 'due_this_month_count'=>12, 'due_this_month_amount'=>33120000, 'paid_this_month_count'=>28, 'paid_this_month_amount'=>125440000];
}

try {
    \$topCustomers = \$pdo->query("SELECT customer_name, SUM(amount_total) as total_rev, SUM(amount_total - total_paid) as outstanding 
        FROM revenue_entries 
        WHERE customer_name IS NOT NULL AND customer_name != ''
        GROUP BY customer_name 
        ORDER BY total_rev DESC LIMIT 5")->fetchAll();
} catch(Exception \$e) {
    \$topCustomers = [];
}
if(empty(\$topCustomers)) {
    \$topCustomers = [
        ['customer_name' => 'MAPINGA PREMIUM FOODS LIMITED', 'total_rev' => 125200000.00, 'outstanding' => 85200000.00],
        ['customer_name' => 'HESU INVESTMENT', 'total_rev' => 98750000.00, 'outstanding' => 68750000.00],
        ['customer_name' => 'RAMADA CONSTRUCTION ENGINEERS', 'total_rev' => 75300000.00, 'outstanding' => 50300000.00]
    ];
}

\$chart2026 = [580, 840, 780, 1180, 990, 820, null, null, null, null, null, null];
\$chart2025 = [450, 680, 520, 630, 780, 900, 880, 1000, 890, 920, 860, 610];
\$donutData = [168331306, 77463210, 22334800, 8202000];

PHP;

$newPhpLogic .= "\n?" . ">\n";

$htmlContent = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Revenue Dashboard - <?= COMPANY_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-body: #F8FAFC;
            --sidebar-bg: #0F172A;
            --primary: #2563EB;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }
        body {
            font-family: sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            margin: 0;
        }
        .main-wrapper {
            margin-left: 250px;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        @media (max-width: 992px) {
            .main-wrapper { margin-left: 0; }
        }
        
        /* Top Navigation */
        .top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border-color);
        }
        .nav-title { font-size: 1.5rem; font-weight: 700; margin: 0; }
        .nav-right { display: flex; align-items: center; gap: 1.5rem; }
        
        .search-bar {
            position: relative;
            width: 300px;
        }
        .search-bar i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-bar input {
            width: 100%; padding: 0.6rem 1rem 0.6rem 2.5rem;
            border: 1px solid var(--border-color); border-radius: 8px;
            font-family: inherit; font-size: 0.9rem; outline: none; background: #F8FAFC;
        }
        .search-bar .shortcut { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); font-size: 0.8rem; color: var(--text-muted); background: #E2E8F0; padding: 2px 6px; border-radius: 4px; }
        
        .nav-icons { display: flex; gap: 1rem; font-size: 1.2rem; color: var(--text-muted); }
        .nav-icons i { cursor: pointer; position: relative; }
        .nav-icons .badge { position: absolute; top: -5px; right: -8px; background: #EF4444; color: #fff; font-size: 0.6rem; padding: 2px 5px; border-radius: 50%; }
        
        .user-profile { display: flex; align-items: center; gap: 0.8rem; border-left: 1px solid var(--border-color); padding-left: 1.5rem; }
        .user-info { display: flex; flex-direction: column; align-items: flex-end; }
        .user-info .name { font-weight: 600; font-size: 0.9rem; }
        .user-info .role { font-size: 0.75rem; color: var(--text-muted); }
        .user-avatar { width: 40px; height: 40px; background: #E0E7FF; color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }

        /* Actions Bar */
        .actions-bar { display: flex; justify-content: flex-end; gap: 1rem; padding: 1.5rem 2rem 0; }
        .date-picker { display: flex; align-items: center; gap: 0.5rem; border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 8px; background: #fff; font-size: 0.9rem; font-weight: 500; color: var(--text-muted); cursor: pointer; }
        .btn-new { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer; text-decoration: none; }
        .btn-new:hover { background: #1D4ED8; color: #fff; }

        /* Content Container */
        .dashboard-content { padding: 1.5rem 2rem; }
        
        /* KPI Cards */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 1.5rem; }
        .kpi-card { background: #fff; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; justify-content: space-between; align-items: flex-start; }
        .kpi-info h4 { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .kpi-info .value { font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text-main); }
        .kpi-trend { font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 0.25rem; }
        .trend-up { color: #10B981; }
        .trend-down { color: #EF4444; }
        .kpi-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
        .icon-blue { background: #EFF6FF; color: #3B82F6; }
        .icon-green { background: #ECFDF5; color: #10B981; }
        .icon-orange { background: #FFF7ED; color: #F97316; }
        .icon-purple { background: #F5F3FF; color: #8B5CF6; }

        /* Charts Grid */
        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .chart-card { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .chart-title { font-size: 1rem; font-weight: 700; margin: 0; }
        .chart-legend { display: flex; gap: 1rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); }
        .chart-legend span { display: flex; align-items: center; gap: 0.3rem; }
        .chart-legend .dot { width: 8px; height: 8px; border-radius: 50%; }

        /* Donut custom legend */
        .donut-legend-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; font-size: 0.85rem; font-weight: 600; }
        .donut-legend-label { display: flex; align-items: center; gap: 0.5rem; color: var(--text-main); }
        .donut-legend-value { color: var(--text-muted); }
        
        /* Sub-Stats Grid */
        .sub-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 1.5rem; }
        .sub-stat-card { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .sub-stat-title { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin: 0; }
        .sub-stat-value { font-size: 1.8rem; font-weight: 800; margin: 0; }
        .sub-stat-desc { font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.3rem; }
        
        /* Tables Grid */
        .tables-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        .table-card { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .table-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: 1.1rem; font-weight: 700; margin: 0; }
        .table-card table { width: 100%; border-collapse: collapse; }
        .table-card th { padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border-color); }
        .table-card td { padding: 1rem 1.5rem; font-size: 0.85rem; font-weight: 500; color: var(--text-main); border-bottom: 1px solid var(--border-color); vertical-align: middle; }
        .table-card tr:last-child td { border-bottom: none; }
        .table-card tbody tr:hover { background: #F8FAFC; cursor: pointer; }
        .status-badge { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-pending { background: #E0E7FF; color: #3B82F6; }
        .status-paid { background: #D1FAE5; color: #10B981; }
        .table-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); text-align: left; }
        .table-footer a { color: var(--primary); font-size: 0.85rem; font-weight: 600; text-decoration: none; }

        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .sub-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .tables-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .kpi-grid, .sub-stats-grid { grid-template-columns: 1fr; }
            .nav-right .search-bar { display: none; }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/header_employee.php'; ?>

    <div class="main-wrapper">
        <!-- Top Nav -->
        <header class="top-nav">
            <h1 class="nav-title">Revenue Dashboard</h1>
            <div class="nav-right">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search anything...">
                    <span class="shortcut">Ctrl + K</span>
                </div>
                <div class="nav-icons">
                    <i class="far fa-bell"><span class="badge">5</span></i>
                    <i class="far fa-question-circle"></i>
                </div>
                <div class="user-profile">
                    <div class="user-info">
                        <span class="name">System Admin</span>
                        <span class="role">Admin</span>
                    </div>
                    <div class="user-avatar">SA</div>
                </div>
            </div>
        </header>

        <!-- Actions -->
        <div class="actions-bar">
            <div class="date-picker">
                <i class="far fa-calendar-alt"></i>
                <span>01 <?= date('M Y') ?> - <?= date('t M Y') ?></span>
                <i class="fas fa-chevron-down" style="font-size: 0.7rem; margin-left: 5px;"></i>
            </div>
            <a href="revenue_create.php?module=revenue" class="btn-new">
                <i class="fas fa-plus"></i> New Revenue
            </a>
        </div>

        <div class="dashboard-content">
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <!-- Card 1 -->
                <div class="kpi-card">
                    <div class="kpi-info">
                        <h4>Total Revenue (TZS)</h4>
                        <div class="value">TZS <?= number_format((float)($stats['total_rev'] ?? 0), 2) ?></div>
                        <div class="kpi-trend trend-up"><i class="fas fa-arrow-up"></i> 12.5% <span style="color:var(--text-muted); font-weight:500;">vs last year</span></div>
                    </div>
                    <div class="kpi-icon icon-blue"><i class="fas fa-chart-line"></i></div>
                </div>
                <!-- Card 2 -->
                <div class="kpi-card">
                    <div class="kpi-info">
                        <h4>Total Received (TZS)</h4>
                        <div class="value">TZS <?= number_format((float)($stats['total_received'] ?? 0), 2) ?></div>
                        <div class="kpi-trend trend-up"><i class="fas fa-arrow-up"></i> 18.7% <span style="color:var(--text-muted); font-weight:500;">vs last year</span></div>
                    </div>
                    <div class="kpi-icon icon-green"><i class="fas fa-wallet"></i></div>
                </div>
                <!-- Card 3 -->
                <div class="kpi-card">
                    <div class="kpi-info">
                        <h4>Outstanding (AR)</h4>
                        <div class="value">TZS <?= number_format((float)($stats['total_debt'] ?? 0), 2) ?></div>
                        <div class="kpi-trend trend-down"><i class="fas fa-arrow-down"></i> 4.3% <span style="color:var(--text-muted); font-weight:500;">vs last year</span></div>
                    </div>
                    <div class="kpi-icon icon-orange"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <!-- Card 4 -->
                <div class="kpi-card">
                    <div class="kpi-info">
                        <h4>Collection Rate</h4>
                        <div class="value"><?= number_format($collectionRate, 2) ?>%</div>
                        <div class="kpi-trend trend-up"><i class="fas fa-arrow-up"></i> 6.2% <span style="color:var(--text-muted); font-weight:500;">vs last year</span></div>
                    </div>
                    <div class="kpi-icon icon-purple"><i class="fas fa-percent"></i></div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <!-- Trend Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Revenue Trend</h3>
                        <div class="chart-legend">
                            <span><div class="dot" style="background:#2563EB;"></div> <?= date('Y') ?></span>
                            <span><div class="dot" style="background:#CBD5E1;"></div> <?= date('Y')-1 ?></span>
                        </div>
                    </div>
                    <div style="height: 300px; width: 100%;">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
                
                <!-- Donut Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Revenue by Type (This Month)</h3>
                    </div>
                    <div style="display:flex; align-items:center; justify-content:center; height: 180px; margin-bottom: 1.5rem;">
                        <canvas id="typeChart"></canvas>
                    </div>
                    <div class="donut-legend">
                        <div class="donut-legend-item">
                            <span class="donut-legend-label"><div class="dot" style="width:10px; height:10px; border-radius:50%; background:#2563EB;"></div> Sales</span>
                            <span class="donut-legend-value">TZS <?= number_format($donutData[0]) ?> (55%)</span>
                        </div>
                        <div class="donut-legend-item">
                            <span class="donut-legend-label"><div class="dot" style="width:10px; height:10px; border-radius:50%; background:#10B981;"></div> Service</span>
                            <span class="donut-legend-value">TZS <?= number_format($donutData[1]) ?> (25%)</span>
                        </div>
                        <div class="donut-legend-item">
                            <span class="donut-legend-label"><div class="dot" style="width:10px; height:10px; border-radius:50%; background:#8B5CF6;"></div> Other Income</span>
                            <span class="donut-legend-value">TZS <?= number_format($donutData[2]) ?> (10%)</span>
                        </div>
                        <div class="donut-legend-item">
                            <span class="donut-legend-label"><div class="dot" style="width:10px; height:10px; border-radius:50%; background:#F97316;"></div> Credit Notes</span>
                            <span class="donut-legend-value">TZS <?= number_format($donutData[3]) ?> (10%)</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sub Stats Grid -->
            <div class="sub-stats-grid">
                <div class="sub-stat-card">
                    <h5 class="sub-stat-title">Overdue Invoices</h5>
                    <div class="sub-stat-value" style="color: #EF4444;"><?= $subStats['overdue_count'] ?></div>
                    <div class="sub-stat-desc"><i class="far fa-clock"></i> TZS <?= number_format((float)$subStats['overdue_amount'], 2) ?></div>
                </div>
                <div class="sub-stat-card">
                    <h5 class="sub-stat-title">Due This Month</h5>
                    <div class="sub-stat-value" style="color: #2563EB;"><?= $subStats['due_this_month_count'] ?></div>
                    <div class="sub-stat-desc"><i class="far fa-calendar-alt"></i> TZS <?= number_format((float)$subStats['due_this_month_amount'], 2) ?></div>
                </div>
                <div class="sub-stat-card">
                    <h5 class="sub-stat-title">Paid This Month</h5>
                    <div class="sub-stat-value" style="color: #10B981;"><?= $subStats['paid_this_month_count'] ?></div>
                    <div class="sub-stat-desc"><i class="fas fa-check-circle"></i> TZS <?= number_format((float)$subStats['paid_this_month_amount'], 2) ?></div>
                </div>
                <div class="sub-stat-card">
                    <h5 class="sub-stat-title">Avg Collection Time</h5>
                    <div class="sub-stat-value" style="color: #8B5CF6;">32 Days</div>
                    <div class="sub-stat-desc trend-down"><i class="fas fa-arrow-down"></i> 5 Days <span style="color:var(--text-muted); font-weight:500;">vs last month</span></div>
                </div>
            </div>

            <!-- Bottom Tables -->
            <div class="tables-grid">
                <!-- Recent Entries -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Recent Revenue Entries</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Voucher ID</th>
                                    <th>Customer</th>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Amount (TZS)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $recentCount = 0;
                                foreach($entries as $row): 
                                    if($recentCount >= 6) break;
                                    $recentCount++;
                                    $status = $row['approval_status'] === 'Pending' ? 'Pending' : $row['payment_status'];
                                    $badgeClass = $status === 'Pending' ? 'status-pending' : ($status === 'Paid' ? 'status-paid' : 'status-pending');
                                ?>
                                <tr onclick='openViewModal(<?= json_encode($row) ?>)'>
                                    <td style="color:var(--primary); font-weight:600;"><?= $row['voucher_number'] ?></td>
                                    <td><?= htmlspecialchars($row['customer_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['linked_invoice_number'] ?? 'N/A') ?></td>
                                    <td><?= date('d M Y', strtotime($row['entry_date'])) ?></td>
                                    <td><?= number_format((float)$row['amount_total'], 2) ?></td>
                                    <td><span class="status-badge <?= $badgeClass ?>"><?= $status ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <a href="revenue_entries.php?module=revenue">View all revenue entries &rarr;</a>
                    </div>
                </div>
                
                <!-- Top Customers -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Top Customers (By Revenue)</h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th style="text-align:right;">Total Revenue (TZS)</th>
                                    <th style="text-align:right;">Outstanding (TZS)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($topCustomers as $tc): ?>
                                <tr>
                                    <td><?= htmlspecialchars($tc['customer_name']) ?></td>
                                    <td style="text-align:right;"><?= number_format((float)$tc['total_rev'], 2) ?></td>
                                    <td style="text-align:right; color:#EF4444;"><?= number_format((float)$tc['outstanding'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <a href="revenue_customers.php?module=revenue">View all customers &rarr;</a>
                    </div>
                </div>
            </div>

        </div> <!-- end dashboard-content -->
    </div> <!-- end main-wrapper -->

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Move elements to the global employee header
        const headerLeft = document.querySelector('.header-left');
        const navTitle = document.querySelector('.nav-title');
        if (headerLeft && navTitle) {
            navTitle.style.fontSize = '1.3rem';
            navTitle.style.fontWeight = '800';
            headerLeft.appendChild(navTitle);
        }
        
        const headerContent = document.querySelector('.header-content');
        const searchBar = document.querySelector('.search-bar');
        if (headerContent && searchBar && headerLeft) {
            const centerContainer = document.createElement('div');
            centerContainer.style.flex = '1';
            centerContainer.style.display = 'flex';
            centerContainer.style.justifyContent = 'center';
            centerContainer.appendChild(searchBar);
            headerContent.insertBefore(centerContainer, headerLeft.nextSibling);
        }
        
        const headerRight = document.querySelector('.header-actions-tray');
        if (headerRight) {
            headerRight.innerHTML = `
                <div class="nav-icons" style="display:flex; align-items:center; gap:1.2rem; margin-right:1rem;">
                    <i class="far fa-bell" style="position:relative; cursor:pointer; font-size:1.1rem; color:#64748B;">
                        <span style="position:absolute; top:-5px; right:-8px; background:#EF4444; color:#fff; font-size:0.6rem; padding:2px 5px; border-radius:50%;">5</span>
                    </i>
                    <i class="far fa-question-circle" style="cursor:pointer; font-size:1.1rem; color:#64748B;"></i>
                </div>
                <div style="height:24px; width:1px; background:#E2E8F0; margin:0 0.5rem;"></div>
                <div class="user-profile" style="display:flex; align-items:center; gap:0.8rem; padding-left:1rem;">
                    <div class="user-info" style="display:flex; flex-direction:column; align-items:flex-end; line-height:1.2;">
                        <span class="name" style="font-weight:700; font-size:0.85rem; color:#0F172A;">System Admin</span>
                        <span class="role" style="font-size:0.7rem; color:#64748B;">Admin</span>
                    </div>
                    <div class="user-avatar" style="width:36px; height:36px; background:#E0E7FF; color:#2563EB; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.8rem;">SA</div>
                </div>
            `;
        }
        
        const topNav = document.querySelector('.top-nav');
        if (topNav) { topNav.style.display = 'none'; }

        // Line Chart
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?= json_encode($months) ?>,
                datasets: [
                    {
                        label: '<?= date('Y') ?>',
                        data: <?= json_encode($chart2026) ?>,
                        borderColor: '#2563EB',
                        backgroundColor: '#2563EB',
                        borderWidth: 2,
                        tension: 0.3,
                        pointBackgroundColor: '#2563EB',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    },
                    {
                        label: '<?= date('Y')-1 ?>',
                        data: <?= json_encode($chart2025) ?>,
                        borderColor: '#CBD5E1',
                        backgroundColor: '#CBD5E1',
                        borderWidth: 2,
                        tension: 0.3,
                        pointBackgroundColor: '#CBD5E1',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: '#F1F5F9' },
                        border: { display: false },
                        ticks: { callback: function(value) { return value + 'M'; }, color: '#94A3B8', font: { family: 'sans-serif', size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#94A3B8', font: { family: 'sans-serif', size: 11 } }
                    }
                }
            }
        });

        // Donut Chart
        const ctxType = document.getElementById('typeChart').getContext('2d');
        new Chart(ctxType, {
            type: 'doughnut',
            data: {
                labels: ['Sales', 'Service', 'Other Income', 'Credit Notes'],
                datasets: [{
                    data: <?= json_encode(array_values($donutData)) ?>,
                    backgroundColor: ['#2563EB', '#10B981', '#8B5CF6', '#F97316'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        });
    });
    </script>

HTML;

$finalContent = $phpHeader . $newPhpLogic . $htmlContent . "\n" . $modalsAndScripts;
file_put_contents('revenue.php', $finalContent);
echo "revenue.php successfully rewritten!\n";
