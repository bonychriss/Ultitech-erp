<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Fetch key metrics
// 1. Total Sales (This Month)
$stmt = $pdo->prepare("SELECT SUM(total) FROM erp_invoices WHERE status != 'draft' AND MONTH(invoice_date) = MONTH(CURRENT_DATE()) AND YEAR(invoice_date) = YEAR(CURRENT_DATE())");
$stmt->execute();
$monthlySales = $stmt->fetchColumn() ?: 0;

// 2. Total Expenses (This Month)
$stmt = $pdo->prepare("SELECT SUM(amount) FROM erp_expenses WHERE status = 'approved' AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
$stmt->execute();
$monthlyExpenses = $stmt->fetchColumn() ?: 0;

// 3. Outstanding Invoices (Receivables)
$stmt = $pdo->query("SELECT SUM(total) FROM erp_invoices WHERE status = 'sent' OR status = 'overdue'");
$outstandingReceivables = $stmt->fetchColumn() ?: 0;

// 4. Low Stock Items
$stmt = $pdo->query("SELECT COUNT(*) FROM erp_products WHERE stock_quantity <= reorder_level");
$lowStockCount = $stmt->fetchColumn() ?: 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 100%; padding: 24px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .metric-card { background: white; padding: 24px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .metric-title { color: #5f6368; font-size: 0.875rem; font-weight: 500; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-value { font-size: 2rem; font-weight: 600; color: #202124; }
        .metric-sub { font-size: 0.875rem; margin-top: 8px; }
        .text-success { color: #137333; }
        .text-danger { color: #c5221f; }
        
        .reports-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .report-card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit; display: block; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .report-icon { height: 120px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #1a73e8; }
        .report-content { padding: 20px; }
        .report-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 8px; color: #202124; }
        .report-desc { color: #5f6368; font-size: 0.875rem; line-height: 1.5; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a></div>
    
    <div class="container">
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title">Sales (This Month)</div>
                <div class="metric-value">TSh <?= number_format($monthlySales) ?></div>
                <div class="metric-sub text-success">Revenue</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Expenses (This Month)</div>
                <div class="metric-value">TSh <?= number_format($monthlyExpenses) ?></div>
                <div class="metric-sub text-danger">Cost</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Receivables</div>
                <div class="metric-value">TSh <?= number_format($outstandingReceivables) ?></div>
                <div class="metric-sub">Outstanding Invoices</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Inventory Health</div>
                <div class="metric-value"><?= $lowStockCount ?> Items</div>
                <div class="metric-sub text-danger">Low Stock Alerts</div>
            </div>
        </div>
        
        <h2 style="margin-bottom: 24px; font-weight: 500; color: #202124;">Available Reports</h2>
        
        <div class="reports-grid">
            <a href="sales.php" class="report-card">
                <div class="report-icon"><i class="fas fa-chart-line"></i></div>
                <div class="report-content">
                    <div class="report-title">Sales Analytics</div>
                    <div class="report-desc">Detailed breakdown of sales performance, top selling products, and customer insights.</div>
                </div>
            </a>
            
            <a href="inventory.php" class="report-card">
                <div class="report-icon"><i class="fas fa-boxes"></i></div>
                <div class="report-content">
                    <div class="report-title">Inventory Reports</div>
                    <div class="report-desc">Stock valuation, movement history, low stock alerts, and batch expiry tracking.</div>
                </div>
            </a>
            
            <a href="financial.php" class="report-card">
                <div class="report-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="report-content">
                    <div class="report-title">Financial Statements</div>
                    <div class="report-desc">Profit & Loss, Balance Sheet, and Trial Balance.</div>
                </div>
            </a>
            
            <a href="hr.php" class="report-card">
                <div class="report-icon"><i class="fas fa-users"></i></div>
                <div class="report-content">
                    <div class="report-title">HR & Payroll</div>
                    <div class="report-desc">Employee cost analysis, leave statistics, and payroll history.</div>
                </div>
            </a>
        </div>
    </div>
</div>
</body>
</html>


