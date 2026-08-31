<?php
require_once '../../includes/functions.php';

global $pdo;

// Generate next invoice number
$stmt = $pdo->query("SELECT invoice_number FROM erp_invoices ORDER BY id DESC LIMIT 1");
$lastInv = $stmt->fetchColumn();

if ($lastInv) {
    // format INV-YYYY-XXXX
    $parts = explode('-', $lastInv);
    if (count($parts) == 3 && $parts[1] == date('Y')) {
        $num = intval($parts[2]) + 1;
        $nextInv = 'INV-' . date('Y') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    } else {
        $nextInv = 'INV-' . date('Y') . '-0001';
    }
} else {
    $nextInv = 'INV-' . date('Y') . '-0001';
}

// Get customers for dropdown
$customers = $pdo->query("SELECT id, name, customer_code FROM erp_customers WHERE status = 'active' ORDER BY name")->fetchAll();

// Get products for JS
$products = $pdo->query("SELECT id, name, sku, unit_price, unit, description FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Invoice - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #1f2937; }
        
        /* Layout & Container */
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; padding: 10px !important; width: 100% !important; } }
        
        /* Page Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-size: 1.5rem; font-weight: 600; color: #111827; }
        .header-actions { display: flex; gap: 12px; }
        
        /* Cards */
        .card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); overflow: hidden; height: 100%; }
        .card-header-title { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-weight: 600; font-size: 0.95rem; color: #374151; background: #fafafa; }
        .card-body { padding: 20px; }
        
        /* Top Section Grid */
        .top-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 1024px) { .top-grid { grid-template-columns: 1fr; } }
        
        /* Form Elements */
        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.85rem; color: #4b5563; }
        input, select, textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; transition: border-color 0.15s; }
        input:focus, select:focus, textarea:focus { border-color: #2563eb; outline: none; ring: 2px solid rgba(37,99,235,0.1); }
        input[readonly] { background-color: #f9fafb; cursor: default; }

        /* Invoice Details Grid within Card */
        .details-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        
        /* Items Table */
        .items-container { background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 24px; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th { background: #f8f9fa; text-align: left; padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: #4b5563; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .items-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .items-table tr:last-child td { border-bottom: none; }
        
        /* Delete Button */
        .btn-delete { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #fee2e2; color: #ef4444; border: none; border-radius: 4px; cursor: pointer; transition: background 0.2s; }
        .btn-delete:hover { background: #fecaca; }
        
        /* Buttons */
        .btn { padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 0.9rem; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; }
        .btn-primary { background: #2563eb; color: white; border-color: #2563eb; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-secondary:hover { background: #f3f4f6; border-color: #9ca3af; }
        .btn-add { background: #f3f4f6; color: #4b5563; border: 1px dashed #d1d5db; width: 100%; margin-top: 0; border-radius: 0 0 8px 8px; justify-content: flex-start; padding-left: 16px; }
        .btn-add:hover { background: #e5e7eb; color: #111827; }
        
        /* Footer & Totals */
        .invoice-footer { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 24px; gap: 40px; }
        .notes-section { flex: 1; }
        .totals-section { width: 300px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; }
        
        .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9rem; color: #4b5563; }
        .total-row.final { margin-top: 16px; padding-top: 16px; border-top: 2px solid #f1f5f9; color: #111827; font-weight: 700; font-size: 1.1rem; margin-bottom: 0; }
        
        /* Search */
        .search-dropdown { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e2e8f0; border-radius: 6px; max-height: 200px; overflow-y: auto; z-index: 50; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: none; margin-top: 4px; }
        .search-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .search-item:hover { background: #f8fafc; }
        .search-item-meta { font-size: 0.75rem; color: #6b7280; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="page-wrapper">
        <form id="invoiceForm">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Create New Invoice</h1>
                <div class="header-actions">
                    <a href="invoices.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Invoice</button>
                </div>
            </div>

            <!-- Top Section -->
            <div class="top-grid">
                <!-- Customer Info Card -->
                <div class="card">
                    <div class="card-header-title">Customer Info</div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Customer *</label>
                            <select name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $cust): ?>
                                    <option value="<?= $cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?> (<?= $cust['customer_code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Invoice Details Card -->
                <div class="card">
                    <div class="card-header-title">Invoice Details</div>
                    <div class="card-body">
                        <div class="details-grid">
                            <div class="form-group">
                                <label>Invoice Number</label>
                                <input type="text" name="invoice_number" value="<?= $nextInv ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Invoice Date *</label>
                                <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Due Date</label>
                                <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Section -->
            <div class="items-container">
                <table class="items-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Item / Description</th>
                            <th style="width: 15%;">Quantity</th>
                            <th style="width: 15%;">Unit Price</th>
                            <th style="width: 10%;">Tax %</th>
                            <th style="width: 15%;">Total</th>
                            <th style="width: 10%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <!-- Items added via JS -->
                    </tbody>
                </table>
                <button type="button" class="btn btn-add" onclick="addItem()">
                    <i class="fas fa-plus"></i> Add Line Item
                </button>
            </div>

            <!-- Footer & Totals -->
            <div class="invoice-footer">
                <div class="notes-section">
                    <div class="card" style="height: auto;">
                        <div class="card-body">
                            <label>Notes / Terms</label>
                            <textarea name="notes" rows="4" placeholder="Enter payment terms, delivery notes, or any other remarks..."></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="totals-section">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span id="subtotalDisplay">0.00</span>
                    </div>
                    <div class="total-row">
                        <span>Tax Amount:</span>
                        <span id="taxDisplay">0.00</span>
                    </div>
                    <div class="total-row final">
                        <span>Total:</span>
                        <span id="totalDisplay">0.00</span>
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
                    <textarea name="items[${index}][description]" placeholder="Description" rows="1" style="margin-top: 4px; font-size: 0.8rem;"></textarea>
                </td>
                <td>
                    <input type="number" name="items[${index}][quantity]" value="1" min="0.01" step="0.01" onchange="calculateRow(${index})" class="qty-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][unit_price]" value="0.00" min="0" step="0.01" onchange="calculateRow(${index})" class="price-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][tax_rate]" value="0" min="0" step="0.01" onchange="calculateRow(${index})" class="tax-input">
                </td>
                <td>
                    <input type="number" name="items[${index}][total]" value="0.00" readonly style="background: #f8f9fa;" class="total-input">
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
                            <span>TSh ${parseFloat(p.unit_price).toFixed(2)}</span>
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
            row.querySelector('textarea').value = product.description || product.name;
            row.querySelector('.price-input').value = product.unit_price;
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
            let subtotal = 0;
            let totalTax = 0;
            
            document.querySelectorAll('#itemsBody tr').forEach(row => {
                const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
                const taxRate = parseFloat(row.querySelector('.tax-input').value) || 0;
                
                const lineTotal = qty * price;
                const lineTax = lineTotal * (taxRate / 100);
                
                subtotal += lineTotal;
                totalTax += lineTax;
            });
            
            const total = subtotal + totalTax;
            
            document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
            document.getElementById('taxDisplay').textContent = totalTax.toFixed(2);
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

        document.getElementById('invoiceForm').addEventListener('submit', async function(e) {
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
                
                const response = await fetch('../api/invoices.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Invoice created successfully!');
                    window.location.href = 'view-invoice.php?id=' + result.id;
                } else {
                    throw new Error(result.message || 'Failed to create invoice');
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

