<?php
require_once __DIR__ . '/includes/dashboard_stats.php';
requireLogin();

global $pdo;
$dashboard = new DashboardStats($pdo);
$kpis = $dashboard->getKPIs();
$salesTrend = $dashboard->getSalesTrend();
$topCustomers = $dashboard->getTopCustomers();

// Prepare Chart Data
$months = array_column($salesTrend, 'month');
$salesData = array_column($salesTrend, 'total');
$custNames = array_column($topCustomers, 'name');
$custTotals = array_column($topCustomers, 'total');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard - ERP</title>
    <!-- Modern Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary: #1a1a1a;
            --accent: #FFD700; /* Ultimate Yellow */
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            background: var(--bg); 
            font-family: 'Inter', sans-serif; 
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            margin-left: 220px; /* Matching existing sidebar width */
            padding: 32px;
            min-height: 100vh;
        }

        /* Header */
        .dashboard-header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dashboard-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--primary);
        }
        .dashboard-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 4px;
        }
        
        .actions-bar {
            display: flex;
            gap: 12px;
        }
        .btn-action {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
            background: #000;
        }
        .btn-action.secondary {
            background: white;
            color: var(--primary);
            border: 1px solid #e5e7eb;
        }

        /* KPI Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .kpi-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .kpi-card:hover { /* Subtle hover effect */
            border-color: rgba(0,0,0,0.1);
        }

        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .kpi-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        
        .kpi-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.025em;
        }

        .kpi-subtext {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 8px;
        }

        /* Specialized KPI Colors */
        .kpi-sales .kpi-icon { background: rgba(255, 215, 0, 0.2); color: #b48608; }
        .kpi-cash .kpi-icon { background: #dcfce7; color: #166534; }
        .kpi-ar .kpi-icon { background: #dbeafe; color: #1e40af; }
        .kpi-ap .kpi-icon { background: #fee2e2; color: #991b1b; }

        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chart-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chart-title {
            font-weight: 600;
            color: var(--primary);
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .charts-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    
    <!-- Header -->
    <div class="dashboard-header">
        <div>
            <h1>Executive Overview</h1>
            <p>Welcome back, Director. Here is today's financial snapshot.</p>
        </div>
        <div class="actions-bar">
            <a href="sales/create-invoice.php" class="btn-action">
                <i class="fas fa-plus"></i> New Invoice
            </a>
            <a href="purchasing/create-po.php" class="btn-action secondary">
                <i class="fas fa-shopping-cart"></i> New PO
            </a>
        </div>
    </div>

    <!-- KPI Grid -->
    <div class="kpi-grid">
        <!-- Sales -->
        <div class="kpi-card kpi-sales">
            <div class="kpi-header">
                <div>
                    <div class="kpi-label">TOTAL SALES (MTD)</div>
                    <div class="kpi-value">TSh <?= number_format($kpis['sales']['mtd']) ?></div>
                </div>
                <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
            </div>
            <div class="kpi-subtext">
                Today: <strong>TSh <?= number_format($kpis['sales']['today']) ?></strong> | YTD: TSh <?= number_format($kpis['sales']['ytd']) ?>
            </div>
        </div>

        <!-- Cash Position -->
        <div class="kpi-card kpi-cash">
            <div class="kpi-header">
                <div>
                    <div class="kpi-label">CASH POSITION</div>
                    <div class="kpi-value">TSh <?= number_format($kpis['cash']) ?></div>
                </div>
                <div class="kpi-icon"><i class="fas fa-university"></i></div>
            </div>
            <div class="kpi-subtext">
                Bank + Cash on Hand
            </div>
        </div>

        <!-- Receivables -->
        <div class="kpi-card kpi-ar">
            <div class="kpi-header">
                <div>
                    <div class="kpi-label">RECEIVABLES (AR)</div>
                    <div class="kpi-value">TSh <?= number_format($kpis['outstanding']['ar']) ?></div>
                </div>
                <div class="kpi-icon"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
            <div class="kpi-subtext">
                Pending Invoices
            </div>
        </div>

        <!-- Payables -->
        <div class="kpi-card kpi-ap">
            <div class="kpi-header">
                <div>
                    <div class="kpi-label">PAYABLES (AP)</div>
                    <div class="kpi-value">TSh <?= number_format($kpis['outstanding']['ap']) ?></div>
                </div>
                <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
            <div class="kpi-subtext">
                Pending Purchase Orders
            </div>
        </div>
        
        <!-- Operations Card -->
        <div class="kpi-card">
             <div class="kpi-header">
                <div>
                    <div class="kpi-label">PENDING COMPLIANCE</div>
                    <div class="kpi-value" style="font-size: 1.5rem;"><?= $kpis['approvals'] ?> Approvals</div>
                </div>
                <div class="kpi-icon" style="background: #f3f4f6;"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="kpi-subtext" style="color: var(--danger);">
                <?= $kpis['stock'] ?> Low Stock Alerts
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-grid">
        <!-- Sales Trend -->
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">Revenue Trend (6 Months)</div>
                <i class="fas fa-chart-line" style="color: var(--text-muted);"></i>
            </div>
            <canvas id="salesChart" height="100"></canvas>
        </div>

        <!-- Top Customers -->
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">Top Customers</div>
            </div>
            <canvas id="customerChart" height="200"></canvas>
        </div>
    </div>

</div>

<script>
    // Sales Chart
    const ctxSales = document.getElementById('salesChart').getContext('2d');
    new Chart(ctxSales, {
        type: 'line',
        data: {
            labels: <?= json_encode($months) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode($salesData) ?>,
                borderColor: '#1a1a1a',
                backgroundColor: 'rgba(255, 215, 0, 0.1)', // Yellow tint
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#FFD700'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    // Customer Chart
    const ctxCust = document.getElementById('customerChart').getContext('2d');
    new Chart(ctxCust, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($custNames) ?>,
            datasets: [{
                data: <?= json_encode($custTotals) ?>,
                backgroundColor: [
                    '#1a1a1a', 
                    '#4b5563', 
                    '#9ca3af',
                    '#FFD700', // Accent
                    '#e5e7eb'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { 
                legend: { position: 'bottom', labels: { boxWidth: 10 } } 
            }
        }
    });
</script>

</body>
</html>
