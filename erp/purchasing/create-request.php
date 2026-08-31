<?php
require_once '../../includes/functions.php';

global $pdo;
// Get products for suggestions
$products = $pdo->query("SELECT id, name, unit, cost_price as estimated_cost FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase Request - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #1f2937; }
        
        .page-wrapper { margin-left: 220px; min-height: 100vh; padding: 24px 40px; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; padding: 16px; } }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 1.5rem; font-weight: 600; color: #111827; }
        .header-actions { display: flex; gap: 12px; }
        
        .card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); overflow: hidden; height: 100%; margin-bottom: 24px; }
        .card-header-title { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-weight: 600; font-size: 0.95rem; color: #374151; background: #fafafa; }
        .card-body { padding: 20px; }
        
        .items-container { background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 24px; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th { background: #f8f9fa; text-align: left; padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: #4b5563; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .items-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .items-table tr:last-child td { border-bottom: none; }
        
        .btn { padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 0.9rem; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: #2563eb; color: white; border-color: #2563eb; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-add { background: #f3f4f6; color: #4b5563; border: 1px dashed #d1d5db; width: 100%; border-radius: 0 0 8px 8px; justify-content: flex-start; padding-left: 16px; margin-top: 0; }
        
        .btn-delete { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #fee2e2; color: #ef4444; border: none; border-radius: 4px; cursor: pointer; }
        
        input, select, textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
        
        /* Search */
        .search-dropdown { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e2e8f0; border-radius: 6px; max-height: 200px; overflow-y: auto; z-index: 50; display: none; }
        .search-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .search-item:hover { background: #f8fafc; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="page-wrapper">
        <form id="createPRForm">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Create Purchase Request</h1>
                <div class="header-actions">
                    <a href="requests.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
                </div>
            </div>

            <div class="card">
                 <div class="card-body">
                    <label style="display:block; margin-bottom:8px; font-weight:500;">Justification / Notes</label>
                    <textarea name="notes" rows="3" placeholder="Explain why these items are needed..." required></textarea>
                 </div>
            </div>

            <!-- Items Section -->
            <div class="items-container">
                <table class="items-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Product / Item Name</th>
                            <th style="width: 15%;">Quantity</th>
                            <th style="width: 15%;">Unit</th>
                            <th style="width: 15%;">Est. Cost</th>
                            <th style="width: 15%;">Total</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <!-- Items added via JS -->
                    </tbody>
                </table>
                <button type="button" class="btn btn-add" onclick="addItem()">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>
            
            <div style="text-align: right; font-size: 1.1rem; font-weight: 600; margin-top: 16px;">
                Total Estimated Cost: <span id="totalDisplay">0.00</span>
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
                        <input type="text" name="items[${index}][product_name]" placeholder="Search or type item name..." oninput="searchProduct(this, ${index})" class="product-search" required autocomplete="off">
                        <input type="hidden" name="items[${index}][product_id]" class="product-id">
                        <div class="search-results" id="results-${index}"></div>
                    </div>
                </td>
                <td>
                    <input type="number" name="items[${index}][quantity]" value="1" min="0.1" step="0.1" onchange="calculateRow(${index})" class="qty-input" required>
                </td>
                <td>
                    <input type="text" name="items[${index}][unit]" value="pcs" class="unit-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][estimated_unit_cost]" value="0.00" min="0" step="0.01" onchange="calculateRow(${index})" class="cost-input">
                </td>
                <td>
                    <input type="number" value="0.00" readonly style="background: #f8f9fa;" class="total-input">
                </td>
                <td style="text-align: center;">
                    <button type="button" class="btn-delete" onclick="this.closest('tr').remove(); calculateTotals();">
                        <i class="fas fa-trash-alt"></i>
                    </button>
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
            
            const matches = products.filter(p => p.name.toLowerCase().includes(term));
            
            resultsDiv.innerHTML = '';
            if (matches.length > 0) {
                matches.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'search-item';
                    div.innerHTML = `${p.name} <span style='color:#6b7280; font-size:0.8em'>(${p.unit})</span>`;
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
            row.querySelector('.unit-input').value = product.unit || 'pcs';
            row.querySelector('.cost-input').value = product.estimated_cost || 0;
            document.getElementById(`results-${index}`).style.display = 'none';
            calculateRow(index);
        }
        
        function calculateRow(index) {
            const row = document.getElementById('itemsBody').children[index];
            if (!row) return;
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const cost = parseFloat(row.querySelector('.cost-input').value) || 0;
            row.querySelector('.total-input').value = (qty * cost).toFixed(2);
            calculateTotals();
        }
        
        function calculateTotals() {
            let total = 0;
            document.querySelectorAll('#itemsBody tr').forEach(row => {
               total += parseFloat(row.querySelector('.total-input').value) || 0;
            });
            document.getElementById('totalDisplay').textContent = total.toFixed(2);
        }
        
        addItem();
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-dropdown')) {
                document.querySelectorAll('.search-results').forEach(el => el.style.display = 'none');
            }
        });

        document.getElementById('createPRForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = 'Submitting...';
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                const response = await fetch('../api/purchase_requests.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    alert('Request successfully submitted!');
                    window.location.href = 'requests.php'; // Will create this next
                } else {
                    alert('Failed: ' + result.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                }
            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>
