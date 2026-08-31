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
    <title>Items - <?= COMPANY_NAME ?></title>
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
        h1, h2, h3 { font-family: var(--font-heading); }
        
        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:30px; border-radius:12px; width:550px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:8px; font-weight:500; font-size:13px; color: var(--text-main); }
        .form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; font-family: var(--font-data); font-size:14px; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-color); outline: none; ring: 2px solid rgba(37, 99, 235, 0.1); }
        
        /* Table */
        .data-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid rgba(229, 231, 235, 0.5); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f9fafb; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-primary); border-bottom: 1px solid #e5e7eb; }
        .data-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #374151; font-family: var(--font-data); vertical-align: middle; }
        .data-table tr:hover td { background-color: #f9fafb; }
        .data-table tr:last-child td { border-bottom: none; }
        
        .status-badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; }
        
        /* Buttons */
        .btn { background: var(--primary-color); color: white; padding: 10px 20px; border-radius: 6px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; font-family: var(--font-primary); }
        .btn:hover { background: #1d4ed8; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 4px; border: 1px solid #d1d5db; background: white; color: #374151; cursor: pointer; }
        .btn-sm:hover { background: #f3f4f6; }
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
                    <h1 style="margin:0; font-size: 24px;">Items</h1>
                    <p style="margin:4px 0 0 0; color:#6b7280; font-size:14px;">Manage your inventory items and SKUs</p>
                </div>
            </div>
            <button onclick="openModal()" class="btn">
                <span style="margin-right:8px;">+</span> Add New Item
            </button>
        </div>

        <?php if($success): ?><div class="alert alert-success" style="margin-bottom:20px; border-radius:8px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger" style="margin-bottom:20px; border-radius:8px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>UoM</th>
                        <th>Reorder Point</th>
                        <th>Stock</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($items)): ?>
                        <tr><td colspan="7" style="text-align:center; padding:40px; color:#6b7280;">No items found. Add your first item!</td></tr>
                    <?php else: ?>
                        <?php foreach($items as $i): ?>
                            <tr>
                                <td style="font-weight:600; color:#111827;"><?= htmlspecialchars($i['sku']) ?></td>
                                <td>
                                    <div style="font-weight:600; color:#1f2937;"><?= htmlspecialchars($i['name']) ?></div>
                                    <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                                        <?= $i['barcode'] ? '<span style="display:inline-flex; align-items:center; gap:4px;"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 4h18M3 20h18M5 8h2m4 0h2m4 0h2m-10 4h2m4 0h2m4 0h2"/></svg>' . htmlspecialchars($i['barcode']) . '</span>' : '' ?>
                                    </div>
                                </td>
                                <td><span class="status-badge" style="background:#f3f4f6; color:#4b5563;"><?= htmlspecialchars($i['category_name'] ?? '-') ?></span></td>
                                <td><?= htmlspecialchars($i['uom']) ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <div style="width:6px; height:6px; border-radius:50%; background:<?= $i['reorder_point'] > 0 ? '#f59e0b' : '#d1d5db' ?>"></div>
                                        <?= (int)$i['reorder_point'] ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight:600; color:<?= $i['stock_quantity'] <= $i['reorder_point'] ? '#ef4444' : '#10b981' ?>">
                                        <?= number_format($i['stock_quantity']) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <button onclick='editItem(<?= json_encode($i) ?>)' class="btn-sm" style="margin-right:8px;">Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this item?');">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                        <button class="btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-top:0;">Add New Item</h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="id" id="itemId" value="0">
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>SKU (Unique Code) *</label>
                        <input type="text" name="sku" id="sku" required placeholder="e.g. LAP-001">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" id="category" list="catList" placeholder="e.g. Electronics">
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
                    <input type="text" name="name" id="name" required placeholder="e.g. Dell Latitude 5420">
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Unit of Measure (UoM) *</label>
                        <select name="uom" id="uom">
                            <option value="Each">Each</option>
                            <option value="Box">Box</option>
                            <option value="Kg">Kg</option>
                            <option value="Litre">Litre</option>
                            <option value="Metre">Metre</option>
                            <option value="Set">Set</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Barcode / GTIN</label>
                        <input type="text" name="barcode" id="barcode" placeholder="Scan...">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:10px; border-top:1px solid #eee; padding-top:10px;">
                    <div class="form-group">
                        <label>Reorder Point (Min)</label>
                        <input type="number" name="reorder_point" id="reorder_point" value="5">
                        <small style="color:#6b7280; font-size:11px;">Alert when stock dips below this</small>
                    </div>
                    <div class="form-group">
                        <label>Safety Stock</label>
                        <input type="number" name="safety_stock" id="safety_stock" value="2">
                    </div>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" onclick="closeModal()" class="btn" style="background:#fff; border:1px solid #ccc; color:#333;">Cancel</button>
                    <button type="submit" class="btn">Save Item</button>
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
