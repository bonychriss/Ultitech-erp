<?php
require_once '../includes/functions.php';
requireLogin();
ensureStocksSchema();

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_item') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $sku = trim($_POST['sku'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? ''); // Text input for now -> find or create category
        $uom = trim($_POST['uom'] ?? 'Each');
        $barcode = trim($_POST['barcode'] ?? '');
        $rop = (int)($_POST['reorder_point'] ?? 0);
        $safety = (int)($_POST['safety_stock'] ?? 0);
        
        if ($sku && $name) {
            // resolve category
            $catId = null;
            if ($category) {
                $stmt = $pdo->prepare("SELECT id FROM stocks_categories WHERE name = ?");
                $stmt->execute([$category]);
                $catId = $stmt->fetchColumn();
                if (!$catId) {
                    $pdo->prepare("INSERT INTO stocks_categories (name) VALUES (?)")->execute([$category]);
                    $catId = $pdo->lastInsertId();
                }
            }

            if ($id > 0) {
                // Update
                $stmt = $pdo->prepare("UPDATE stocks_items SET sku=?, name=?, category_id=?, uom=?, barcode=?, reorder_point=?, safety_stock=? WHERE id=?");
                $stmt->execute([$sku, $name, $catId, $uom, $barcode, $rop, $safety, $id]);
                $success = "Item updated successfully.";
            } else {
                // Insert
                try {
                    $stmt = $pdo->prepare("INSERT INTO stocks_items (sku, name, category_id, uom, barcode, reorder_point, safety_stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$sku, $name, $catId, $uom, $barcode, $rop, $safety]);
                    $success = "Item created successfully.";
                } catch (PDOException $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } else {
            $error = "SKU and Name are required.";
        }
    }
    
    if ($action === 'delete_item') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM stocks_items WHERE id=?")->execute([$id]);
        $success = "Item deleted.";
    }
}

// Fetch Items
$sql = "
    SELECT i.*, c.name as category_name 
    FROM stocks_items i 
    LEFT JOIN stocks_categories c ON i.category_id = c.id 
    ORDER BY i.name ASC
