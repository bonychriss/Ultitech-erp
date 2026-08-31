<?php
require_once '../../includes/functions.php';

global $pdo;

$year = $_GET['year'] ?? date('Y');

// 1. Income (Invoices)
$sql = "SELECT MONTH(invoice_date) as month, SUM(total) as total 
        FROM erp_invoices 
        WHERE status != 'draft' AND YEAR(invoice_date) = ? 
        GROUP BY month";
$stmt = $pdo->prepare($sql);
$stmt->execute([$year]);
$incomeData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Expenses
$sql = "SELECT MONTH(date) as month, SUM(amount) as total 
        FROM erp_expenses 
        WHERE status = 'approved' AND YEAR(date) = ? 
        GROUP BY month";
$stmt = $pdo->prepare($sql);
$stmt->execute([$year]);
$expenseData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Prepare Chart Data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$income = [];
$expenses = [];
$profit = [];

$totalIncome = 0;
$totalExpenses = 0;

for ($i = 1; $i <= 12; $i++) {
    $inc = $incomeData[$i] ?? 0;
    $exp = $expenseData[$i] ?? 0;
    
    $income[] = $inc;
    $expenses[] = $exp;
    $profit[] = $inc - $exp;
    
    $totalIncome += $inc;
    $totalExpenses += $exp;
}

$netProfit = $totalIncome - $totalExpenses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Reports - ERP</title>
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
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; }
        .metric-value { font-size: 2rem; font-weight: 600; color: #202124; }
        .metric-label { color: #5f6368; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .text-success { color: #137333; }
        .text-danger { color: #c5221f; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions">
            <a href="index.php" class="btn btn-secondary">â† Back</a>
            <a href="../accounting/balance-sheet.php" class="btn btn-secondary">Balance Sheet</a>
            <a href="../accounting/trial-balance.php" class="btn btn-secondary">Trial Balance</a>
        </div></div>
    
    <div class="container">
        <div class="grid-3">
            <div class="card">
                <div class="metric-label">Total Income</div>
                <div class="metric-value text-success">TSh <?= number_format($totalIncome) ?></div>
            </div>
            <div class="card">
                <div class="metric-label">Total Expenses</div>
                <div class="metric-value text-danger">TSh <?= number_format($totalExpenses) ?></div>
            </div>
            <div class="card">
                <div class="metric-label">Net Profit</div>
                <div class="metric-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                    TSh <?= number_format($netProfit) ?>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h3>Profit & Loss Overview</h3>
            <canvas id="plChart" height="100"></canvas>
        </div>
    </div>
    
    <script>
        const ctx = document.getElementById('plChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($months) ?>,
                datasets: [
                    {
                        label: 'Income',
                        data: <?= json_encode($income) ?>,
                        backgroundColor: '#137333'
                    },
                    {
                        label: 'Expenses',
                        data: <?= json_encode($expenses) ?>,
                        backgroundColor: '#c5221f'
                    },
                    {
                        label: 'Net Profit',
                        data: <?= json_encode($profit) ?>,
                        type: 'line',
                        borderColor: '#1a73e8',
                        borderWidth: 2,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
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


