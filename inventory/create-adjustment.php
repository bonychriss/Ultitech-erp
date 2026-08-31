<?php
require_once '../../includes/functions.php';

global $pdo;

// Generate next adjustment number
$stmt = $pdo->query("SELECT adjustment_number FROM erp_inventory_adjustments ORDER BY id DESC LIMIT 1");
$lastAdj = $stmt->fetchColumn();

if ($lastAdj) {
    $num = intval(substr($lastAdj, 4)) + 1;
    $nextAdj = 'ADJ-' . str_pad($num, 4, '0', STR_PAD_LEFT);
} else {
    $nextAdj = 'ADJ-0001';
}

// Get products
$products = $pdo->query("SELECT id, name, sku, stock_quantity, unit FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Adjustment - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 100%; padding: 24px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-body { padding: 24px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full-width { grid-column: span 2; }
        
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #202124; font-size: 0.875rem; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 20px; }
        .items-table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; }
        .items-table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        .items-table input { border: 1px solid #e0e0e0; padding: 8px; }
        
        .btn { padding: 10px 24px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; margin-right: 10px; }
        .btn-danger { background: #dc3545; color: white; padding: 6px 12px; font-size: 0.75rem; }
        
        .search-dropdown { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #dadce0; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 100; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .search-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f3f4; }
        .search-item:hover { background: #f8f9fa; }
        .search-item-name { font-weight: 500; }
        .search-item-meta { font-size: 0.75rem; color: #5f6368; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><a href="adjustments.php" class="btn btn-secondary">Cancel</a></div>
    
    <div class="container">
        <form id="adjForm">
            <div class="card">
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Adjustment #</label>
                            <input type="text" name="adjustment_number" value="<?= $nextAdj ?>" readonly style="background: #f8f9fa;">
                        </div>
                        
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Reason</label>
                            <select name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="damage">Damage / Expired</option>
                                <option value="theft">Theft / Loss</option>
                                <option value="count_correction">Inventory Count Correction</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" name="notes" placeholder="Optional notes">
                        </div>
                    </div>
                    
                    <h3>Items to Adjust</h3>
                    <table class="items-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Product</th>
                                <th style="width: 20%;">Current Stock</th>
                                <th style="width: 20%;">Adjustment (+/-)</th>
                                <th style="width: 15%;">New Stock</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <!-- Items will be added here -->
                        </tbody>
                    </table>
                    
                    <button type="button" class="btn btn-secondary" onclick="addItem()">+ Add Item</button>
                    
                    <div style="margin-top: 24px; text-align: right;">
                        <button type="submit" class="btn btn-primary">Save Adjustment</button>
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
                    <input type="text" readonly class="current-stock" style="background: #f8f9fa; border: none; padding: 8px;">
                </td>
                <td>
                    <input type="number" name="items[${index}][quantity_change]" step="0.01" onchange="calculateRow(${index})" class="adj-input" placeholder="e.g. -5 or 10">
                </td>
                <td>
                    <span class="new-stock" style="font-weight: 500;">-</span>
                </td>
                <td>
                    <button type="button" class="btn btn-danger" onclick="this.closest('tr').remove();">Ã—</button>
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
                            <span>Stock: ${p.stock_quantity}</span>
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
            row.querySelector('.current-stock').value = product.stock_quantity;
            document.getElementById(`results-${index}`).style.display = 'none';
            calculateRow(index);
        }
        
        function calculateRow(index) {
            const row = document.getElementById('itemsBody').children[index];
            if (!row) return;
            
            const current = parseFloat(row.querySelector('.current-stock').value) || 0;
            const change = parseFloat(row.querySelector('.adj-input').value) || 0;
            const newStock = current + change;
            
            row.querySelector('.new-stock').textContent = newStock.toFixed(2);
        }
        
        // Add initial empty row
        addItem();
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-dropdown')) {
                document.querySelectorAll('.search-results').forEach(el => el.style.display = 'none');
            }
        });

        document.getElementById('adjForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (document.querySelectorAll('#itemsBody tr').length === 0) {
                alert('Please add at least one item');
                return;
            }
            
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                const response = await fetch('../api/adjustments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Adjustment saved successfully!');
                    window.location.href = 'adjustments.php';
                } else {
                    throw new Error(result.message || 'Failed to save adjustment');
                }
            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
    </script>
</div>
</body>
</html>

