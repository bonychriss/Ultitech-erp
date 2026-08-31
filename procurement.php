<?php
require_once 'includes/functions.php';
requireLogin();

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_receipt'])) {
    $item_name = trim($_POST['item_name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $supplier = trim($_POST['supplier_name'] ?? '');
    $po_number = trim($_POST['po_number'] ?? '');
    $batch = trim($_POST['batch_number'] ?? '');
    $location = trim($_POST['warehouse_location'] ?? '');
    $expiry = $_POST['expiry_date'] ?: null;
    $qty = (int)($_POST['quantity'] ?? 0);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $currency = $_POST['currency'] ?? 'TZS';
    $date_received = $_POST['date_received'] ?: date('Y-m-d');
   
    // File Upload Handling
    $attachment_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/grn/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $_FILES['attachment']['name']);
        $dest = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
            $attachment_path = $dest;
        }
    }

    // Server-Side Math
    $total_cost = $qty * $unit_cost;

    if ($item_name && $qty > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO stock_intakes 
                (item_name, sku, category, supplier_name, po_number, batch_number, warehouse_location, expiry_date, quantity, unit_cost, total_cost, currency, attachment_path, date_received) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([$item_name, $sku, $category, $supplier, $po_number, $batch, $location, $expiry, $qty, $unit_cost, $total_cost, $currency, $attachment_path, $date_received]);
            $success = "Stock intake recorded successfully.";
        } catch (Exception $e) {
            $error = "Failed to save: " . $e->getMessage();
        }
    } else {
        $error = "Item Name and Quantity are required.";
    }
}

// Fetch 5 Recent Intakes
$history = $pdo->query("SELECT * FROM stock_intakes ORDER BY id DESC LIMIT 5")->fetchAll();

