<?php
require_once '../../includes/functions.php';
requireLogin();

$success = '';
$error = '';

// Handle Create/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'create' || $_POST['action'] === 'edit') {
                $name = trim($_POST['name']);
                $type = trim($_POST['type']);
                $tin = trim($_POST['tin']);
                $contact = trim($_POST['contact_details']);

                if (empty($name))
                    throw new Exception("Payee Name is required");

                if ($_POST['action'] === 'create') {
                    $stmt = $pdo->prepare("INSERT INTO payees (name, type, tin, contact_details) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $type, $tin, $contact]);
                    $success = "Payee added successfully.";
                } else {
                    $id = (int) $_POST['id'];
                    $stmt = $pdo->prepare("UPDATE payees SET name=?, type=?, tin=?, contact_details=? WHERE id=?");
                    $stmt->execute([$name, $type, $tin, $contact, $id]);
                    $success = "Payee updated successfully.";
                }
            } elseif ($_POST['action'] === 'delete') {
                $id = (int) $_POST['id'];
                $stmt = $pdo->prepare("UPDATE payees SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Payee deactivated successfully.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (strpos($error, 'Duplicate entry') !== false) {
                $error = "A payee with this name already exists.";
            }
        }
    }
}

// Fetch Payees
$search = trim($_GET['search'] ?? '');
$params = [];
$query = "SELECT * FROM payees WHERE is_active = 1";

