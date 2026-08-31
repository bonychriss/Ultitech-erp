<?php
require_once '../includes/functions.php';
requireLogin();
ensureStocksSchema();

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_supplier') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_details'] ?? '');
        
        if ($name) {
            if ($id > 0) {
                $pdo->prepare("UPDATE stocks_suppliers SET name=?, contact_details=? WHERE id=?")->execute([$name, $contact, $id]);
                $success = "Supplier updated.";
            } else {
                $pdo->prepare("INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)")->execute([$name, $contact]);
                $success = "Supplier added.";
            }
        } else {
            $error = "Supplier Name is required.";
        }
    }
    
    if ($action === 'delete_supplier') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM stocks_suppliers WHERE id=?")->execute([$id]);
            $success = "Supplier deleted.";
        } catch (PDOException $e) {
            $error = "Cannot delete supplier (likely has linked items or POs).";
        }
    }
}

$suppliers = $pdo->query("SELECT * FROM stocks_suppliers ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Suppliers - <?= COMPANY_NAME ?></title>
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
        
        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.65); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:32px; border-radius:12px; width:500px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
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
        
        /* Table */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th { text-align:left; background:#f8fafc; padding:12px 20px; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #e2e8f0; }
        .data-table td { padding:14px 20px; font-size:14px; color:var(--text); border-bottom:1px solid #f1f5f9; }
        .data-table tr:hover { background:#f8fafc; }
        
        .action-icon { color:#94a3b8; font-size:18px; cursor:pointer; transition:color 0.2s; background:none; border:none; padding:5px; }
        .action-icon:hover { color:var(--primary); }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <div class="main-container">
        <div class="header-dash">
            <div>
                <h1>Suppliers</h1>
                <span style="font-size:13px; color:#64748B;">Manage your procurement sources and partners</span>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Supplier</button>
            </div>
        </div>

        <?php if($success): ?><div style="padding:15px; background:#dcfce7; color:#166534; border-radius:12px; margin-bottom:20px; border:1px solid #bbf7d0;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:12px; margin-bottom:20px; border:1px solid #fecaca;"><?= $error ?></div><?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-truck"></i> Supplier Directory</span>
                <span style="font-size:12px; font-weight:normal; color:#64748b;"><?= count($suppliers) ?> suppliers registered</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact Details</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($suppliers)): ?>
                            <tr><td colspan="3" style="text-align:center; padding:40px; color:#94a3b8;">No suppliers found.</td></tr>
                        <?php endif; ?>
                        <?php foreach($suppliers as $s): ?>
                        <tr>
                            <td><strong style="color:var(--primary);"><?= htmlspecialchars($s['name']) ?></strong></td>
                            <td><?= nl2br(htmlspecialchars($s['contact_details'])) ?></td>
                            <td style="text-align:right">
                                <button class="action-icon" onclick='editSupplier(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this supplier?')">
                                    <input type="hidden" name="action" value="delete_supplier">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="action-icon" style="color:#ef4444;"><i class="fas fa-trash-alt"></i></button>
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
    <div id="supModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 id="modalTitle" style="margin:0; color:var(--primary);">Add Supplier</h3>
                <button onclick="closeModal()" style="background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer;">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="id" id="supId" value="0">
                
                <div class="form-group">
                    <label>Supplier Name *</label>
                    <input type="text" name="name" id="name" class="form-control" required placeholder="e.g. TechWorld Distributors">
                </div>
                <div class="form-group">
                    <label>Contact Details</label>
                    <textarea name="contact_details" id="contact" class="form-control" rows="4" placeholder="Phone, Email, Address..."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('supModal').classList.add('open');
            document.getElementById('modalTitle').textContent = 'Add Supplier';
            document.getElementById('supId').value = '0';
            document.getElementById('name').value = '';
            document.getElementById('contact').value = '';
        }
        function closeModal() {
            document.getElementById('supModal').classList.remove('open');
        }
        function editSupplier(s) {
            openModal();
            document.getElementById('modalTitle').textContent = 'Edit Supplier';
            document.getElementById('supId').value = s.id;
            document.getElementById('name').value = s.name;
            document.getElementById('contact').value = s.contact_details;
        }
    </script>
</body>
</html>
