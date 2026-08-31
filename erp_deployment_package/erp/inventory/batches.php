<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Get expiring batches (next 30 days)
$sql = "SELECT b.*, p.name as product_name, p.sku 
        FROM erp_inventory_batches b 
        JOIN erp_products p ON b.product_id = p.id 
        WHERE b.expiry_date IS NOT NULL 
        AND b.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)
        AND b.quantity > 0
        ORDER BY b.expiry_date ASC";
$expiringBatches = $pdo->query($sql)->fetchAll();

// Get all batches
$sql = "SELECT b.*, p.name as product_name, p.sku 
        FROM erp_inventory_batches b 
        JOIN erp_products p ON b.product_id = p.id 
        WHERE b.quantity > 0
        ORDER BY p.name ASC, b.expiry_date ASC";
$allBatches = $pdo->query($sql)->fetchAll();

$products = $pdo->query("SELECT id, name, sku FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Batch & Expiry Tracking - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .alert-section { background: #fef7e0; border: 1px solid #f9ab00; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .alert-section h3 { color: #b06000; margin-bottom: 12px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; max-width: 600px; margin: 50px auto; border-radius: 8px; padding: 24px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“¦ Batch & Expiry Tracking</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back</a>
            <button onclick="openAddBatchModal()" class="btn btn-primary">+ Add Batch</button>
        </div>
    </div>
    
    <div class="container">
        <?php if (!empty($expiringBatches)): ?>
            <div class="alert-section">
                <h3>âš ï¸ Expiring Soon (Next 30 Days)</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Batch #</th>
                            <th>Expiry Date</th>
                            <th>Quantity</th>
                            <th>Days Left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiringBatches as $batch): 
                            $daysLeft = (strtotime($batch['expiry_date']) - time()) / 86400;
                            $badgeClass = $daysLeft <= 7 ? 'badge-danger' : 'badge-warning';
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($batch['product_name']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($batch['sku']) ?></div>
                                </td>
                                <td style="font-family: monospace;"><?= htmlspecialchars($batch['batch_number']) ?></td>
                                <td><?= date('M d, Y', strtotime($batch['expiry_date'])) ?></td>
                                <td><?= number_format($batch['quantity'], 2) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= floor($daysLeft) ?> days</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h3>All Inventory Batches</h3>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Batch #</th>
                        <th>Expiry Date</th>
                        <th>Quantity</th>
                        <th>Cost Price</th>
                        <th>Created</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allBatches)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 48px; color: #5f6368;">No batches found. Add your first batch to start tracking.</td></tr>
                    <?php else: ?>
                        <?php foreach ($allBatches as $batch): 
                            $status = 'active';
                            $statusClass = 'badge-success';
                            if ($batch['expiry_date']) {
                                $daysLeft = (strtotime($batch['expiry_date']) - time()) / 86400;
                                if ($daysLeft <= 0) {
                                    $status = 'expired';
                                    $statusClass = 'badge-danger';
                                } elseif ($daysLeft <= 30) {
                                    $status = 'expiring';
                                    $statusClass = 'badge-warning';
                                }
                            }
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($batch['product_name']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($batch['sku']) ?></div>
                                </td>
                                <td style="font-family: monospace;"><?= htmlspecialchars($batch['batch_number']) ?></td>
                                <td><?= $batch['expiry_date'] ? date('M d, Y', strtotime($batch['expiry_date'])) : '-' ?></td>
                                <td><?= number_format($batch['quantity'], 2) ?></td>
                                <td>TSh <?= number_format($batch['cost_price'], 2) ?></td>
                                <td><?= date('M d, Y', strtotime($batch['created_at'])) ?></td>
                                <td><span class="badge <?= $statusClass ?>"><?= ucfirst($status) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="addBatchModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Add New Batch</h2>
            <form id="addBatchForm">
                <div class="form-group">
                    <label>Product *</label>
                    <select name="product_id" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Batch Number *</label>
                    <input type="text" name="batch_number" required placeholder="e.g., BATCH-2024-001">
                </div>
                <div class="form-group">
                    <label>Expiry Date</label>
                    <input type="date" name="expiry_date">
                </div>
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="quantity" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Cost Price</label>
                    <input type="number" name="cost_price" step="0.01" value="0">
                </div>
                <div style="margin-top: 24px; text-align: right;">
                    <button type="button" onclick="closeAddBatchModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Batch</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddBatchModal() {
            document.getElementById('addBatchModal').style.display = 'block';
        }
        
        function closeAddBatchModal() {
            document.getElementById('addBatchModal').style.display = 'none';
            document.getElementById('addBatchForm').reset();
        }
        
        document.getElementById('addBatchForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/batches.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Batch added successfully!');
                    location.reload();
                } else {
                    throw new Error(result.message || 'Failed to add batch');
                }
            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.textContent = 'Add Batch';
            }
        });
        
        window.onclick = function(event) {
            const modal = document.getElementById('addBatchModal');
            if (event.target == modal) {
                closeAddBatchModal();
            }
        }
    </script>
</body>
</html>

