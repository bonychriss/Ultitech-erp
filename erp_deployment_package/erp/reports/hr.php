<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// 1. Employee Count by Department
$sql = "SELECT d.name, COUNT(e.id) as count 
        FROM erp_employees e 
        JOIN erp_departments d ON e.department_id = d.id 
        WHERE e.status = 'active' 
        GROUP BY d.id";
$deptStats = $pdo->query($sql)->fetchAll();

// 2. Monthly Payroll Cost
$sql = "SELECT SUM(net_salary) FROM erp_payroll 
        WHERE MONTH(payroll_month) = MONTH(CURRENT_DATE()) 
        AND YEAR(payroll_month) = YEAR(CURRENT_DATE())";
$monthlyPayroll = $pdo->query($sql)->fetchColumn() ?: 0;

// 3. Leave Stats (This Month)
$sql = "SELECT status, COUNT(*) as count 
        FROM erp_leave_requests 
        WHERE MONTH(start_date) = MONTH(CURRENT_DATE()) 
        GROUP BY status";
$leaveStats = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Recent Hires
$sql = "SELECT first_name, last_name, position, join_date 
        FROM erp_employees 
        ORDER BY join_date DESC LIMIT 5";
$recentHires = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HR Reports - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; padding: 24px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .metric-value { font-size: 2rem; font-weight: 600; color: #202124; }
        .metric-label { color: #5f6368; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { text-align: left; padding: 8px; border-bottom: 2px solid #f1f3f4; font-size: 0.875rem; color: #5f6368; }
        td { padding: 12px 8px; border-bottom: 1px solid #f1f3f4; font-size: 0.875rem; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ‘¥ HR & Payroll Reports</h1>
        <a href="index.php" class="btn btn-secondary">â† Back to Reports</a>
    </div>
    
    <div class="container">
        <div class="grid-2">
            <div class="card">
                <div class="metric-label">Monthly Payroll Cost</div>
                <div class="metric-value">TSh <?= number_format($monthlyPayroll) ?></div>
            </div>
            
            <div class="card">
                <div class="metric-label">Leave Requests (This Month)</div>
                <div class="metric-value">
                    <?= ($leaveStats['pending'] ?? 0) + ($leaveStats['approved'] ?? 0) + ($leaveStats['rejected'] ?? 0) ?>
                </div>
                <div style="margin-top: 8px; font-size: 0.875rem;">
                    <span style="color: #b06000;"><?= $leaveStats['pending'] ?? 0 ?> Pending</span> â€¢ 
                    <span style="color: #137333;"><?= $leaveStats['approved'] ?? 0 ?> Approved</span>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h3>Department Distribution</h3>
                <canvas id="deptChart"></canvas>
            </div>
            
            <div class="card">
                <h3>Recent Hires</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Join Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentHires as $h): ?>
                        <tr>
                            <td><?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?></td>
                            <td><?= htmlspecialchars($h['position']) ?></td>
                            <td><?= date('M d, Y', strtotime($h['join_date'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        const ctx = document.getElementById('deptChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($deptStats, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($deptStats, 'count')) ?>,
                    backgroundColor: ['#1a73e8', '#137333', '#f9ab00', '#c5221f', '#a142f4']
                }]
            }
        });
    </script>
</body>
</html>

