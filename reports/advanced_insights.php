<?php
require_once '../includes/functions.php';
require_once '../erp/includes/dashboard_stats.php';
requireLogin();

global $pdo;
$dashboard = new DashboardStats($pdo);

// --- View Logic ---
$view = $_GET['view'] ?? 'overview'; // Default to overview

// Global Date Range (Defaults to current month/quarter)
$startDate = date('Y-m-01', strtotime('-5 months')); // Last 6 months context
$endDate = date('Y-m-d');

// --- Data Fetching Based on View ---
$data = [];

// 1. Common Data (Used in Overview & specific stats)
// Revenue
$stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status != 'cancelled' AND invoice_date BETWEEN '$startDate' AND '$endDate'");
$periodRevenue = $stmt->fetchColumn();

// Expenses
$stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses_requests WHERE status = 'approved' AND date BETWEEN '$startDate' AND '$endDate'");
$periodExpenses = $stmt->fetchColumn();

// Net Profit
$netProfit = $periodRevenue - $periodExpenses;
$profitMargin = $periodRevenue > 0 ? ($netProfit / $periodRevenue) * 100 : 0;

// Sales Trend (Common for charts)
$salesTrend = $dashboard->getSalesTrend();
$chartLabels = json_encode(array_column($salesTrend, 'month'));
$chartData = json_encode(array_column($salesTrend, 'total'));

// Expense Categories (Common)
try {
    $stmt = $pdo->query("SELECT category, SUM(amount) as total FROM expenses_requests WHERE status='approved' GROUP BY category LIMIT 5");
    $expenseCats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $expenseCats = []; }
if (empty($expenseCats)) $expenseCats = ['General' => 0];
$expLabels = json_encode(array_keys($expenseCats));
$expData = json_encode(array_values($expenseCats));