";
$items = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Items Master - <?= COMPANY_NAME ?></title>
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
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: var(--text); }
        * { box-sizing: border-box; }

        .main-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        @media (min-width: 1024px) {
            .main-container {
                margin-left: 260px;
                max-width: calc(100% - 280px); /* Adjust max-width to fit remaining space */
            }
        }
        
        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.65); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:32px; border-radius:12px; width:550px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; }
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
        .btn-accent { background:var(--accent); color:#000; }
        .btn-accent:hover { background:#d9a000; }
        .btn-outline { background:transparent; border:1px solid #e2e8f0; color:#64748b; }
        .btn-outline:hover { background:#f8fafc; border-color:#cbd5e1; color:var(--text); }
        
        /* Table */
        .items-table { width:100%; border-collapse:collapse; }
        .items-table th { text-align:left; background:#f8fafc; padding:12px 20px; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #e2e8f0; }
        .items-table td { padding:14px 20px; font-size:14px; color:var(--text); border-bottom:1px solid #f1f5f9; }
        .items-table tr:hover { background:#f8fafc; }
        
        .badge { padding:4px 8px; border-radius:6px; font-size:12px; font-weight:600; }
        .badge-info { background:#e1effe; color:#1e429f; }
        
        .action-icon { color:#94a3b8; font-size:18px; cursor:pointer; transition:color 0.2s; background:none; border:none; padding:5px; }
        .action-icon:hover { color:var(--primary); }
        .delete-icon:hover { color:var(--danger); }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <div class="main-container">
        <div class="header-dash">
            <div>
                <h1>Items Master</h1>
                <span style="font-size:13px; color:#64748B;">Manage your products and inventory catalog</span>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add New Item</button>
            </div>
        </div>

        <?php if($success): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:12px; margin-bottom:20px; border:1px solid #bbf7d0;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:12px; margin-bottom:20px; border:1px solid #fecaca;"><?= $error ?></div><?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-list"></i> Product List</span>
                <span style="font-size:12px; font-weight:normal; color:#64748b;"><?= count($items) ?> items registered</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>UoM</th>
                            <th>Stock Level</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($items)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:40px; color:#94a3b8;">No items found.</td></tr>
                        <?php endif; ?>
                        <?php foreach($items as $i): ?>
                        <tr>
                            <td style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($i['sku']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($i['name']) ?></strong><br>
                                <small style="color:#94a3b8"><?= htmlspecialchars($i['barcode']) ?></small>
                            </td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($i['category_name'] ?: 'Uncategorized') ?></span></td>
                            <td><?= htmlspecialchars($i['uom']) ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <strong style="font-size:15px; color:<?= $i['stock_quantity'] <= $i['reorder_point'] ? 'var(--danger)' : 'var(--success)' ?>"><?= number_format($i['stock_quantity']) ?></strong>
                                    <span style="font-size:11px; color:#94a3b8">(Min: <?= $i['reorder_point'] ?>)</span>
                                </div>
                            </td>
                            <td style="text-align:right">
                                <button class="action-icon" onclick='editItem(<?= json_encode($i) ?>)'><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this item?')">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                    <button type="submit" class="action-icon delete-icon"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 id="modalTitle" style="margin:0; color:var(--primary);">Add New Item</h3>
                <button onclick="closeModal()" style="background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer;">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="id" id="itemId" value="0">
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>SKU (Unique Code) *</label>
                        <input type="text" name="sku" id="sku" class="form-control" required placeholder="e.g. LAP-001">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" id="category" class="form-control" list="catList" placeholder="e.g. Electronics">
                        <datalist id="catList">
                            <?php 
                            $cats = $pdo->query("SELECT name FROM stocks_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                            foreach($cats as $c) echo "<option value='".htmlspecialchars($c)."'>";
                            ?>
                        </datalist>
                    </div>
                </div>

                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" name="name" id="name" class="form-control" required placeholder="e.g. Dell Latitude 5420">
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Unit of Measure</label>
                        <select name="uom" id="uom" class="form-control">
                            <option value="Each">Each</option>
                            <option value="Box">Box</option>
                            <option value="Kg">Kg</option>
                            <option value="Liters">Liters</option>
                            <option value="Pcs">Pcs</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Barcode / GTIN</label>
                        <input type="text" name="barcode" id="barcode" class="form-control" placeholder="Optional">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Reorder Point (Low Stock)</label>
                        <input type="number" name="reorder_point" id="reorder_point" class="form-control" value="5">
                    </div>
                    <div class="form-group">
                        <label>Safety Stock</label>
                        <input type="number" name="safety_stock" id="safety_stock" class="form-control" value="2">
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('itemModal').classList.add('open');
            document.getElementById('modalTitle').textContent = 'Add New Item';
            document.getElementById('itemId').value = '0';
            document.getElementById('sku').value = '';
            document.getElementById('name').value = '';
            document.getElementById('category').value = '';
            document.getElementById('uom').value = 'Each';
            document.getElementById('barcode').value = '';
            document.getElementById('reorder_point').value = '5';
            document.getElementById('safety_stock').value = '2';
        }
        function closeModal() {
            document.getElementById('itemModal').classList.remove('open');
        }
        function editItem(item) {
            openModal();
            document.getElementById('modalTitle').textContent = 'Edit Item';
            document.getElementById('itemId').value = item.id;
            document.getElementById('sku').value = item.sku;
            document.getElementById('name').value = item.name;
            document.getElementById('category').value = item.category_name || '';
            document.getElementById('uom').value = item.uom;
            document.getElementById('barcode').value = item.barcode;
            document.getElementById('reorder_point').value = item.reorder_point;
            document.getElementById('safety_stock').value = item.safety_stock;
        }
    </script>
</body>
</html>
