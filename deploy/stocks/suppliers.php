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
        // Check for dependencies? Foreign keys might restrict this, which is good.
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
        
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .modal.open { display:flex; animation: fadeIn 0.2s ease-out; }
        .modal-content { background:#fff; padding:30px; border-radius:12px; width:500px; max-width:95%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:8px; font-weight:500; font-size:13px; color: var(--text-main); }
        .form-group input, .form-group textarea { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; font-family: var(--font-data); font-size:14px; transition: border-color 0.2s; }
        .form-group input:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; }
        
        .data-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid rgba(229, 231, 235, 0.5); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f9fafb; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-primary); border-bottom: 1px solid #e5e7eb; }
        .data-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #374151; font-family: var(--font-data); }
        .data-table tr:hover td { background-color: #f9fafb; }
        
        .btn { background: var(--primary-color); color: white; padding: 10px 20px; border-radius: 6px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; font-family: var(--font-primary); }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 4px; border: 1px solid #d1d5db; background: white; color: #374151; cursor: pointer; margin-right:5px; transition: all 0.2s; }
        .btn-sm:hover { background: #f3f4f6; border-color: #9ca3af; }
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
                    <h1 style="margin:0; font-size: 24px;">Suppliers</h1>
                    <p style="margin:4px 0 0 0; color:#6b7280; font-size:14px;">Manage your vendor database</p>
                </div>
            </div>
            <button onclick="openModal()" class="btn">
                <span style="font-size:18px; vertical-align:middle; margin-right:4px;">+</span> Add Supplier
            </button>
        </div>

        <?php if($success): ?><div class="alert alert-success" style="border-radius:8px; margin-bottom:20px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger" style="border-radius:8px; margin-bottom:20px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:30%;">Name</th>
                        <th>Contact Details</th>
                        <th style="width:100px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($suppliers)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:40px; color:#6b7280;">No suppliers found.</td></tr>
                    <?php else: ?>
                        <?php foreach($suppliers as $s): ?>
                            <tr>
                                <td style="font-weight:600; color:#111827;"><?= htmlspecialchars($s['name']) ?></td>
                                <td style="color:#4b5563;"><?= nl2br(htmlspecialchars($s['contact_details'])) ?></td>
                                <td style="text-align:right;">
                                    <button onclick='editSupplier(<?= json_encode($s) ?>)' class="btn-sm">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal -->
    <div id="supModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-top:0;">Add Supplier</h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="id" id="supId" value="0">
                
                <div class="form-group">
                    <label>Supplier Name *</label>
                    <input type="text" name="name" id="name" required placeholder="e.g. Acme Corp">
                </div>
                <div class="form-group">
                    <label>Contact Details</label>
                    <textarea name="contact_details" id="contact" rows="4" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;" placeholder="Phone, Email, Address..."></textarea>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" onclick="closeModal()" class="btn" style="background:#fff; border:1px solid #ccc; color:#333;">Cancel</button>
                    <button type="submit" class="btn">Save Supplier</button>
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
