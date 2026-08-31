<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$sort = $_GET['sort'] ?? 'revenue';

$query = "
    SELECT 
        c.id, 
        c.company_name, 
        c.contact_person, 
        c.email,
        COUNT(i.id) as orders, 
        SUM(i.total_amount) as revenue,
        MAX(i.created_at) as last_order
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id AND i.status != 'cancelled' AND i.created_at BETWEEN ? AND ?
    GROUP BY c.id, c.company_name, c.contact_person, c.email
";

if ($sort === 'revenue') $query .= " ORDER BY revenue DESC";
elseif ($sort === 'orders') $query .= " ORDER BY orders DESC";
else $query .= " ORDER BY c.company_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute([$startDate, $endDate]);
$customers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Customers Performance | ERP Analytics</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6; --bg-primary: #f8fafc; --text-primary: #1e293b; --border: #e2e8f0;
        }
        body { background: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', sans-serif; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn-back { display: inline-flex; align-items: center; padding: 8px 16px; background: white; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; color: var(--text-primary); }
        
        .report-sheet { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th { text-align: left; padding: 15px 10px; background: #f1f5f9; font-size: 0.75rem; text-transform: uppercase; color: #64748b; }
        .table-custom td { padding: 15px 10px; border-bottom: 1px solid var(--border); font-size: 0.875rem; }
        .doc-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        .doc-link:hover { text-decoration: underline; }
        
        .sort-pill { padding: 4px 12px; border-radius: 20px; background: #e2e8f0; text-decoration: none; color: #475569; font-size: 0.75rem; }
        .sort-pill.active { background: var(--primary); color: white; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div style="display: flex; align-items: center; gap: 20px;">
            <a href="sales.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Dashboard</a>
            <h1 style="margin: 0; font-size: 1.5rem;">Customer Performance Report</h1>
        </div>
        
        <form method="GET" style="display: flex; gap: 10px;">
            <input type="date" name="start_date" value="<?= $startDate ?>" style="padding: 6px; border-radius: 6px; border: 1px solid var(--border);">
            <input type="date" name="end_date" value="<?= $endDate ?>" style="padding: 6px; border-radius: 6px; border: 1px solid var(--border);">
            <button type="submit" style="background: var(--primary); color: white; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer;">Update</button>
        </form>
    </div>

    <div class="report-sheet">
        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
            <span>Sort By:</span>
            <a href="?sort=revenue&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="sort-pill <?= $sort === 'revenue' ? 'active' : '' ?>">Revenue</a>
            <a href="?sort=orders&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="sort-pill <?= $sort === 'orders' ? 'active' : '' ?>">Orders</a>
            <a href="?sort=name&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="sort-pill <?= $sort === 'name' ? 'active' : '' ?>">Name</a>
        </div>

        <table class="table-custom">
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Contact Person</th>
                    <th style="text-align: center;">Orders</th>
                    <th style="text-align: right;">Total Revenue</th>
                    <th>Last Active</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td>
                        <a href="customer_details.php?id=<?= $c['id'] ?>" class="doc-link"><?= htmlspecialchars($c['company_name']) ?></a>
                    </td>
                    <td><?= htmlspecialchars($c['contact_person'] ?: 'N/A') ?></td>
                    <td style="text-align: center;"><?= number_format($c['orders']) ?></td>
                    <td style="text-align: right; font-weight: 700;">TSH <?= number_format($c['revenue'] ?: 0, 0) ?></td>
                    <td style="color: #64748b; font-size: 0.8rem;">
                        <?= $c['last_order'] ? date('M d, Y', strtotime($c['last_order'])) : 'Never' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
