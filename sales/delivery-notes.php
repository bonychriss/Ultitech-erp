<?php 
require_once '../../includes/functions.php';
global $pdo;

$sql = "SELECT d.*, c.name as customer_name, s.order_number 
        FROM erp_delivery_notes d 
        JOIN erp_customers c ON d.customer_id = c.id 
        JOIN erp_sales_orders s ON d.order_id = s.id 
        ORDER BY d.id DESC";
$deliveries = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Notes - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
         * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; width: 100% !important; } }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h2 { margin: 0; font-size: 1.75rem; color: #1f2937; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table th { text-align: left; padding: 12px 16px; background: #f8f9fa; border-bottom: 2px solid #e5e7eb; color: #4b5563; }
        .table td { padding: 16px; border-bottom: 1px solid #f3f4f6; color: #1f2937; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-draft { background:#f3f4f6; color:#374151; }
        .badge-scheduled { background:#dbeafe; color:#2563eb; }
        .badge-delivered { background:#d1fae5; color:#059669; }
        .badge-cancelled { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <h2>Delivery Notes</h2>
        <!-- Creation usually happens from Sales Order, so no 'New' button here typically, unless standalone delivery -->
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>DN #</th>
                <th>Order #</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Driver / Vehicle</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deliveries)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:#6b7280;">No deliveries found. Create one from a Sales Order.</td></tr>
            <?php else: ?>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td style="font-family:monospace; font-weight:500;"><?= htmlspecialchars($d['delivery_number']) ?></td>
                        <td><a href="view-sales-order.php?id=<?= $d['order_id'] ?>" style="color:#2563eb;"><?= htmlspecialchars($d['order_number']) ?></a></td>
                        <td><?= htmlspecialchars($d['customer_name']) ?></td>
                        <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
                        <td>
                            <?php if($d['driver_name']): ?>
                                <div><i class="fas fa-user"></i> <?= htmlspecialchars($d['driver_name']) ?></div>
                            <?php endif; ?>
                            <?php if($d['vehicle_reg']): ?>
                                <div style="font-size:0.85em; color:#6b7280;"><i class="fas fa-truck"></i> <?= htmlspecialchars($d['vehicle_reg']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
                        <td>
                             <!-- Action buttons like 'Print' or 'Mark Delivered' -->
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
