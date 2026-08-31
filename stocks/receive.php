<?php
require_once '../includes/functions.php';
requireLogin();
ensureStocksSchema();

$error = '';
$success = '';

// Handle GRN Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'receive_stock') {
        $item_id = (int)$_POST['item_id'];
        $supplier_id = (int)$_POST['supplier_id']; 
        $po_number = trim($_POST['po_number']);
        $batch = trim($_POST['batch_no']);
        $expiry = $_POST['expiry_date'] ?: null;
        $qty = (float)$_POST['qty'];
        $cost = (float)$_POST['cost'];
        $location = trim($_POST['warehouse_location']);
        $date_received = $_POST['date_received'];
        $invoice_grn = trim($_POST['invoice_grn']); 

        if ($item_id && $qty > 0) {
            try {
                $pdo->beginTransaction();
                
                $refString = $po_number;
                if ($invoice_grn) $refString .= " / " . $invoice_grn;

                $stmt = $pdo->prepare("INSERT INTO stocks_transactions 
                    (item_id, type, quantity, unit_cost, tax_amount, warehouse_location, condition_status, batch_number, expiry_date, external_reference, user_id, transaction_date) 
                    VALUES (?, 'in', ?, ?, 0, ?, 'Good', ?, ?, ?, ?, ?)");
                
                $timestamp = $date_received . ' ' . date('H:i:s');
                
                $stmt->execute([$item_id, $qty, $cost, $location, $batch, $expiry, $refString, $_SESSION['user_id'], $timestamp]);

                // Update Stock Master
                $stmt = $pdo->prepare("UPDATE stocks_items SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt->execute([$qty, $item_id]);

                $pdo->commit();
                $success = "Stock received successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to receive stock: " . $e->getMessage();
            }
        } else {
            $error = "Invalid Item or Quantity.";
        }
    }
}

// Fetch Master Data
$items = $pdo->query("SELECT id, name, sku, uom, stock_quantity FROM stocks_items ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM stocks_suppliers ORDER BY name")->fetchAll();