// 2. Specific View Data
if ($view === 'finance') {
    // Recent Vouchers
    $stmt = $pdo->query("SELECT * FROM payment_vouchers ORDER BY created_at DESC LIMIT 5");
    $data['recent_vouchers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($view === 'inventory') {
    // Low Stock Items
    $stmt = $pdo->query("SELECT p.*, s.quantity as stock_quantity FROM products p JOIN stock s ON p.id = s.product_id WHERE s.quantity <= p.reorder_level LIMIT 10");
    $data['low_stock'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Schema Mapping
    $costCol = 'buying_price';
    $imageCol = 'main_image';

// --- 5. INVENTORY VALUATION ---
$stmt = $pdo->query("SELECT SUM(s.quantity * p.buying_price) as total_value FROM products p JOIN stock s ON p.id = s.product_id");
$inventoryValuation = $stmt->fetchColumn() ?: 0;

} elseif ($view === 'logistics') {
    // Trip Stats
    $stmt = $pdo->query("SELECT COUNT(*) FROM delivery_trips");
    $data['total_trips'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM delivery_trips WHERE status = 'completed'");
    $data['completed_trips'] = $stmt->fetchColumn();
    
    // Recent Trips
    $stmt = $pdo->query("SELECT * FROM delivery_trips ORDER BY created_at DESC LIMIT 5");
    $data['recent_trips'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Active Class Helper ---
function isActive($v, $current) { return $v === $current ? 'active-module' : ''; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Reports | Ultimate ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4361ee;
            --bg-body: #f8f9fa;
            --bg-card: #ffffff;
            --text-main: #2b2d42;
            --text-muted: #8d99ae;
            --radius-lg: 16px;
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-body); color: var(--text-main); }
        h1, h2, h3, h4, h5 { font-family: 'Poppins', sans-serif; }

        .wrapper { padding: 2rem; max-width: 1600px; margin: 0 auto; }
        
        /* Module Grid (Settings Style) */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 2rem;
        }

        .hub-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            height: 100%;
        }

        .hub-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .hub-card.active-module {
            border-color: var(--primary);
            background: #f0f4ff; /* Subtle highlight instead of solid block */
            box-shadow: 0 4px 6px -1px rgba(67, 97, 238, 0.1);
        }
        
        .hub-card i {
            font-size: 24px;
            margin-bottom: 16px;
            transition: color 0.2s;
        }
        
        .hub-card h3 {
            margin: 0 0 8px 0;
            font-size: 1.1rem;
            color: #111827;
            font-weight: 600;
        }

        .hub-card p {
            margin: 0;
            color: #6b7280;
            font-size: 0.85rem;
            line-height: 1.5;
            flex-grow: 1;
        }

        /* KPI Cards */
        .kpi-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .chart-panel {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        
        .table-custom th { font-weight: 600; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; }
        .table-custom td { vertical-align: middle; font-size: 0.9rem; }
        
        .animate-fade-in { animation: fadeIn 0.5s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="wrapper">
    
    <!-- Header -->
    <header class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>Advanced Reports</h1>
            <p class="text-muted m-0">AI-driven analysis for smarter decisions.</p>
        </div>
        <a href="../select-module.php" class="btn btn-outline-secondary rounded-pill"><i class="fas fa-arrow-left me-2"></i>Exit</a>
    </header>

    <!-- Module Navigation Cards (Settings Style) -->
    <div class="settings-grid">
        <!-- Overview -->
        <a href="?view=overview" class="hub-card <?= isActive('overview', $view) ?>">
            <i class="fas fa-th-large text-primary"></i>
            <h3>Overview</h3>
            <p>Executive summary of performance.</p>
        </a>

        <!-- Sales -->
        <a href="sales_report.php" class="hub-card">
            <i class="fas fa-chart-line text-success"></i>
            <h3>Sales & CRM</h3>
            <p>Revenue trends and customer insights.</p>
        </a>

        <!-- Finance -->
        <a href="?view=finance" class="hub-card <?= isActive('finance', $view) ?>">
            <i class="fas fa-wallet text-warning"></i>
            <h3>Finance</h3>
            <p>Expense breakdown and profit analysis.</p>
        </a>

        <!-- Inventory -->
        <a href="?view=inventory" class="hub-card <?= isActive('inventory', $view) ?>">
            <i class="fas fa-boxes text-info"></i>
            <h3>Inventory</h3>
            <p>Stock health and valuation reports.</p>
        </a>

        <!-- Logistics -->
        <a href="?view=logistics" class="hub-card <?= isActive('logistics', $view) ?>">
            <i class="fas fa-truck text-danger"></i>
            <h3>Logistics</h3>
            <p>Trip efficiency and delivery tracking.</p>
        </a>
    </div>


    <!-- DYNAMIC CONTENT AREA -->
    
    <!-- 1. OVERVIEW VIEW -->
    <?php if ($view === 'overview'): ?>
    <div class="animate-fade-in">
        <h4 class="mb-3">Executive Summary</h4>
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card border-start border-4 border-primary">
                    <div class="text-muted small">Total Revenue</div>
                    <div class="fs-3 fw-bold my-1">TSh <?= number_format($periodRevenue / 1000000, 1) ?>M</div>
                    <span class="badge bg-success-subtle text-success"><i class="fas fa-arrow-up"></i> MTD</span>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card border-start border-4 border-warning">
                    <div class="text-muted small">Net Profit Est.</div>
                    <div class="fs-3 fw-bold my-1">TSh <?= number_format($netProfit / 1000000, 1) ?>M</div>
                    <span class="text-muted small"><?= number_format($profitMargin, 1) ?>% Margin</span>
                </div>
            </div>
             <div class="col-xl-6">
                <div class="chart-panel h-100 mb-0">
                    <h5>Revenue Trend</h5>
                    <div style="height: 200px;"><canvas id="mainTrendChart"></canvas></div>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
             <div class="col-md-6">
                 <div class="chart-panel">
                     <h5>Expense Distribution</h5>
                     <div style="height: 250px;"><canvas id="expenseChart"></canvas></div>
                 </div>
             </div>
             <div class="col-md-6">
                 <div class="chart-panel">
                     <h5>AI Analysis</h5>
                     <div class="alert alert-light border">
                         <i class="fas fa-lightbulb text-warning me-2"></i>
                         <strong>Suggestion:</strong> <?= $profitMargin < 15 ? "Profit margin is low ($profitMargin%). Review 'Travel' expenses." : "Healthy profit margins maintained." ?>
                     </div>
                      <div class="alert alert-light border">
                         <i class="fas fa-box text-primary me-2"></i>
                         <strong>Inventory:</strong> Check the "Inventory" tab for recently low stock items.
                     </div>
                 </div>
             </div>
        </div>
    </div>
    


    <!-- 3. FINANCE VIEW -->
    <?php elseif ($view === 'finance'): ?>
    <div class="animate-fade-in">
        <h4 class="mb-3 text-warning">Financial Overview</h4>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="chart-panel">
                    <h5>Expense Breakdown</h5>
                    <div style="height: 300px;"><canvas id="expenseChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                 <div class="card border-0 shadow-sm rounded-4 h-100">
                     <div class="card-header bg-white border-0 pt-4 px-4"><h5>Recent Vouchers</h5></div>
                     <div class="card-body">
                         <div class="table-responsive">
                            <table class="table table-sm table-custom">
                                <thead><tr><th>Voucher</th><th>Payee</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach($data['recent_vouchers'] as $v): ?>
                                    <tr>
                                        <td><?= $v['voucher_no'] ?></td>
                                        <td><?= $v['payee_name'] ?></td>
                                        <td><?= number_format($v['total_amount']) ?></td>
                                        <td><?= $v['status'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                         </div>
                     </div>
                 </div>
            </div>
        </div>
    </div>

    <!-- 4. INVENTORY VIEW -->
    <?php elseif ($view === 'inventory'): ?>
    <div class="animate-fade-in">
        <h4 class="mb-3 text-info">Inventory Health</h4>
        <div class="row g-4 mb-4">
             <div class="col-md-4">
                 <div class="kpi-card bg-info-subtle border-0">
                     <h5 class="text-info-emphasis">Total Stock Value</h5>
                     <h2 class="mb-0">TSh <?= number_format($data['inventory_value'] / 1000000, 1) ?>M</h2>
                 </div>
             </div>
        </div>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-danger-subtle border-0 py-3"><h5 class="mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Low Stock Alerts</h5></div>
            <div class="card-body">
                <table class="table table-custom">
                    <thead><tr><th>Product Name</th><th>Current Stock</th><th>Reorder Level</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($data['low_stock'] as $item): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($item['name']) ?></td>
                            <td class="text-danger fw-bold"><?= $item['stock_quantity'] ?></td>
                            <td><?= $item['reorder_level'] ?></td>
                            <td><a href="../stock/modules/products/edit.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 5. LOGISTICS VIEW -->
    <?php elseif ($view === 'logistics'): ?>
    <div class="animate-fade-in">
        <h4 class="mb-3 text-secondary">Logistics Performance</h4>
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                 <div class="kpi-card text-center">
                     <div class="text-muted">Total Trips</div>
                     <div class="display-4 fw-bold"><?= $data['total_trips'] ?></div>
                 </div>
            </div>
            <div class="col-md-3">
                 <div class="kpi-card text-center">
                     <div class="text-muted">Success Rate</div>
                     <div class="display-4 fw-bold text-success"><?= $data['total_trips'] > 0 ? round(($data['completed_trips']/$data['total_trips'])*100) : 0 ?>%</div>
                 </div>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4">
             <div class="card-header bg-white border-0 pt-4 px-4"><h5>Recent Trips</h5></div>
             <div class="card-body">
                 <table class="table table-custom">
                     <thead><tr><th>Trip Ref</th><th>Driver</th><th>Status</th><th>Date</th></tr></thead>
                     <tbody>
                         <?php foreach($data['recent_trips'] as $trip): ?>
                         <tr>
                             <td><?= $trip['trip_ref'] ?></td>
                             <td><?= $trip['driver_name'] ?></td>
                             <td><span class="badge bg-primary-subtle text-primary"><?= $trip['status'] ?></span></td>
                             <td><?= date('d M', strtotime($trip['created_at'])) ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Scripts & Charts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Initialize charts regarding active view
    <?php if ($view === 'overview' || $view === 'sales'): ?>
    const ctxTrend = document.getElementById('mainTrendChart');
    if(ctxTrend) {
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?= $chartLabels ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?= $chartData ?>,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
    <?php endif; ?>

    <?php if ($view === 'overview' || $view === 'finance'): ?>
    const ctxExp = document.getElementById('expenseChart');
    if(ctxExp) {
        new Chart(ctxExp, {
            type: 'doughnut',
            data: {
                labels: <?= $expLabels ?>,
                datasets: [{
                    data: <?= $expData ?>,
                    backgroundColor: ['#4361ee', '#f72585', '#4cc9f0', '#ffc107', '#20c997']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%' }
        });
    }
    <?php endif; ?>
</script>

</body>
</html>
