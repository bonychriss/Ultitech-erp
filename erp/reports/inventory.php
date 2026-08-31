<?php
require_once '../../includes/functions.php';

global $pdo;

// 1. Stock Valuation
$sql = "SELECT SUM(stock_quantity * cost_price) as total_value, COUNT(*) as total_items 
        FROM erp_products WHERE status = 'active'";
$stockStats = $pdo->query($sql)->fetch();

// 2. Low Stock Items
$sql = "SELECT name, sku, stock_quantity, reorder_level 
        FROM erp_products 
        WHERE stock_quantity <= reorder_level AND status = 'active' 
        ORDER BY stock_quantity ASC";
$lowStock = $pdo->query($sql)->fetchAll();

// 3. Expiring Batches (Next 30 Days)
$sql = "SELECT b.*, p.name as product_name 
        FROM erp_inventory_batches b 
        JOIN erp_products p ON b.product_id = p.id 
        WHERE b.expiry_date IS NOT NULL 
        AND b.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) 
        AND b.quantity > 0 
        ORDER BY b.expiry_date ASC";
$expiringBatches = $pdo->query($sql)->fetchAll();

// 4. Recent Stock Movements
$sql = "SELECT sm.*, p.name as product_name, u.username 
        FROM erp_stock_movements sm 
        JOIN erp_products p ON sm.product_id = p.id 
        JOIN users u ON sm.created_by = u.id 
        ORDER BY sm.created_at DESC 
        LIMIT 10";
$movements = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Reports - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
        .badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div style="padding: 16px 24px 0; text-align: right;"><a href="index.php" class="btn btn-secondary">â† Back to Reports</a></div>
    
    <div class="container">
        <div class="grid-2">
            <div class="card">
                <div class="metric-label">Total Stock Value</div>
                <div class="metric-value">TSh <?= number_format($stockStats['total_value'], 2) ?></div>
                <div style="margin-top: 8px; color: #5f6368;"><?= number_format($stockStats['total_items']) ?> Active Products</div>
            </div>
            
            <div class="card">
                <div class="metric-label">Stock Health</div>
                <div class="metric-value"><?= count($lowStock) ?> Items</div>
                <div style="margin-top: 8px; color: #c5221f;">Below Reorder Level</div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h3>âš ï¸ Low Stock Alerts</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Current Stock</th>
                            <th style="text-align: right;">Reorder Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStock as $item): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500;"><?= htmlspecialchars($item['name']) ?></div>
                                <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($item['sku']) ?></div>
                            </td>
                            <td style="text-align: right; color: #c5221f; font-weight: 600;"><?= number_format($item['stock_quantity']) ?></td>
                            <td style="text-align: right;"><?= number_format($item['reorder_level']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lowStock)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #5f6368;">All stock levels healthy</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <h3>ðŸ“… Expiring Soon</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiringBatches as $batch): ?>
                        <tr>
                            <td><?= htmlspecialchars($batch['product_name']) ?></td>
                            <td><?= htmlspecialchars($batch['batch_number']) ?></td>
                            <td style="color: #c5221f;"><?= date('M d, Y', strtotime($batch['expiry_date'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expiringBatches)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #5f6368;">No expiring batches</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <h3>Recent Stock Movements</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Reference</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= date('M d H:i', strtotime($m['created_at'])) ?></td>
                        <td><?= htmlspecialchars($m['product_name']) ?></td>
                        <td>
                            <span class="badge <?= $m['type'] == 'in' ? 'badge-success' : 'badge-warning' ?>">
                                <?= strtoupper($m['type']) ?>
                            </span>
                        </td>
                        <td><?= number_format($m['quantity']) ?></td>
                        <td><?= htmlspecialchars($m['reference']) ?></td>
                        <td><?= htmlspecialchars($m['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>


