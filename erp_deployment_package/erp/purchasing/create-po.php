<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Generate next PO number
$stmt = $pdo->query("SELECT po_number FROM erp_purchase_orders ORDER BY id DESC LIMIT 1");
$lastPO = $stmt->fetchColumn();

if ($lastPO) {
    // format PO-YYYY-XXXX
    $parts = explode('-', $lastPO);
    if (count($parts) == 3 && $parts[1] == date('Y')) {
        $num = intval($parts[2]) + 1;
        $nextPO = 'PO-' . date('Y') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    } else {
        $nextPO = 'PO-' . date('Y') . '-0001';
    }
} else {
    $nextPO = 'PO-' . date('Y') . '-0001';
}

// Get suppliers
$suppliers = $pdo->query("SELECT id, name, supplier_code FROM erp_suppliers WHERE status = 'active' ORDER BY name")->fetchAll();

// Get products
$products = $pdo->query("SELECT id, name, sku, cost_price, unit FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase Order - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 1200px; margin: 24px auto; padding: 0 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .card-body { padding: 24px; }
        
        .form-row { display: flex; gap: 20px; margin-bottom: 16px; }
        .col { flex: 1; }
        
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px; }
        .items-table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; }
        .items-table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        .items-table input { border: 1px solid #e0e0e0; padding: 8px; }
        
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .btn-danger { background: #dc3545; color: white; padding: 6px 12px; font-size: 0.75rem; }
        
        .totals-area { display: flex; justify-content: flex-end; }
        .totals-box { width: 300px; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f3f4; }
        .total-row.final { border-top: 2px solid #e0e0e0; border-bottom: none; font-weight: 600; font-size: 1.1rem; margin-top: 8px; padding-top: 16px; }
        
        .search-dropdown { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #dadce0; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 100; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .search-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f3f4; }
        .search-item:hover { background: #f8f9fa; }
        .search-item-name { font-weight: 500; }
        .search-item-meta { font-size: 0.75rem; color: #5f6368; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <div class="header">
        <h1>New Purchase Order</h1>
        <a href="purchase-orders.php" class="btn btn-secondary">Cancel</a>
    </div>
    
    <div class="container">
        <form id="poForm">
            <div class="card">
                <div class="card-body">
                    <div class="form-row">
                        <div class="col">
                            <label>Supplier *</label>
                            <select name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?> (<?= $sup['supplier_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>PO Number</label>
                            <input type="text" name="po_number" value="<?= $nextPO ?>" readonly style="background: #f8f9fa;">
                        </div>
                        <div class="col">
                            <label>Order Date *</label>
                            <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col">
                            <label>Expected Date</label>
                            <input type="date" name="expected_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <h3>Items</h3>
                    <table class="items-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Product</th>
                                <th style="width: 15%;">Quantity</th>
                                <th style="width: 20%;">Unit Cost</th>
                                <th style="width: 20%;">Total</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <!-- Items will be added here -->
                        </tbody>
                    </table>
                    
                    <button type="button" class="btn btn-secondary" onclick="addItem()">+ Add Item</button>
                    
                    <div class="totals-area">
                        <div class="totals-box">
                            <div class="total-row final">
                                <span>Total Amount:</span>
                                <span id="totalDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Delivery instructions, etc."></textarea>
                    </div>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Create Purchase Order</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        const products = <?= json_encode($products) ?>;
        
        function addItem() {
            const tbody = document.getElementById('itemsBody');
            const row = document.createElement('tr');
            const index = tbody.children.length;
            
            row.innerHTML = `
                <td>
                    <div class="search-dropdown">
                        <input type="text" placeholder="Search product..." oninput="searchProduct(this, ${index})" class="product-search">
                        <input type="hidden" name="items[${index}][product_id]" class="product-id">
                        <div class="search-results" id="results-${index}"></div>
                    </div>
                </td>
                <td>
                    <input type="number" name="items[${index}][quantity]" value="1" min="0.01" step="0.01" onchange="calculateRow(${index})" class="qty-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][unit_price]" value="0.00" min="0" step="0.01" onchange="calculateRow(${index})" class="price-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][total]" value="0.00" readonly style="background: #f8f9fa;" class="total-input">
                </td>
                <td>
                    <button type="button" class="btn btn-danger" onclick="this.closest('tr').remove(); calculateTotals();">Ã—</button>
                </td>
            `;
            
            tbody.appendChild(row);
        }
        
        function searchProduct(input, index) {
            const term = input.value.toLowerCase();
            const resultsDiv = document.getElementById(`results-${index}`);
            
            if (term.length < 1) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            const matches = products.filter(p => 
                p.name.toLowerCase().includes(term) || 
                p.sku.toLowerCase().includes(term)
            );
            
            resultsDiv.innerHTML = '';
            
            if (matches.length > 0) {
                matches.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'search-item';
                    div.innerHTML = `
                        <div class="search-item-name">${p.name}</div>
                        <div class="search-item-meta">
                            <span>${p.sku}</span>
                            <span>Cost: ${parseFloat(p.cost_price).toFixed(2)}</span>
                        </div>
                    `;
                    div.onclick = () => selectProduct(p, index);
                    resultsDiv.appendChild(div);
                });
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.style.display = 'none';
            }
        }
        
        function selectProduct(product, index) {
            const row = document.getElementById('itemsBody').children[index];
            row.querySelector('.product-search').value = product.name;
            row.querySelector('.product-id').value = product.id;
            row.querySelector('.price-input').value = product.cost_price;
            document.getElementById(`results-${index}`).style.display = 'none';
            calculateRow(index);
        }
        
        function calculateRow(index) {
            const row = document.getElementById('itemsBody').children[index];
            if (!row) return;
            
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const total = qty * price;
            
            row.querySelector('.total-input').value = total.toFixed(2);
            calculateTotals();
        }
        
        function calculateTotals() {
            let total = 0;
            document.querySelectorAll('.total-input').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            
            document.getElementById('totalDisplay').textContent = total.toFixed(2);
        }
        
        // Add initial empty row
        addItem();
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-dropdown')) {
                document.querySelectorAll('.search-results').forEach(el => el.style.display = 'none');
            }
        });

        document.getElementById('poForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (document.querySelectorAll('#itemsBody tr').length === 0) {
                alert('Please add at least one item');
                return;
            }
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Creating...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/purchase-orders.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Purchase Order created successfully!');
                    window.location.href = 'view-po.php?id=' + result.id;
                } else {
                    throw new Error(result.message || 'Failed to create PO');
                }
            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
    </script>
</body>
</html>

