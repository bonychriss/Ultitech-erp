<?php
require_once '../includes/functions.php';
requireLogin();
ensureStocksSchema();

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Create Purchase Order
    if ($action === 'create_po') {
        $supplier_id = (int)$_POST['supplier_id'];
        $po_number = trim($_POST['po_number']);
        $expected = $_POST['expected_delivery_date'] ?: null;
        $items = $_POST['items'] ?? []; 
        
        if ($supplier_id && $po_number && !empty($items)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO stocks_purchase_orders (po_number, supplier_id, status, expected_delivery_date, created_by) VALUES (?, ?, 'ordered', ?, ?)");
                $stmt->execute([$po_number, $supplier_id, $expected, $_SESSION['user_id']]);
                $poId = $pdo->lastInsertId();

                $stmtItem = $pdo->prepare("INSERT INTO stocks_po_items (po_id, item_id, qty_ordered, unit_cost) VALUES (?, ?, ?, ?)");
                foreach ($items as $i) {
                    if ((int)$i['item_id'] > 0 && (float)$i['qty'] > 0) {
                        $stmtItem->execute([$poId, (int)$i['item_id'], (float)$i['qty'], (float)$i['cost']]);
                    }
                }
                $pdo->commit();
                $success = "Purchase Order $po_number created.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to create PO: " . $e->getMessage();
            }
        } else {
            $error = "Missing required fields (Supplier, PO Number, Items).";
        }
    }

    // 2. Receive Stock (GRN)
    if ($action === 'receive_stock') {
        $po_id = (int)$_POST['po_id'];
        $receipts = $_POST['receipts'] ?? []; 

        $pdo->beginTransaction();
        try {
            $hasReceipt = false;
            foreach ($receipts as $r) {
                $qty = (float)$r['qty_received'];
                if ($qty <= 0) continue; 
                $hasReceipt = true;

                $itemId = (int)$r['item_id'];
                $batch = $r['batch'] ?? null;
                $expiry = $r['expiry'] ?: null;

                $stmt = $pdo->prepare("UPDATE stocks_po_items SET qty_received = qty_received + ? WHERE po_id = ? AND item_id = ?");
                $stmt->execute([$qty, $po_id, $itemId]);

                $stmt = $pdo->prepare("INSERT INTO stocks_transactions (item_id, type, quantity, reference_type, reference_id, batch_number, expiry_date, user_id) VALUES (?, 'in', ?, 'PO', ?, ?, ?, ?)");
                $stmt->execute([$itemId, $qty, $po_id, $batch, $expiry, $_SESSION['user_id']]);

                $stmt = $pdo->prepare("UPDATE stocks_items SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt->execute([$qty, $itemId]);
            }

            if ($hasReceipt) {
                $stmt = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'partial' WHERE id = ? AND status != 'received'");
                $stmt->execute([$po_id]);
                $pdo->commit();
                $success = "Stock received successfully.";
            } else {
                $pdo->rollBack();
                $error = "No quantities entered.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to receive stock: " . $e->getMessage();
        }
    }
}

// Fetch POs
$pos = $pdo->query("
    SELECT po.*, s.name as supplier_name, 
    (SELECT COUNT(*) FROM stocks_po_items WHERE po_id = po.id) as item_count,
    (SELECT SUM(qty_ordered * unit_cost) FROM stocks_po_items WHERE po_id = po.id) as total_value
    FROM stocks_purchase_orders po 
    JOIN stocks_suppliers s ON po.supplier_id = s.id 
    ORDER BY po.created_at DESC
")->fetchAll();

$suppliers = $pdo->query("SELECT * FROM stocks_suppliers ORDER BY name ASC")->fetchAll();
$items = $pdo->query("SELECT * FROM stocks_items ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Orders - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2C3E50;
            --accent: #F4B400;
            --bg: #F1F5F9;
            --card-bg: #FFFFFF;
            --text: #1E293B;
            --border: #E2E8F0;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: var(--text); }
        * { box-sizing: border-box; }

        .main-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.65); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:32px; border-radius:12px; width:800px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
        
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; font-size:12px; font-weight:700; color:#64748b; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.025em; }
        .form-control { width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; font-family:'Inter'; font-size:14px; transition:all 0.2s; background:#f8fafc; }
        .form-control:focus { outline:none; border-color:var(--accent); background:#fff; box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.1); }
        
        .card { background: var(--card-bg); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; }
        .card-header { padding: 15px 20px; border-bottom: 1px solid var(--border); background: #F8FAFC; font-weight: 600; font-size: 15px; color: var(--primary); display: flex; justify-content: space-between; align-items:center; }
        
        .header-dash { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header-dash h1 { margin: 0; font-size: 24px; color: var(--primary); }

        .btn { padding:10px 20px; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; border:none; transition:all 0.2s; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:#1a252f; }
        .btn-outline { background:transparent; border:1px solid #e2e8f0; color:#64748b; }
        .btn-outline:hover { background:#f8fafc; border-color:#cbd5e1; color:var(--text); }
        .btn-sm { padding:6px 12px; font-size:12px; }
        
        /* Table */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th { text-align:left; background:#f8fafc; padding:12px 20px; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #e2e8f0; }
        .data-table td { padding:14px 20px; font-size:14px; color:var(--text); border-bottom:1px solid #f1f5f9; }
        .data-table tr:hover { background:#f8fafc; }
        
        .status-badge { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; }
        
        .item-row { display:flex; gap:10px; margin-bottom:10px; align-items:center; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <div class="main-container">
        <div class="header-dash">
            <div>
                <h1>Procurement</h1>
                <span style="font-size:13px; color:#64748B;">Manage Purchase Orders and track deliveries</span>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <button class="btn btn-primary" onclick="openCreateModal()"><i class="fas fa-plus"></i> New PO</button>
            </div>
        </div>

        <?php if($success): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:12px; margin-bottom:20px; border:1px solid #bbf7d0;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:12px; margin-bottom:20px; border:1px solid #fecaca;"><?= $error ?></div><?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-clipboard-list"></i> Recent Purchase Orders</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Date Ordered</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pos)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">No purchase orders found.</td></tr>
                        <?php endif; ?>
                        <?php foreach($pos as $po): ?>
                        <tr>
                            <td><strong style="color:var(--primary);"><?= htmlspecialchars($po['po_number']) ?></strong></td>
                            <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                            <td>
                                <span class="status-badge" style="
                                    background: <?= $po['status']==='received'?'#dcfce7':($po['status']==='partial'?'#ffedd5':'#f1f5f9') ?>; 
                                    color: <?= $po['status']==='received'?'#166534':($po['status']==='partial'?'#9a3412':'#475569') ?>;
                                "><?= $po['status'] ?></span>
                            </td>
                            <td><?= date('d M Y', strtotime($po['created_at'])) ?></td>
                            <td><?= $po['item_count'] ?> items</td>
                            <td><strong><?= number_format($po['total_value'], 2) ?></strong></td>
                            <td style="text-align:right">
                                <?php if($po['status'] !== 'cancelled' && $po['status'] !== 'received'): ?>
                                    <button class="btn btn-outline btn-sm" onclick="openReceiveModal(<?= $po['id'] ?>, '<?= $po['po_number'] ?>')">Receive Stock</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create PO Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; color:var(--primary);">New Purchase Order</h3>
                <button onclick="document.getElementById('createModal').classList.remove('open')" style="background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer;">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_po">
                
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div class="form-group">
                        <label>Supplier *</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">Select Supplier</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>PO Number *</label>
                        <input type="text" name="po_number" class="form-control" value="PO-<?= date('Ymd') ?>-<?= rand(100,999) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Expected Delivery</label>
                        <input type="date" name="expected_delivery_date" class="form-control">
                    </div>
                </div>

                <div style="background:#f8fafc; padding:20px; border-radius:12px; border:1px solid #e2e8f0;">
                    <h4 style="margin:0 0 15px 0; font-size:13px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">Order Items</h4>
                    <div id="itemsContainer">
                        <!-- JS renders rows here -->
                    </div>
                    <button type="button" onclick="addPoItemRow()" class="btn btn-outline btn-sm" style="margin-top:10px;"><i class="fas fa-plus"></i> Add Line Item</button>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:30px;">
                    <button type="button" onclick="document.getElementById('createModal').classList.remove('open')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create PO</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Receive Stock Modal -->
    <div id="receiveModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; color:var(--primary);">Receive Stock (GRN)</h3>
                <span id="receivePoNum" style="font-family:monospace; background:#f1f5f9; padding:4px 10px; border-radius:6px; font-weight:600;"></span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="receive_stock">
                <input type="hidden" name="po_id" id="receivePoId">
                
                <div id="receiveItemsContainer" style="max-height:450px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:12px;">
                    <p style="text-align:center; color:#94a3b8; padding:40px;">Loading items...</p>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:30px;">
                    <button type="button" onclick="document.getElementById('receiveModal').classList.remove('open')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Process Receipt</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const allItems = <?= json_encode($items) ?>;

        function openCreateModal() {
            document.getElementById('createModal').classList.add('open');
            document.getElementById('itemsContainer').innerHTML = '';
            addPoItemRow();
        }

        function addPoItemRow() {
            const container = document.getElementById('itemsContainer');
            const idx = container.children.length;
            
            let opts = '<option value="">Select Item</option>';
            allItems.forEach(i => {
                opts += `<option value="${i.id}">${i.sku} - ${i.name} (${i.uom})</option>`;
            });

            const div = document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `
                <div style="flex:2;">
                    <select name="items[${idx}][item_id]" required class="form-control">${opts}</select>
                </div>
                <div style="flex:1;">
                    <input type="number" name="items[${idx}][qty]" class="form-control" placeholder="Qty" required step="0.01" min="0.01">
                </div>
                <div style="flex:1;">
                    <input type="number" name="items[${idx}][cost]" class="form-control" placeholder="Cost" required step="0.01">
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="action-icon" style="color:var(--danger);"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(div);
        }

        function openReceiveModal(poId, poNum) {
            document.getElementById('receiveModal').classList.add('open');
            document.getElementById('receivePoId').value = poId;
            document.getElementById('receivePoNum').textContent = poNum;
            document.getElementById('receiveItemsContainer').innerHTML = '<p style="text-align:center; padding:40px;">Loading...</p>';
            
            fetch(`get_po_items.php?id=${poId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<table class="data-table" style="font-size:13px;"><thead><tr><th>Item</th><th>Ordered</th><th>Pending</th><th>Receive Now</th><th>Batch/Expiry</th></tr></thead><tbody>';
                    data.forEach((item, idx) => {
                        const pending = Math.max(0, item.qty_ordered - item.qty_received);
                        html += `
                            <tr>
                                <td><strong>${item.item_name}</strong><br><small style="color:#64748b">${item.sku}</small></td>
                                <td>${item.qty_ordered}</td>
                                <td><span style="color:${pending > 0 ? 'var(--warning)' : 'var(--success)'}; font-weight:600;">${pending}</span></td>
                                <td>
                                    <input type="hidden" name="receipts[${idx}][item_id]" value="${item.item_id}">
                                    <input type="number" name="receipts[${idx}][qty_received]" max="${pending}" placeholder="Qty" class="form-control" style="width:100px; padding:6px;">
                                </td>
                                <td>
                                    <input type="text" name="receipts[${idx}][batch]" placeholder="Batch #" class="form-control" style="width:110px; padding:6px; margin-bottom:5px;">
                                    <input type="date" name="receipts[${idx}][expiry]" class="form-control" style="width:110px; padding:6px;">
                                </td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table>';
                    document.getElementById('receiveItemsContainer').innerHTML = html;
                });
        }
    </script>
</body>
</html>
