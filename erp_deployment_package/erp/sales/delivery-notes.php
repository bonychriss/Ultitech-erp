<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

$status = $_GET['status'] ?? 'all';
$sql = "SELECT dn.*, c.name as customer_name 
        FROM erp_delivery_notes dn 
        JOIN erp_customers c ON dn.customer_id = c.id 
        WHERE 1=1";
$params = [];

if ($status !== 'all') {
    $sql .= " AND dn.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY dn.date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Notes - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; }
        .filters { display: flex; gap: 12px; }
        .filter-select { padding: 10px 16px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; background: white; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸšš Delivery Notes</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <a href="create-delivery.php" class="btn btn-primary">+ New Delivery</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="dispatched" <?= $status == 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                        <option value="delivered" <?= $status == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    </select>
                </form>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>DN #</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Driver</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: #5f6368;">
                                <div style="font-size: 4rem; margin-bottom: 16px;">ðŸšš</div>
                                <h3>No delivery notes found</h3>
                                <p>Create delivery notes to track shipments.</p>
                                <a href="create-delivery.php" class="btn btn-primary" style="margin-top: 16px;">+ New Delivery</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $dn): ?>
                            <tr>
                                <td><?= htmlspecialchars($dn['delivery_number']) ?></td>
                                <td><?= htmlspecialchars($dn['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($dn['date'])) ?></td>
                                <td><?= htmlspecialchars($dn['driver_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($dn['vehicle_number'] ?? '-') ?></td>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'draft' => 'badge-warning',
                                        'dispatched' => 'badge-info',
                                        'delivered' => 'badge-success'
                                    ];
                                    ?>
                                    <span class="badge <?= $statusClass[$dn['status']] ?? 'badge-info' ?>">
                                        <?= ucfirst($dn['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view-delivery.php?id=<?= $dn['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

