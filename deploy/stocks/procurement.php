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
        $items = $_POST['items'] ?? []; // Array of {item_id, qty, cost}
        
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
        // We receive per line item for simplicity in this MVP, or bulk?
        // Let's do bulk receive form submission
        $receipts = $_POST['receipts'] ?? []; // {item_id, qty_received, batch, expiry}

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

                // A. Update PO Item
                $stmt = $pdo->prepare("UPDATE stocks_po_items SET qty_received = qty_received + ? WHERE po_id = ? AND item_id = ?");
                $stmt->execute([$qty, $po_id, $itemId]);

                // B. Insert Transaction
                $stmt = $pdo->prepare("INSERT INTO stocks_transactions (item_id, type, quantity, reference_type, reference_id, batch_number, expiry_date, user_id) VALUES (?, 'in', ?, 'PO', ?, ?, ?, ?)");
                $stmt->execute([$itemId, $qty, $po_id, $batch, $expiry, $_SESSION['user_id']]);

                // C. Update Stock Master
                $stmt = $pdo->prepare("UPDATE stocks_items SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt->execute([$qty, $itemId]);
            }

            if ($hasReceipt) {
                // Check if PO is fully received
                // Simple logic: if all items have qty_received >= qty_ordered -> 'received', else 'partial'
                // For MVP, just set to 'partial' or 'received' based on manual toggle or simple check
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

// Fetch Master Data for Forms
$suppliers = $pdo->query("SELECT id, name FROM stocks_suppliers ORDER BY name")->fetchAll();
$items = $pdo->query("SELECT id, name, sku, uom FROM stocks_items ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Roboto:wght@400;500;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-primary: 'Inter', sans-serif;
            --font-heading: 'Roboto', sans-serif;
            --font-data: 'Source Sans 3', sans-serif;
            --primary-color: #2563eb;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        body { font-family: var(--font-primary); background-color: var(--bg-color); color: var(--text-main); }
        h1, h2, h3, h4 { font-family: var(--font-heading); }
        
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:30px; border-radius:12px; width:800px; max-width:95%; max-height:90vh; overflow-y:auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .form-row { display:flex; gap:20px; margin-bottom:20px; }
        .form-group { flex:1; }
        .form-group label { display:block; margin-bottom:8px; font-weight:500; font-size:13px; color: var(--text-main); }
        .form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; font-family: var(--font-data); font-size:14px; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-color); outline: none; }
        
        .item-row { display:flex; gap:12px; align-items:flex-end; border-bottom:1px solid #f3f4f6; padding-bottom:12px; margin-bottom:12px; }
        
        /* Table */
        .data-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid rgba(229, 231, 235, 0.5); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f9fafb; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-primary); border-bottom: 1px solid #e5e7eb; }
        .data-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #374151; font-family: var(--font-data); vertical-align: middle; }
        .data-table tr:hover td { background-color: #f9fafb; }
        
        .btn { background: var(--primary-color); color: white; padding: 10px 20px; border-radius: 6px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; font-family: var(--font-primary); }
        .btn:hover { background: #1d4ed8; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        .btn-outline { background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; cursor: pointer; transition: all 0.2s; font-family: var(--font-primary); }
        .btn-outline:hover { background: #f3f4f6; border-color: #9ca3af; }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 4px; border: 1px solid #d1d5db; background: white; color: #374151; cursor: pointer; }
        .btn-danger { color: #ef4444; border-color: #fecaca; }
        .btn-danger:hover { background: #fef2f2; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content" style="padding: 30px;">
        <div class="header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
            <div style="display:flex; align-items:center; gap:16px;">
                 <a href="index.php" class="btn-icon" style="color:#6b7280; text-decoration:none; display:flex; align-items:center; gap:8px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back
                </a>
                <div>
                    <h1 style="margin:0; font-size: 24px;">Purchase Order</h1>
                    <p style="margin:4px 0 0 0; color:#6b7280; font-size:14px;">Manage Purchase Orders & receipts</p>
                </div>
            </div>
            <button onclick="openCreateModal()" class="btn">
                <span style="font-size:18px; vertical-align:middle; margin-right:4px;">+</span> Create PO
            </button>
        </div>

        <?php if($success): ?><div class="alert alert-success" style="border-radius:8px; margin-bottom:20px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger" style="border-radius:8px; margin-bottom:20px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>PO #</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total Value</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($pos)): ?>
                        <tr><td colspan="7" style="text-align:center; padding:40px; color:#6b7280;">No Purchase Orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach($pos as $po): ?>
                            <tr>
                                <td style="font-weight:600; color:#111827;"><?= htmlspecialchars($po['po_number']) ?></td>
                                <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                <td>
                                    <span class="status-badge" style="
                                        padding: 4px 10px; border-radius: 999px; font-weight: 500; font-size: 12px;
                                        background: <?= $po['status']==='received'?'#ecfdf5':($po['status']==='partial'?'#fff7ed':'#f3f4f6') ?>; 
                                        color: <?= $po['status']==='received'?'#059669':($po['status']==='partial'?'#ea580c':'#4b5563') ?>;
                                        text-transform: capitalize;
                                    "><?= $po['status'] ?></span>
                                </td>
                                <td><?= date('d M Y', strtotime($po['created_at'])) ?></td>
                                <td><?= $po['item_count'] ?></td>
                                <td><?= number_format($po['total_value'], 2) ?></td>
                                <td style="text-align:right;">
                                    <?php if($po['status'] !== 'cancelled'): ?>
                                        <button onclick="openReceiveModal(<?= $po['id'] ?>, '<?= $po['po_number'] ?>')" class="btn-outline" style="font-size:12px;">Receive Stock</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Create PO Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-top:0;">New Purchase Order</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_po">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Supplier *</label>
                        <select name="supplier_id" required>
                            <option value="">Select Supplier</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>PO Number *</label>
                        <input type="text" name="po_number" value="PO-<?= date('Ymd') ?>-<?= rand(100,999) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Expected Delivery</label>
                        <input type="date" name="expected_delivery_date">
                    </div>
                </div>

                <div style="background:#f9fafb; padding:15px; border-radius:6px; margin-top:10px;">
                    <h4 style="margin:0 0 10px 0; font-size:13px; color:#6b7280;">Order Items</h4>
                    <div id="itemsContainer">
                        <!-- JS renders rows here -->
                    </div>
                    <button type="button" onclick="addPoItemRow()" class="btn-sm" style="margin-top:10px;">+ Add Item</button>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" onclick="document.getElementById('createModal').classList.remove('open')" class="btn-outline">Cancel</button>
                    <button type="submit" class="btn">Create PO</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Receive Stock Modal -->
    <div id="receiveModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h3 style="margin:0;">Receive Stock (GRN)</h3>
                <span id="receivePoNum" style="font-family:monospace; background:#e5e7eb; padding:2px 6px; border-radius:4px;"></span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="receive_stock">
                <input type="hidden" name="po_id" id="receivePoId">
                
                <div id="receiveItemsContainer" style="max-height:400px; overflow-y:auto;">
                    <p style="text-align:center; color:#6b7280;">Loading items...</p>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" onclick="document.getElementById('receiveModal').classList.remove('open')" class="btn-outline">Cancel</button>
                    <button type="submit" class="btn">Process Receipt</button>
                </div>
            </form>
        </div>
    </div>

    <!-- API Helper Script for fetching PO items -->
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
                    <select name="items[${idx}][item_id]" required class="form-control item-select">${opts}</select>
                </div>
                <div style="flex:1;">
                    <input type="number" name="items[${idx}][qty]" placeholder="Qty" required step="0.01" min="0.01">
                </div>
                <div style="flex:1;">
                    <input type="number" name="items[${idx}][cost]" placeholder="Cost" required step="0.01">
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="btn-sm btn-danger">&times;</button>
            `;
            container.appendChild(div);
        }

        function openReceiveModal(poId, poNum) {
            document.getElementById('receiveModal').classList.add('open');
            document.getElementById('receivePoId').value = poId;
            document.getElementById('receivePoNum').textContent = poNum;
            document.getElementById('receiveItemsContainer').innerHTML = '<p>Loading...</p>';

            // Fetch Items via AJAX (simulate)
            // In a real app we'd have a dedicated API endpoint. 
            // Here I'll cheat and reload the page with a special query param or just build a quick PHP handler on this same page to return JSON.
            // Let's use a quick fetch to a helper script.
            
            fetch(`get_po_items.php?id=${poId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<table class="alert-table" style="font-size:13px;"><thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Receive Now</th><th>Batch/Expiry</th></tr></thead><tbody>';
                    data.forEach((item, idx) => {
                        html += `
                            <tr>
                                <td>${item.item_name} <br><small>${item.sku}</small></td>
                                <td>${item.qty_ordered}</td>
                                <td>${item.qty_received}</td>
                                <td>
                                    <input type="hidden" name="receipts[${idx}][item_id]" value="${item.item_id}">
                                    <input type="number" name="receipts[${idx}][qty_received]" max="${item.qty_ordered - item.qty_received}" placeholder="Qty" style="width:80px; padding:4px;">
                                </td>
                                <td>
                                    <input type="text" name="receipts[${idx}][batch]" placeholder="Batch #" style="width:90px; padding:4px; margin-bottom:2px;"><br>
                                    <input type="date" name="receipts[${idx}][expiry]" style="width:90px; padding:4px;">
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