if ($search) {
    $query .= " AND (name LIKE ? OR tin LIKE ? OR type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payees = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payees - Expenses - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; } 
        body { margin: 0; padding: 0; background: #f8f9fa; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #202124; overflow-x: hidden; width: 100%; transition: all 0.3s; } 
        
        /* Sidebar & Wrapper Transitions */
        .sidebar { transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); width: 220px !important; overflow-x: hidden; }
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); width: auto !important; } 
        
        /* Collapsed State */
        body.sidebar-collapsed .sidebar { width: 70px !important; }
        body.sidebar-collapsed .sidebar .text, 
        body.sidebar-collapsed .sidebar .sidebar-label,
        body.sidebar-collapsed .sidebar-brand span { display: none !important; }
        body.sidebar-collapsed .sidebar .menu-item a { justify-content: center; padding: 12px 0; }
        body.sidebar-collapsed .sidebar .d-icon { margin-right: 0; }
        body.sidebar-collapsed .page-wrapper { margin-left: 70px !important; width: auto !important; }

        @media (max-width: 1024px) { 
            .page-wrapper { margin-left: 0 !important; width: 100% !important; } 
            body.sidebar-collapsed .page-wrapper { margin-left: 0 !important; width: 100% !important; }
        } 
        
        .container { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 24px; box-sizing: border-box; } 
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 20px; }
        .page-title h1 { font-size: 24px; font-weight: 600; color: #1a0dab; }
        
        .btn { padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: 1px solid transparent; transition: all 0.2s; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-primary:hover { background: #1557b0; }
        .btn-secondary { background: white; color: #5f6368; border-color: #dadce0; }
        .btn-secondary:hover { background: #f1f3f4; color: #202124; }
        
        .alert { padding: 12px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; }
        .alert-success { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .alert-error { background: #fdecea; color: #d93025; border: 1px solid #fad2cf; font-size: 14px; }

        /* Modal & Forms */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .modal.open { display: flex; }
        .modal-content { background: white; border-radius: 12px; width: 100%; max-width: 500px; padding: 30px; box-shadow: 0 24px 48px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .modal-header h2 { font-size: 20px; color: #202124; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #3c4043; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 6px; font-size: 14px; transition: border-color 0.2s; }
        .form-control:focus { border-color: #1a73e8; outline: none; box-shadow: 0 0 0 2px rgba(26,115,232,0.1); }

        /* Design Match Styles */
        .card { background: white; border-radius: 8px; border: 1px solid #dadce0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
        .table thead th { background: #0081c2; color: white; padding: 12px 20px; font-weight: 600; text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; border: none; }
        .table td { padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid #eee; }
        
        .badge { padding: 4px 16px; border-radius: 20px; font-size: 12px; font-weight: 500; text-transform: capitalize; }
        .badge-info { background: #e8f0fe; color: #1a73e8; border: 1px solid #d2e3fc; }
        
        /* Action buttons layout */
        .action-cell { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
        .btn-record { color: #1a73e8; text-decoration: none; font-size: 13px; font-weight: 500; margin-right: 4px; }
        .btn-record:hover { text-decoration: underline; }
        
        .icon-btn { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 4px; border: 1px solid #dadce0; background: #fff; cursor: pointer; color: #3c4043; transition: all 0.2s; padding: 0; }
        .icon-btn:hover { background: #f1f3f4; border-color: #bdc1c6; }
        .icon-btn svg { width: 16px; height: 16px; }
        
        .search-area { display: flex; gap: 10px; align-items: center; }
        .search-input { padding: 8px 12px; border: 1px solid #dadce0; border-radius: 4px; width: 200px; font-size: 14px; height: 38px; }
        .btn-blue { background: #1a73e8; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 14px; height: 38px; }
        .btn-blue:hover { background: #1557b0; }

        /* --- Mobile Responsiveness --- */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; border-bottom: 1px solid #f1f3f4; padding-bottom: 16px; margin-bottom: 16px; }
            .header-actions { width: 100%; display: flex; flex-direction: column; gap: 12px; }
            .search-area { width: 100%; }
            .search-area form { width: 100%; flex-direction: column; }
            .search-input { width: 100%; }
            .btn-blue { width: 100%; justify-content: center; }
            
            .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .table { width: 800px; }
            
            .modal-content { padding: 20px; width: 95%; margin: 10px; }
            
            /* Page adjustments */
            .page-wrapper { margin-left: 0 !important; }
            .container { padding: 16px; }
            .page-title h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
    <script>
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed');
        }
    </script>
    
    <?php include '../../sidebar.php'; ?>
    <div class="page-wrapper">
        <div class="container">
            <div class="page-header">
                <div class="page-title-area" style="display:flex; align-items:center; gap:16px; flex-grow:1;">
                    <button class="icon-btn" id="sidebarToggle" title="Toggle Sidebar" style="width:40px; height:40px; border-radius:4px;">
                        <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    <div class="page-title">
                        <h1 style="color:#1a0dab; font-size:24px;">Payees</h1>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="search-area">
                        <form action="" method="GET" style="display:flex; gap:8px;">
                            <input type="hidden" name="module" value="expenses">
                            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search..." class="search-input">
                            <button type="submit" class="icon-btn" style="width:auto; padding: 0 16px; height:40px;">Search</button>
                            <button type="button" onclick="openModal()" class="btn-blue" style="height:40px; display:flex; align-items:center;">+ New Payee</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="table-wrap">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Payee Name</th>
                            <th style="text-align:center;">Type</th>
                            <th style="text-align:center;">TIN</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payees)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:60px; color:#5f6368;">No payees found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payees as $p): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700; color:#202124; text-transform:uppercase; font-size:14px;"><?= h($p['name']) ?></div>
                                        <div style="font-size:12px; color:#70757a; margin-top:2px;"><?= h($p['contact_details'] ?: 'No contact info') ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="badge badge-info"><?= h($p['type']) ?></span>
                                    </td>
                                    <td style="text-align:center; color:#5f6368; font-size:14px;">
                                        <?= h($p['tin'] ?: '-') ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <div class="action-cell">
                                            <a href="create.php?module=expenses&payee=<?= urlencode($p['name']) ?>" class="btn-record">+ Record</a>
                                            
                                            <button onclick='editPayee(<?= json_encode($p) ?>)' class="icon-btn" title="Edit Payee">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                </svg>
                                            </button>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this payee?')">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="icon-btn" title="Deactivate" style="color:#d93025;">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <line x1="18" y1="6" x2="6" y2="18"></line>
                                                        <line x1="6" y1="6" x2="18" y2="18"></line>
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="payeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">New Payee</h2>
                <button onclick="closeModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#5f6368;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="payeeId">

                <div class="form-group">
                    <label>Payee Name <span style="color:red">*</span></label>
                    <input type="text" name="name" id="payeeName" required class="form-control" placeholder="e.g. John Doe or Tech Solutions Ltd">
                </div>

                <div class="form-group">
                    <label>Type</label>
                    <select name="type" id="payeeType" class="form-control">
                        <option value="Supplier">Supplier</option>
                        <option value="Staff">Staff</option>
                        <option value="Service Provider">Service Provider</option>
                        <option value="Government">Government</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>TIN</label>
                    <input type="text" name="tin" id="payeeTin" class="form-control" placeholder="Tax Identification Number">
                </div>

                <div class="form-group">
                    <label>Contact Details</label>
                    <textarea name="contact_details" id="payeeContact" class="form-control" rows="3" placeholder="Phone, Email, Address..."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:10px;">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Payee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('modalTitle').innerText = 'New Payee';
            document.getElementById('formAction').value = 'create';
            document.getElementById('payeeId').value = '';
            document.getElementById('payeeName').value = '';
            document.getElementById('payeeType').value = 'Supplier';
            document.getElementById('payeeTin').value = '';
            document.getElementById('payeeContact').value = '';
            document.getElementById('payeeModal').classList.add('open');
        }

        function editPayee(payee) {
            document.getElementById('modalTitle').innerText = 'Edit Payee';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('payeeId').value = payee.id;
            document.getElementById('payeeName').value = payee.name;
            document.getElementById('payeeType').value = payee.type;
            document.getElementById('payeeTin').value = payee.tin || '';
            document.getElementById('payeeContact').value = payee.contact_details || '';
            document.getElementById('payeeModal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('payeeModal').classList.remove('open');
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('payeeModal')) {
                closeModal();
            }
        }
    </script>
    <script>
        function toggleHeaderMenu() {
            if (window.innerWidth < 1024) {
                document.body.classList.toggle('sidebar-mobile-open');
            } else {
                const nowCollapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', nowCollapsed);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('sidebarToggle');
            const body = document.body;
            
            // Load initial state
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                body.classList.add('sidebar-collapsed');
            }
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleHeaderMenu);
            }
        });
    </script>
</body>
</html>
