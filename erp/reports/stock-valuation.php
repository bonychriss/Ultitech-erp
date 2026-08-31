<?php
require_once '../../includes/functions.php';
global $pdo;

// Fetch inventory valuation per product
$sql = "
SELECT 
    p.name, 
    p.sku, 
    SUM(b.remaining_quantity) as total_qty,
    SUM(b.remaining_quantity * b.cost_price) as total_value
FROM erp_products p
JOIN erp_product_batches b ON p.id = b.product_id
WHERE b.remaining_quantity > 0
GROUP BY p.id, p.name, p.sku
ORDER BY total_value DESC
";

$items = $pdo->query($sql)->fetchAll();
$grandTotal = array_sum(array_column($items, 'total_value'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Valuation (FIFO) - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px !important; padding: 30px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 1.8rem; font-weight: 700; color: #111827; }
        
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .table th { text-align: left; padding: 12px; background: #f9fafb; font-weight: 600; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .table td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        
        .value-cell { font-family: monospace; font-weight: 600; text-align: right; }
        
        .summary-box { background: #1a1a1a; color: white; padding: 24px; border-radius: 12px; margin-bottom: 24px; display: inline-block; min-width: 300px; }
        .summary-label { font-size: 0.9rem; color: #9ca3af; margin-bottom: 8px; }
        .summary-value { font-size: 2rem; font-weight: 700; color: #FFD700; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="header">
        <h1>Stock Valuation Report</h1>
        <button onclick="window.print()" style="padding: 8px 16px; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="summary-box">
        <div class="summary-label">Total Inventory Value (FIFO)</div>
        <div class="summary-value">TSh <?= number_format($grandTotal, 2) ?></div>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>SKU</th>
                    <th style="text-align: right;">Quantity on Hand</th>
                    <th style="text-align: right;">Total Value</th>
                    <th style="text-align: right;">Avg. Unit Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px;">No stock found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): 
                        $avgCost = $item['total_qty'] > 0 ? $item['total_value'] / $item['total_qty'] : 0;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= htmlspecialchars($item['sku']) ?></td>
                            <td class="value-cell"><?= number_format($item['total_qty'], 2) ?></td>
                            <td class="value-cell">TSh <?= number_format($item['total_value'], 2) ?></td>
                            <td class="value-cell"><?= number_format($avgCost, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
