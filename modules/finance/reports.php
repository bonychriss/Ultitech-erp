<?php
// modules/finance/reports.php
require_once '../../includes/functions.php';
requireLogin();

$module_name = 'finance';
$_SESSION['active_module'] = $module_name;

$year = $_GET['year'] ?? date('Y');

// Fetch Monthly Income/Expense for the selected year
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(transaction_date, '%m') as month,
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as expense
    FROM finance_transactions 
    WHERE DATE_FORMAT(transaction_date, '%Y') = ?
    GROUP BY month
    ORDER BY month ASC
");
$stmt->execute([$year]);
// Fetch as associative array since we have 3 columns (month, income, expense)
$monthly_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$months = [];
$income_data = [];
$expense_data = [];

// Initialize all 12 months with 0
for ($m = 1; $m <= 12; $m++) {
    $m_padded = str_pad($m, 2, '0', STR_PAD_LEFT);
    $months[$m_padded] = 0; // Temp placeholder
    $income_data[$m_padded] = 0;
    $expense_data[$m_padded] = 0;
}

foreach ($monthly_results as $row) {
    $income_data[$row['month']] = (float)$row['income'];
    $expense_data[$row['month']] = (float)$row['expense'];
}

// Prepare arrays for Chart.js
$chart_labels = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
$chart_income = array_values($income_data);
$chart_expense = array_values($expense_data);

// Fetch Category Breakdown (Yearly)
$stmt = $pdo->prepare("
    SELECT category, SUM(amount) as total 
    FROM finance_transactions 
    WHERE type = 'debit' AND DATE_FORMAT(transaction_date, '%Y') = ?
    GROUP BY category 
    ORDER BY total DESC
");
$stmt->execute([$year]);
$category_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_labels = [];
$cat_data = [];
$cat_colors = ['#4f46e5', '#10b981', '#ef4444', '#f59e0b', '#6366f1', '#ec4899', '#8b5cf6', '#14b8a6'];

foreach ($category_results as $row) {
    $cat_labels[] = $row['category'];
    $cat_data[] = $row['total'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - <?php echo defined('SITE_NAME') ? SITE_NAME : 'ERP System'; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/finance.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-dark">Financial Reports</h2>
            <form method="GET" class="d-flex align-items-center gap-2">
                <label class="fw-bold text-muted">Year:</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php 
                    $curr_year = date('Y');
                    for ($y = $curr_year; $y >= $curr_year - 5; $y--) {
                        $selected = ($y == $year) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>
            </form>
        </div>

        <!-- <?php include 'includes/navbar.php'; ?> -->

        <div class="row g-4">
            <!-- Monthly Trend -->
            <div class="col-lg-8">
                <div class="finance-card p-4 mb-4">
                    <h5 class="fw-bold mb-4">Monthly Income vs Expenses (<?php echo $year; ?>)</h5>
                    <canvas id="monthlyChart" height="300"></canvas>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="col-lg-4">
                <div class="finance-card p-4">
                    <h5 class="fw-bold mb-4">Expense Breakdown</h5>
                    <div style="height: 300px; position: relative;">
                         <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
             <div class="col-12">
                <div class="finance-card p-4">
                    <h5 class="fw-bold mb-3">Yearly Summary</h5>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Total Spent</th>
                                    <th class="text-end">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_yearly_expense = array_sum($cat_data);
                                foreach ($category_results as $cat): 
                                    $pct = ($total_yearly_expense > 0) ? ($cat['total'] / $total_yearly_expense) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['category']); ?></td>
                                    <td class="text-end"><?php echo number_format($cat['total']); ?> TZS</td>
                                    <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end gap-2">
                                            <span class="small text-muted"><?php echo number_format($pct, 1); ?>%</span>
                                            <div class="progress" style="width: 50px; height: 5px;">
                                                <div class="progress-bar" style="width: <?php echo $pct; ?>%; background-color: var(--primary-color);"></div>
                                            </div>
                                        </div>
                                    </td>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Monthly Chart
    const ctx1 = document.getElementById('monthlyChart').getContext('2d');
    new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                {
                    label: 'Income',
                    data: <?php echo json_encode($chart_income); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'Expense',
                    data: <?php echo json_encode($chart_expense); ?>,
                    backgroundColor: '#ef4444',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [2, 4] } },
                x: { grid: { display: false } }
            }
        }
    });

    // Category Chart
    const ctx2 = document.getElementById('categoryChart').getContext('2d');
    new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($cat_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($cat_data); ?>,
                backgroundColor: <?php echo json_encode($cat_colors); ?>,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' }
            }
        }
    });
</script>
</body>
</html>