// Top Stats
$totalValue = $pdo->query("SELECT SUM(total_cost) FROM stock_intakes")->fetchColumn() ?: 0;
$todayCount = $pdo->query("SELECT COUNT(*) FROM stock_intakes WHERE date_received = CURDATE()")->fetchColumn() ?: 0;
$lowStockCount = 12; // Static mock as requested

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STOCK MANAGEMENT - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #0F172A; /* Slate 900 */
            --secondary: #334155; /* Slate 700 */
            --accent: #2563EB; /* Blue 600 */
            --bg: #F8FAFC; /* Slate 50 */
            --card-bg: #FFFFFF;
            --border: #E2E8F0;
            --success-bg: #DCFCE7;
            --success-text: #166534;
            --danger-text: #EF4444;
        }

        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); margin: 0; color: var(--primary); -webkit-font-smoothing: antialiased; }
        * { box-sizing: border-box; }

        .main-container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        @media (min-width: 1024px) {
            .main-container {
                margin-left: 260px;
                max-width: calc(100% - 260px);
            }
        }

        /* HEADER */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header-title h1 { margin: 0; font-size: 24px; font-weight: 700; color: var(--primary); letter-spacing: -0.02em; }
        .header-title p { margin: 4px 0 0 0; font-size: 14px; color: #64748B; font-weight: 400; }
        
        .btn-hub {
            display: inline-flex; align-items: center; gap: 8px;
            background: white; border: 1px solid var(--border);
            padding: 8px 16px; border-radius: 8px;
            color: var(--secondary); text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn-hub:hover { border-color: #cbd5e1; background: #f1f5f9; color: var(--primary); }

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: var(--card-bg); padding: 24px; border-radius: 12px;
            border: 1px solid var(--border);
            display: flex; flex-direction: column; justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .stat-label { font-size: 13px; font-weight: 600; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 700; color: var(--primary); letter-spacing: -0.02em; }

        @media (max-width: 768px) {
            .stats-grid { gap: 8px; }
            .stat-card { padding: 8px; border-radius: 0; } /* Sharp corners */
            .stat-label { font-size: 9px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .stat-value { font-size: 14px; }
            .main-container { padding: 15px; }
            .header { margin-bottom: 20px; }
            .header-title h1 { font-size: 18px; }
        }
        
        /* CONTENT CARD */
        .card { background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { 
            padding: 20px 24px; border-bottom: 1px solid var(--border); 
            display: flex; justify-content: space-between; align-items: center;
            background: white;
        }
        .card-title { font-size: 16px; font-weight: 600; color: var(--primary); }

        /* TABLE */
        .table-container { overflow-x: auto; }
        .recent-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .recent-table th { 
            text-align: left; font-size: 12px; font-weight: 600; color: #64748B; 
            padding: 16px 24px; background: #F8FAFC; border-bottom: 1px solid var(--border);
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .recent-table td { 
            padding: 16px 24px; font-size: 14px; border-bottom: 1px solid var(--border); 
            color: var(--secondary); vertical-align: middle;
        }
        .recent-table tr:last-child td { border-bottom: none; }
        .recent-table tr:hover td { background: #F8FAFC; }
        
        .item-main { font-weight: 600; color: var(--primary); margin-bottom: 2px; }
        .item-sub { font-size: 12px; color: #94A3B8; display: flex; align-items: center; gap: 6px; }
        
        /* FORM & MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(2px); z-index: 1000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .modal-overlay.show { opacity: 1; }
        .modal-content { background: white; width: 100%; max-width: 600px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); transform: scale(0.95); transition: transform 0.2s; max-height: 90vh; overflow-y: auto; }
        .modal-overlay.show .modal-content { transform: scale(1); }
        
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 18px; font-weight: 600; color: var(--primary); margin: 0; }
        .close-btn { background: transparent; border: none; color: #94A3B8; cursor: pointer; padding: 4px; transition: color 0.2s; }
        .close-btn:hover { color: var(--danger-text); }
        
        .form-body { padding: 24px; }
        .form-section { margin-bottom: 24px; }
        .section-label { font-size: 12px; font-weight: 600; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; display: block; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .col-full { grid-column: span 2; }
        
        .input-group label { display: block; font-size: 13px; font-weight: 500; color: var(--secondary); margin-bottom: 6px; }
        .form-input { 
            width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; 
            font-size: 14px; color: var(--primary); transition: all 0.2s; outline: none;
            background: white;
        }
        .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .form-input.readonly { background: #F1F5F9; color: #64748B; cursor: default; }

        .btn-primary { 
            background: var(--primary); color: white; border: none; padding: 12px; 
            width: 100%; border-radius: 8px; font-weight: 600; font-size: 14px; 
            cursor: pointer; transition: background 0.2s; margin-top: 10px;
        }
        .btn-primary:hover { background: #1E293B; }
        
        .action-btn { 
            background: white; border: 1px solid var(--border); color: var(--secondary);
            padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500;
            cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .action-btn:hover { background: #F8FAFC; border-color: #CBD5E1; color: var(--primary); }
    </style>
</head>
<body class="dashboard">
    <?php require_once 'includes/header_employee.php'; ?>

    <div class="main-container">
        
        <div class="header">
            <div class="header-title">
                <h1>Stock Management</h1>
                <p>Internal Goods Receipt System</p>
            </div>
            <div>
                <a href="select-module.php" class="btn-hub">
                    <i class="fas fa-th"></i> Hub
                </a>
            </div>
        </div>

        <?php if($success): ?><div style="padding:16px; background:var(--success-bg); color:var(--success-text); border-radius:8px; margin-bottom:24px; font-weight: 500; border: 1px solid #bbf7d0;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div style="padding:16px; background:#fee2e2; color:#b91c1c; border-radius:8px; margin-bottom:24px; font-weight: 500; border: 1px solid #fecaca;"><?= $error ?></div><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Stock Value</div>
                <div class="stat-value">$<?= number_format($totalValue, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Items Received Today</div>
                <div class="stat-value"><?= $todayCount ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Low Stock Alerts</div>
                <div class="stat-value" style="color:var(--danger-text);"><?= $lowStockCount ?></div>
            </div>
        </div>

        <!-- RECENT HISTORY -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Recent Intakes History</div>
            </div>
            <div class="table-container">
                <table class="recent-table">
                        <thead>
                            <tr>
                                <th>Item / SKU</th>
                                <th>Qty</th>
                                <th>Cost</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history as $h): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($h['item_name']) ?></div>
                                        <div style="font-size:10px; color:#888;">
                                            <?= htmlspecialchars($h['sku'] ?: 'N/A') ?> 
                                            <?php if($h['attachment_path']): ?>
                                                &bull; <i class="fas fa-paperclip" style="color:var(--primary);"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="color:var(--success); font-weight:700;">+<?= number_format($h['quantity']) ?></td>
                                    <td style="font-size: 11px; white-space: nowrap;">
                                        <?= htmlspecialchars($h['currency'] ?? 'TZS') ?> <?= number_format($h['total_cost'], 2) ?>
                                    </td>
                                    <td>
                                        <button class="action-btn" onclick='viewIntake(<?= json_encode($h) ?>)' title="View Details">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                             <?php if(empty($history)): ?>
                                <tr><td colspan="4" style="text-align:center; padding:40px; color:#aaa;">No intakes recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="padding:15px; text-align:center; border-top: 1px solid var(--border);">
                    <span style="font-size:12px; color:#64748B;">Showing latest 5 records</span>
                </div>
            </div>
        </div>

    </div>

    <!-- INTAKE FORM MODAL (HIDDEN) -->
    <div id="intake-modal" class="modal-overlay" onclick="if(event.target === this) closeModal()">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Stock Intake</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="form-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="confirm_receipt" value="1">
                    
                    <div class="form-section">
                        <div class="section-title">1. Item Identification</div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Item Name *</label>
                                <input type="text" name="item_name" class="form-input" required placeholder="Full product name">
                            </div>
                            <div class="form-group">
                                <label>SKU / Product Code</label>
                                <input type="text" name="sku" class="form-input" placeholder="e.g. ITEM-001">
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <input type="text" name="category" class="form-input" placeholder="e.g. Electronics">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">2. Source & Logistics</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Supplier Name</label>
                                <input type="text" name="supplier_name" class="form-input" placeholder="Vendor Co.">
                            </div>
                            <div class="form-group">
                                <label>PO Number</label>
                                <input type="text" name="po_number" class="form-input" placeholder="Purchase Order Ref">
                            </div>
                            <div class="form-group">
                                <label>Batch Number</label>
                                <input type="text" name="batch_number" class="form-input" placeholder="Lot/Batch #">
                            </div>
                            <div class="form-group">
                                <label>Date Received</label>
                                <input type="date" name="date_received" class="form-input" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">3. Finance & Quantity</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Currency</label>
                                <select name="currency" class="form-input" id="currency" onchange="calcTotal()">
                                    <option value="TZS">TZS</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Quantity Received *</label>
                                <input type="number" name="quantity" id="qty" class="form-input" placeholder="0" required oninput="calcTotal()">
                            </div>
                            <div class="form-group">
                                <label>Unit Cost *</label>
                                <input type="number" name="unit_cost" id="cost" class="form-input" placeholder="0.00" step="0.01" required oninput="calcTotal()">
                            </div>
                            <div class="form-group">
                                <label>Total Cost</label>
                                <input type="text" id="total" class="form-input readonly" placeholder="0.00" style="font-weight:bold; color:var(--primary);">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">4. Storage & Attachments</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Warehouse Location</label>
                                <input type="text" name="warehouse_location" class="form-input" placeholder="e.g. Shelf A-404">
                            </div>
                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-input">
                            </div>
                            <div class="form-group full-width">
                                <label>Attachment (Invoice/GRN/Receipt)</label>
                                <input type="file" name="attachment" class="form-input" accept=".pdf,.png,.jpg,.jpeg">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Confirm Stock Receipt</button>
                </form>
            </div>
        </div>
    </div>

    <!-- VIEW DETAILS MODAL -->
    <div id="view-modal" class="modal-overlay" onclick="if(event.target === this) closeViewModal()">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Intake Details</h3>
                <button class="close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="form-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; font-size:13px;">
                    <div><span style="color:#64748B; display:block; font-size:11px;">Item Name</span> <strong id="v_item"></strong></div>
                    <div><span style="color:#64748B; display:block; font-size:11px;">SKU</span> <strong id="v_sku"></strong></div>
                    
                    <div><span style="color:#64748B; display:block; font-size:11px;">Supplier</span> <strong id="v_supplier"></strong></div>
                    <div><span style="color:#64748B; display:block; font-size:11px;">PO Number</span> <strong id="v_po"></strong></div>
                    
                    <div><span style="color:#64748B; display:block; font-size:11px;">Date Received</span> <strong id="v_date"></strong></div>
                    <div><span style="color:#64748B; display:block; font-size:11px;">Category</span> <strong id="v_category"></strong></div>
                    
                    <div style="grid-column:span 2; border-top:1px solid #eee; padding-top:10px; margin-top:5px;"></div>
                    
                    <div><span style="color:#64748B; display:block; font-size:11px;">Quantity</span> <strong id="v_qty" style="color:var(--success);"></strong></div>
                    <div><span style="color:#64748B; display:block; font-size:11px;">Total Cost</span> <strong id="v_cost"></strong></div>
                    
                    <div style="grid-column:span 2; background:#F8FAFC; padding:10px; border-radius:6px;">
                        <span style="color:#64748B; display:block; font-size:11px;">Storage Location</span> 
                        <strong id="v_loc"></strong>
                        <br>
                        <span style="color:#64748B; font-size:11px;">Batch:</span> <span id="v_batch"></span> &bull; 
                        <span style="color:#64748B; font-size:11px;">Expiry:</span> <span id="v_expiry"></span>
                    </div>
                </div>
                
                <div id="v_attachment_container" style="margin-top:20px; text-align:center; display:none;">
                    <a id="v_attachment_link" href="#" target="_blank" class="btn-submit" style="display:inline-block; text-decoration:none; width:auto; background:#fff; border:1px solid var(--border); color:var(--primary);">
                        <i class="fas fa-paperclip"></i> View Attached Document
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('intake-modal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('intake-modal').style.display = 'none';
        }

        // View Modal Logic
        function viewIntake(data) {
            document.getElementById('v_item').textContent = data.item_name;
            document.getElementById('v_sku').textContent = data.sku || 'N/A';
            document.getElementById('v_supplier').textContent = data.supplier_name || 'N/A';
            document.getElementById('v_po').textContent = data.po_number || 'N/A';
            document.getElementById('v_date').textContent = data.date_received;
            document.getElementById('v_category').textContent = data.category || 'N/A';
            document.getElementById('v_qty').textContent = data.quantity;
            
            // Format currency
            let curr = data.currency || 'TZS';
            let cost = parseFloat(data.total_cost).toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('v_cost').textContent = curr + ' ' + cost;
            
            document.getElementById('v_loc').textContent = data.warehouse_location || 'Not Assigned';
            document.getElementById('v_batch').textContent = data.batch_number || 'N/A';
            document.getElementById('v_expiry').textContent = data.expiry_date || 'N/A';
            
            // Attachment
            if(data.attachment_path) {
                document.getElementById('v_attachment_container').style.display = 'block';
                document.getElementById('v_attachment_link').href = data.attachment_path;
            } else {
                document.getElementById('v_attachment_container').style.display = 'none';
            }
            
            document.getElementById('view-modal').style.display = 'flex';
        }
        function closeViewModal() {
            document.getElementById('view-modal').style.display = 'none';
        }

        function calcTotal() {
            let qty = parseFloat(document.getElementById('qty').value) || 0;
            let cost = parseFloat(document.getElementById('cost').value) || 0;
            let total = qty * cost;
            let curr = document.getElementById('currency').value;
            document.getElementById('total').value = curr + " " + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        
        // Auto-open if URL param exists
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('open_intake') === '1') {
            openModal();
        }
    </script>

</body>
</html>
