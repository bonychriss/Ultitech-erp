<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$customers = $pdo->query("SELECT id, name FROM erp_customers ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, unit_price FROM erp_products ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Quote - ERP</title>
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
        .line-item { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; margin-bottom: 12px; align-items: end; }
        .totals { background: #f8f9fa; padding: 16px; border-radius: 4px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .total-row.grand { font-size: 1.2rem; font-weight: 700; color: #1a73e8; border-top: 2px solid #e0e0e0; padding-top: 12px; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>New Quote</h1>
        <a href="quotes.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-body">
                <div id="alertMessage" class="alert"></div>
                
                <form id="createQuoteForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Customer *</label>
                            <select name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Quote Date *</label>
                            <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Expiry Date</label>
                            <input type="date" name="expiry_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Tax Rate (%)</label>
                            <input type="number" name="tax_rate" step="0.01" value="0" oninput="calculateTotals()">
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <h3 style="margin: 20px 0 12px 0;">Line Items</h3>
                    <div id="lineItems" class="line-items"></div>
                    <button type="button" onclick="addLine()" class="btn btn-secondary">+ Add Item</button>
                    
                    <div class="totals">
                        <div class="total-row">
                            <span>Subtotal:</span>
                            <span id="subtotal">0.00</span>
                        </div>
                        <div class="total-row">
                            <span>Tax:</span>
                            <span id="tax">0.00</span>
                        </div>
                        <div class="total-row grand">
                            <span>Total:</span>
                            <span id="total">0.00</span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Save Quote</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const products = <?= json_encode($products) ?>;
        let lineCount = 0;
        
        function addLine() {
            const div = document.createElement('div');
            div.className = 'line-item';
            div.innerHTML = `
                <select name="items[${lineCount}][product_id]" required onchange="updatePrice(this, ${lineCount})">
                    <option value="">Select Product</option>
                    ${products.map(p => `<option value="${p.id}" data-price="${p.unit_price}">${p.name}</option>`).join('')}
                </select>
                <input type="number" name="items[${lineCount}][quantity]" step="1" placeholder="Qty" required oninput="calculateTotals()" value="1">
                <input type="number" name="items[${lineCount}][unit_price]" step="0.01" placeholder="Price" required oninput="calculateTotals()" id="price_${lineCount}">
                <span id="item_total_${lineCount}" style="padding: 10px; font-weight: 600;">0.00</span>
                <button type="button" onclick="this.parentElement.remove(); calculateTotals();" class="btn btn-secondary" style="padding: 10px;">Ã—</button>
            `;
            document.getElementById('lineItems').appendChild(div);
            lineCount++;
        }
        
        function updatePrice(select, index) {
            const option = select.options[select.selectedIndex];
            const price = option.getAttribute('data-price');
            document.getElementById('price_' + index).value = price;
            calculateTotals();
        }
        
        function calculateTotals() {
            let subtotal = 0;
            document.querySelectorAll('.line-item').forEach((line, idx) => {
                const qty = parseFloat(line.querySelector('input[name*="[quantity]"]').value) || 0;
                const price = parseFloat(line.querySelector('input[name*="[unit_price]"]').value) || 0;
                const itemTotal = qty * price;
                const totalSpan = line.querySelector('span[id^="item_total_"]');
                if (totalSpan) totalSpan.textContent = itemTotal.toFixed(2);
                subtotal += itemTotal;
            });
            
            const taxRate = parseFloat(document.querySelector('input[name="tax_rate"]').value) || 0;
            const tax = subtotal * (taxRate / 100);
            const total = subtotal + tax;
            
            document.getElementById('subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('tax').textContent = tax.toFixed(2);
            document.getElementById('total').textContent = total.toFixed(2);
        }
        
        addLine();
        
        document.getElementById('createQuoteForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            const alert = document.getElementById('alertMessage');
            alert.style.display = 'none';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/quotes.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert.className = 'alert alert-success';
                    alert.textContent = 'Quote created successfully! Redirecting...';
                    alert.style.display = 'block';
                    setTimeout(() => window.location.href = 'view-quote.php?id=' + result.id, 1500);
                } else {
                    throw new Error(result.message || 'Failed to create quote');
                }
            } catch (error) {
                alert.className = 'alert alert-error';
                alert.textContent = error.message;
                alert.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Save Quote';
            }
        });
    </script>
</body>
</html>

