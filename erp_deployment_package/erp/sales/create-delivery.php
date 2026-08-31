<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$customers = $pdo->query("SELECT id, name, address FROM erp_customers ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, sku FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();

// Pre-fill from invoice if invoice_id is present
$invoiceId = $_GET['invoice_id'] ?? null;
$prefillData = [];
if ($invoiceId) {
    $stmt = $pdo->prepare("SELECT i.*, c.address as customer_address FROM erp_invoices i JOIN erp_customers c ON i.customer_id = c.id WHERE i.id = ?");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
    
    if ($invoice) {
        $prefillData['customer_id'] = $invoice['customer_id'];
        $prefillData['shipping_address'] = $invoice['customer_address'];
        $prefillData['invoice_id'] = $invoiceId;
        
        // Get invoice items
        $stmt = $pdo->prepare("SELECT * FROM erp_invoice_items WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);
        $prefillData['items'] = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Delivery Note - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 1000px; margin: 24px auto; padding: 0 24px; }
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-body { padding: 24px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; display: none; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
        .line-items { margin: 20px 0; }
        .line-item { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; margin-bottom: 12px; align-items: end; }
        .section-title { grid-column: span 2; font-size: 1.1rem; font-weight: 600; margin: 20px 0 10px 0; border-bottom: 1px solid #f1f3f4; padding-bottom: 8px; color: #1a73e8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>New Delivery Note</h1>
        <a href="delivery-notes.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="createDeliveryForm">
                    <?php if (!empty($prefillData['invoice_id'])): ?>
                        <input type="hidden" name="invoice_id" value="<?= $prefillData['invoice_id'] ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="section-title" style="margin-top: 0;">Customer Information</div>
                        
                        <div class="form-group">
                            <label>Customer *</label>
                            <select name="customer_id" required onchange="updateAddress(this)">
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" 
                                        data-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                                        <?= (isset($prefillData['customer_id']) && $prefillData['customer_id'] == $c['id']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Delivery Date *</label>
                            <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Shipping Address</label>
                            <textarea name="shipping_address" rows="2" id="shippingAddress"><?= htmlspecialchars($prefillData['shipping_address'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="section-title">Delivery Details</div>
                        
                        <div class="form-group">
                            <label>Driver Name</label>
                            <input type="text" name="driver_name">
                        </div>
                        
                        <div class="form-group">
                            <label>Vehicle Number</label>
                            <input type="text" name="vehicle_number" placeholder="e.g., T 123 ABC">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <h3 style="margin: 20px 0 12px 0;">Items to Deliver</h3>
                    <div id="lineItems" class="line-items"></div>
                    <button type="button" onclick="addLine()" class="btn btn-secondary">+ Add Item</button>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Create Delivery Note</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const products = <?= json_encode($products) ?>;
        let lineCount = 0;
        
        // Prefill items if available
        const prefillItems = <?= json_encode($prefillData['items'] ?? []) ?>;
        
        function updateAddress(select) {
            const option = select.options[select.selectedIndex];
            const address = option.getAttribute('data-address');
            document.getElementById('shippingAddress').value = address || '';
        }
        
        function addLine(item = null) {
            const div = document.createElement('div');
            div.className = 'line-item';
            
            const productId = item ? item.product_id : '';
            const quantity = item ? item.quantity : 1;
            
            div.innerHTML = `
                <select name="items[${lineCount}][product_id]" required onchange="loadBatches(this, ${lineCount})">
                    <option value="">Select Product</option>
                    ${products.map(p => `<option value="${p.id}" ${p.id == productId ? 'selected' : ''}>${p.name} (${p.sku})</option>`).join('')}
                </select>
                <input type="number" name="items[${lineCount}][quantity]" step="0.01" placeholder="Quantity" required value="${quantity}">
                <select name="items[${lineCount}][batch_number]" id="batch_${lineCount}">
                    <option value="">No Batch</option>
                </select>
                <button type="button" onclick="this.parentElement.remove();" class="btn btn-secondary" style="padding: 10px;">Ã—</button>
            `;
            document.getElementById('lineItems').appendChild(div);
            
            // If prefilling, trigger batch load
            if (productId) {
                const select = div.querySelector('select[name^="items"]');
                loadBatches(select, lineCount);
            }
            
            lineCount++;
        }
        
        async function loadBatches(select, index) {
            const productId = select.value;
            const batchSelect = document.getElementById('batch_' + index);
            
            if (!productId) {
                batchSelect.innerHTML = '<option value="">No Batch</option>';
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_batches');
                formData.append('product_id', productId);
                
                const response = await fetch('../api/batches.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.batches.length > 0) {
                    batchSelect.innerHTML = '<option value="">Select Batch</option>' +
                        result.batches.map(b => `<option value="${b.batch_number}">
                            ${b.batch_number} (Qty: ${b.quantity}${b.expiry_date ? ', Exp: ' + b.expiry_date : ''})
                        </option>`).join('');
                } else {
                    batchSelect.innerHTML = '<option value="">No batches available</option>';
                }
            } catch (error) {
                console.error('Error loading batches:', error);
            }
        }
        
        // Initialize lines
        if (prefillItems.length > 0) {
            prefillItems.forEach(item => addLine(item));
        } else {
            addLine();
        }
        
        document.getElementById('createDeliveryForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/delivery-notes.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Delivery note created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'view-delivery.php?id=' + result.id, 1500);
                } else {
                    throw new Error(result.message || 'Failed to create delivery note');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Create Delivery Note';
            }
        });
    </script>
</body>
</html>

