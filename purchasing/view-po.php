<?php
require_once '../../includes/functions.php';

global $pdo;
$id = $_GET['id'] ?? 0;

// Get PO details
$stmt = $pdo->prepare("SELECT po.*, s.name as supplier_name, s.address as supplier_address, s.email as supplier_email, s.phone as supplier_phone, u.full_name as created_by_name 
                       FROM erp_purchase_orders po 
                       JOIN erp_suppliers s ON po.supplier_id = s.id 
                       LEFT JOIN users u ON po.created_by = u.id 
                       WHERE po.id = ?");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    die("Purchase Order not found");
}

// Get PO items
$stmt = $pdo->prepare("SELECT poi.*, p.name as product_name, p.sku 
                       FROM erp_purchase_order_items poi 
                       JOIN erp_products p ON poi.product_id = p.id 
                       WHERE poi.po_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO #<?= htmlspecialchars($po['po_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #525659; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; padding: 20px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
        .toolbar { background: #323639; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px 4px 0 0; margin-bottom: 20px; max-width: 800px; margin: 0 auto 20px auto; }
        
        .invoice-container { background: white; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); min-height: 1000px; }
        
        .invoice-header { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .company-info h2 { color: #1a73e8; margin-bottom: 10px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { color: #5f6368; font-weight: 300; font-size: 2.5rem; margin-bottom: 10px; }
        
        .invoice-details { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .bill-to { flex: 1; }
        .invoice-meta { text-align: right; }
        
        .table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; text-transform: uppercase; }
        .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        .text-right { text-align: right; }
        
        .totals { display: flex; justify-content: flex-end; }
        .totals-table { width: 300px; }
        .totals-table td { padding: 8px 0; }
        .totals-table .final { font-weight: 600; font-size: 1.1rem; border-top: 2px solid #e0e0e0; padding-top: 16px; margin-top: 8px; }
        
        .notes { margin-top: 40px; padding-top: 20px; border-top: 1px solid #f1f3f4; color: #5f6368; font-size: 0.875rem; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; color: white; background: #1a73e8; }
        .btn-secondary { background: #5f6368; }
        .btn-success { background: #137333; }
        
        @media print {
            body { background: white; padding: 0; }
            .toolbar { display: none; }
            .invoice-container { box-shadow: none; padding: 0; margin: 0; }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div class="toolbar">
        <div>
            <a href="purchase-orders.php" style="color: white; text-decoration: none;">â† Back to POs</a>
        </div>
        <div>
            <?php if ($po['status'] !== 'received' && $po['status'] !== 'cancelled'): ?>
                <button class="btn btn-success" onclick="receiveGoods()">Receive Goods</button>
            <?php endif; ?>
            <button class="btn btn-secondary" onclick="window.print()">Print</button>
        </div>
    </div>
    
    <div class="invoice-container">
        <div class="invoice-header">
            <div class="company-info">
                <h2><?= COMPANY_NAME ?></h2>
                <p>123 Business Street</p>
                <p>Dar es Salaam, Tanzania</p>
            </div>
            <div class="invoice-title">
                <h1>PURCHASE ORDER</h1>
                <p>#<?= htmlspecialchars($po['po_number']) ?></p>
            </div>
        </div>
        
        <div class="invoice-details">
            <div class="bill-to">
                <h3 style="font-size: 0.875rem; color: #5f6368; text-transform: uppercase; margin-bottom: 8px;">Vendor:</h3>
                <p><strong><?= htmlspecialchars($po['supplier_name']) ?></strong></p>
                <p><?= nl2br(htmlspecialchars($po['supplier_address'] ?? '')) ?></p>
                <p><?= htmlspecialchars($po['supplier_email'] ?? '') ?></p>
                <p><?= htmlspecialchars($po['supplier_phone'] ?? '') ?></p>
            </div>
            <div class="invoice-meta">
                <p><strong>Date:</strong> <?= date('M d, Y', strtotime($po['order_date'])) ?></p>
                <p><strong>Expected:</strong> <?= $po['expected_date'] ? date('M d, Y', strtotime($po['expected_date'])) : '-' ?></p>
                <p><strong>Status:</strong> <?= ucfirst($po['status']) ?></p>
            </div>
        </div>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Unit Cost</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                            <span style="font-size: 0.8rem; color: #5f6368;"><?= htmlspecialchars($item['sku']) ?></span>
                        </td>
                        <td class="text-right"><?= floatval($item['quantity']) ?></td>
                        <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                        <td class="text-right"><?= number_format($item['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="totals">
            <table class="totals-table">
                <tr>
                    <td class="final">Total:</td>
                    <td class="final text-right">TSh <?= number_format($po['total_amount'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <?php if (!empty($po['notes'])): ?>
        <div class="notes">
            <strong>Notes:</strong><br>
            <?= nl2br(htmlspecialchars($po['notes'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        async function receiveGoods() {
            if (!confirm('Are you sure you want to mark these goods as received? This will increase your stock levels.')) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'receive');
                formData.append('id', <?= $po['id'] ?>);
                
                const response = await fetch('../api/purchase-orders.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Goods received successfully! Stock has been updated.');
                    window.location.reload();
                } else {
                    alert('Failed: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });
    </script>
</div>
</body>
</html>