// Fetch Recent History
$history = $pdo->query("
    SELECT t.*, i.name as item_name, i.sku 
    FROM stocks_transactions t 
    JOIN stocks_items i ON t.item_id = i.id 
    WHERE t.type = 'in' 
    ORDER BY t.transaction_date DESC 
    LIMIT 10
")->fetchAll();

// Calculate Stats
$totalValue = $pdo->query("SELECT SUM(stock_quantity * (SELECT unit_cost FROM stocks_transactions WHERE item_id = stocks_items.id ORDER BY transaction_date DESC LIMIT 1)) FROM stocks_items")->fetchColumn();  
$totalValue = $totalValue ?: 0;
$todayCount = $pdo->query("SELECT COUNT(*) FROM stocks_transactions WHERE type='in' AND DATE(transaction_date) = CURDATE()")->fetchColumn();
$lowStock = $pdo->query("SELECT COUNT(*) FROM stocks_items WHERE stock_quantity <= reorder_point")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Stock Management</title>
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
        }

        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: var(--text); }
        * { box-sizing: border-box; }

        /* LAYOUT */
        .main-container {
            max-width: 1200px; margin: 0 auto; padding: 20px;
        }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header h1 { margin: 0; font-size: 24px; color: var(--primary); }

        /* STATS CARDS */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: var(--card-bg); padding: 20px; border-radius: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;
        }
        .stat-info h4 { margin: 0 0 5px 0; color: #64748B; font-size: 13px; text-transform: uppercase; }
        .stat-info .num { font-size: 24px; font-weight: 700; color: var(--primary); }
        .icon-box { width: 45px; height: 45px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .blue { background: #E0F2FE; color: #0284C7; }
        .green { background: #DCFCE7; color: #16A34A; }
        .orange { background: #FFF7ED; color: #EA580C; }

        /* CONTENT GRID */
        .content-grid { display: grid; grid-template-columns: 1.8fr 1.2fr; gap: 25px; }

        /* FORM STYLING */
        .card { background: var(--card-bg); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; }
        .card-header { padding: 15px 20px; border-bottom: 1px solid var(--border); background: #F8FAFC; font-weight: 600; font-size: 15px; color: var(--primary); display: flex; justify-content: space-between; }
        
        .form-body { padding: 25px; }
        
        .form-section { margin-bottom: 25px; }
        .section-title { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--accent); font-weight: 700; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .full-width { grid-column: span 2; }

        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #64748B; margin-bottom: 6px; }
        .form-input { 
            width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 14px; transition: 0.2s;
            background: #F8FAFC;
        }
        .form-input:focus { background: #fff; border-color: var(--accent); outline: none; }
        .readonly { background: #E2E8F0; color: #64748B; pointer-events: none; }

        .btn-submit { background: var(--primary); color: white; border: none; padding: 12px 20px; width: 100%; border-radius: 6px; font-weight: 600; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background: #F4B400; color: black; }

        /* TABLE STYLING */
        .recent-table { width: 100%; border-collapse: collapse; }
        .recent-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748B; padding: 12px 15px; background: #F8FAFC; border-bottom: 1px solid var(--border); }
        .recent-table td { padding: 12px 15px; font-size: 13px; border-bottom: 1px solid #F1F5F9; color: var(--text); }
        .recent-table tr:last-child td { border: none; }
        
        .status-badge { padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .b-received { background: #DCFCE7; color: #166534; }
        
        @media (max-width: 900px) { .content-grid, .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <?php require_once '../includes/header_employee.php'; ?>

    <div class="main-container">
        
        <!-- Header -->
        <div class="header">
            <div>
                <h1>Stock Management</h1>
                <span style="font-size:13px; color:#64748B;">Procurement & Goods Receipt Note (GRN)</span>
            </div>
            <div>
                <a href="reports.php" style="background:white; border:1px solid #ddd; padding:8px 15px; border-radius:6px; cursor:pointer; text-decoration:none; color:#1E293B; display:inline-block;">
                    <i class="fas fa-file-export"></i> Export Report
                </a>
            </div>
        </div>

        <?php if($success): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:6px; margin-bottom:20px;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:6px; margin-bottom:20px;"><?= $error ?></div><?php endif; ?>

        <!-- 1. Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-info"><h4>Total Stock Value</h4><div class="num">$<?= number_format($totalValue) ?></div></div>
                <div class="icon-box blue"><i class="fas fa-coins"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info"><h4>Items Received (Today)</h4><div class="num"><?= $todayCount ?></div></div>
                <div class="icon-box green"><i class="fas fa-truck-loading"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info"><h4>Low Stock Alerts</h4><div class="num" style="color:#EF4444"><?= $lowStock ?></div></div>
                <div class="icon-box orange"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>

        <!-- 2. Main Content -->
        <div class="content-grid">
            
            <!-- LEFT: INPUT FORM -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-plus-circle"></i> Receive New Stock (GRN)</span>
                </div>
                <div class="form-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="receive_stock">
                        
                        <!-- Section A: Item Details -->
                        <div class="form-section">
                            <div class="section-title">1. Item Information</div>
                            <div class="form-row">
                                <div class="form-group full-width">
                                    <label>Select Item / SKU</label>
                                    <select name="item_id" class="form-input" onchange="updateItemInfo(this)" required>
                                        <option value="">Select Item...</option>
                                        <?php foreach($items as $i): ?>
                                            <option value="<?= $i['id'] ?>" data-stock="<?= $i['stock_quantity'] ?>"><?= htmlspecialchars($i['name']) ?> (<?= htmlspecialchars($i['sku']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Category</label>
                                    <input type="text" class="form-input readonly" value="General" readonly>
                                </div>
                                <div class="form-group">
                                    <label>Current Stock</label>
                                    <input type="text" id="current_stock" class="form-input readonly" value="-" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Section B: Supplier Info -->
                        <div class="form-section">
                            <div class="section-title">2. Procurement Source</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Supplier Name</label>
                                    <select name="supplier_id" class="form-input">
                                         <option value="">Select Supplier...</option>
                                        <?php foreach($suppliers as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>PO Number</label>
                                    <input type="text" name="po_number" class="form-input" placeholder="e.g. PO-2025-001">
                                </div>
                                <div class="form-group">
                                    <label>Batch / Lot No.</label>
                                    <input type="text" name="batch_no" class="form-input" placeholder="Batch #">
                                </div>
                                <div class="form-group">
                                    <label>Date Received</label>
                                    <input type="date" name="date_received" class="form-input" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Section C: Costing -->
                        <div class="form-section">
                            <div class="section-title">3. Quantity & Cost</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Quantity Received</label>
                                    <input type="number" name="qty" id="qty" class="form-input" placeholder="0" step="0.01" required oninput="calcTotal()">
                                </div>
                                <div class="form-group">
                                    <label>Unit Cost (Buying Price)</label>
                                    <input type="number" name="cost" id="cost" class="form-input" placeholder="0.00" step="0.01" required oninput="calcTotal()">
                                </div>
                                <div class="form-group full-width">
                                    <label>Total Value (Auto)</label>
                                    <input type="text" id="total" class="form-input readonly" placeholder="0.00" style="font-weight:bold; color:#2C3E50;">
                                </div>
                            </div>
                        </div>

                        <!-- Section D: Location -->
                        <div class="form-section">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Warehouse Location</label>
                                    <input type="text" name="warehouse_location" class="form-input" placeholder="e.g. Shelf A-4">
                                </div>
                                <div class="form-group">
                                    <label>Expiry Date (Optional)</label>
                                    <input type="date" name="expiry_date" class="form-input">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">Confirm Stock Receipt</button>

                    </form>
                </div>
            </div>

            <!-- RIGHT: RECENT HISTORY -->
            <div class="card">
                <div class="card-header">
                    <span>Recent Intakes</span>
                    <i class="fas fa-history" style="color:#aaa;"></i>
                </div>
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Supplier</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($history as $h): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($h['item_name']) ?></strong><br>
                                    <span style="font-size:11px; color:#888;"><?= htmlspecialchars($h['sku']) ?></span>
                                </td>
                                <td style="color:#166534; font-weight:600;">+<?= number_format($h['quantity']) ?></td>
                                <td><small>TechWorld</small></td> <!-- Placeholder for Supplier if not joined, or use generic -->
                                <td><span class="status-badge b-received">Stocked</span></td>
                            </tr>
                        <?php endforeach; ?>
                         <?php if(empty($history)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:15px; color:#aaa;">No recent history.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div style="padding:15px; text-align:center;">
                    <a href="index.php" style="font-size:13px; color:#2C3E50; font-weight:600; text-decoration:none;">View All Stock Logs &rarr;</a>
                </div>
            </div>

        </div>

    </div>

    <!-- Script for Calculation -->
    <script>
        function updateItemInfo(select) {
            const opt = select.options[select.selectedIndex];
            const stock = opt.getAttribute('data-stock') || '-';
            document.getElementById('current_stock').value = stock + " Units";
        }

        function calcTotal() {
            let qty = parseFloat(document.getElementById('qty').value) || 0;
            let cost = parseFloat(document.getElementById('cost').value) || 0;
            let total = qty * cost;
            document.getElementById('total').value = "$" + total.toFixed(2);
        }
    </script>

</body>
</html>
