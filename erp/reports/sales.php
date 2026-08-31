<?php
require_once '../../includes/functions.php';

global $pdo;

// Date Range Filter
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// 1. Daily Sales Chart Data
$sql = "SELECT DATE(invoice_date) as date, SUM(total) as total 
        FROM erp_invoices 
        WHERE status != 'draft' AND invoice_date BETWEEN ? AND ? 
        GROUP BY DATE(invoice_date) 
        ORDER BY date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$startDate, $endDate]);
$dailySales = $stmt->fetchAll();

$dates = [];
$totals = [];
foreach ($dailySales as $day) {
    $dates[] = date('M d', strtotime($day['date']));
    $totals[] = $day['total'];
}

// 2. Top Selling Products
$sql = "SELECT p.name, SUM(ii.quantity) as qty, SUM(ii.total) as revenue 
        FROM erp_invoice_items ii 
        JOIN erp_invoices i ON ii.invoice_id = i.id 
        JOIN erp_products p ON ii.product_id = p.id 
        WHERE i.status != 'draft' AND i.invoice_date BETWEEN ? AND ? 
        GROUP BY p.id 
        ORDER BY revenue DESC 
        LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([$startDate, $endDate]);
$topProducts = $stmt->fetchAll();

// 3. Top Customers
$sql = "SELECT c.name, COUNT(i.id) as invoice_count, SUM(i.total) as revenue 
        FROM erp_invoices i 
        JOIN erp_customers c ON i.customer_id = c.id 
        WHERE i.status != 'draft' AND i.invoice_date BETWEEN ? AND ? 
        GROUP BY c.id 
        ORDER BY revenue DESC 
        LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([$startDate, $endDate]);
$topCustomers = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Analytics - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; padding: 24px; }
        .filter-bar { background: white; padding: 16px 24px; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 24px; display: flex; align-items: center; gap: 16px; }
        .form-control { padding: 8px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { text-align: left; padding: 8px; border-bottom: 2px solid #f1f3f4; font-size: 0.875rem; color: #5f6368; }
        td { padding: 12px 8px; border-bottom: 1px solid #f1f3f4; font-size: 0.875rem; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div style="padding: 16px 24px 0; text-align: right;"><a href="index.php" class="btn btn-secondary">â† Back to Reports</a></div>
    
    <div class="container">
        <form class="filter-bar">
            <div style="font-weight: 500;">Date Range:</div>
            <input type="date" name="start_date" class="form-control" value="<?= $startDate ?>">
            <span>to</span>
            <input type="date" name="end_date" class="form-control" value="<?= $endDate ?>">
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </form>
        
        <div class="card">
            <h3 style="margin-bottom: 20px;">Sales Trend</h3>
            <canvas id="salesChart" height="80"></canvas>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h3>Top Selling Products</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Qty Sold</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td style="text-align: right;"><?= number_format($p['qty']) ?></td>
                            <td style="text-align: right;">TSh <?= number_format($p['revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topProducts)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #5f6368;">No data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <h3>Top Customers</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th style="text-align: right;">Invoices</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCustomers as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td style="text-align: right;"><?= number_format($c['invoice_count']) ?></td>
                            <td style="text-align: right;">TSh <?= number_format($c['revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topCustomers)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #5f6368;">No data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        const ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($dates) ?>,
                datasets: [{
                    label: 'Daily Revenue (TSh)',
                    data: <?= json_encode($totals) ?>,
                    borderColor: '#1a73e8',
                    backgroundColor: 'rgba(26, 115, 232, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'TSh ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>


