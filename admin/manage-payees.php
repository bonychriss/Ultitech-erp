<?php
require_once '../includes/functions.php';
requireAdmin();

$success = '';
$error = '';

// Handle Create/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    
                    // Auto-link to Stocks Suppliers if type is Supplier
                    if ($type === 'Supplier') {
                        // Check if exists in stocks_suppliers
                        $chk = $pdo->prepare("SELECT id FROM stocks_suppliers WHERE name = ?");
                        $chk->execute([$name]);
                        if (!$chk->fetchColumn()) {
                            $pdo->prepare("INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)")
                                ->execute([$name, $contact]);
                        }
                    }

                } else {
                    $id = (int) $_POST['id'];
                    $stmt = $pdo->prepare("UPDATE payees SET name=?, type=?, tin=?, contact_details=? WHERE id=?");
                    $stmt->execute([$name, $type, $tin, $contact, $id]);
                    $success = "Payee updated successfully.";

                    // Auto-update Stocks Supplier if exists and type is Supplier
                    if ($type === 'Supplier') {
                         /* 
                           We can't easily link ID-to-ID unless we stored payee_id in stocks_suppliers.
                           For now, we'll try to update by NAME or insert if missing, 
                           but updating name in payees might break the link if we rely on name.
                           Let's basically ensure it exists.
                        */
                        $chk = $pdo->prepare("SELECT id FROM stocks_suppliers WHERE name = ?");
                        $chk->execute([$name]);
                        $sId = $chk->fetchColumn();
                        if ($sId) {
                            $pdo->prepare("UPDATE stocks_suppliers SET contact_details=? WHERE id=?")->execute([$contact, $sId]);
                        } else {
                            $pdo->prepare("INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)")->execute([$name, $contact]);
                        }
                    }
                }
            } elseif ($_POST['action'] === 'delete') {
                $id = (int) $_POST['id'];
                // Soft delete or hard delete? Let's do toggle active for now to preserve history, 
                // but user asked for delete. Let's do soft delete 'is_active=0' effectively removing from lists.
                $stmt = $pdo->prepare("UPDATE payees SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Payee removed successfully.";
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
$stmt = $pdo->query("SELECT * FROM payees WHERE is_active = 1 ORDER BY name ASC");
$payees = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payees - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Compact Table Styles */
        .table-responsive {
            font-size: 13px;
            /* Smaller font */
        }

        .data-table th,
        .data-table td {
            padding: 6px 12px;
            /* Reduced padding */
            vertical-align: middle;
        }

        .dropdown-badge {
            font-size: 11px;
            padding: 2px 6px;
        }

        /* Action Buttons */
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #6b7280;
            transition: color 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            color: #111827;
        }

        .action-btn.edit:hover {
            color: #2563eb;
        }

        .action-btn.delete:hover {
            color: #dc2626;
        }

        /* Modal Styles (Existing + Tweaks) */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 450px;
            /* Slightly narrower */
            max-width: 90%;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .close {
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: #9ca3af;
        }

        .close:hover {
            color: #111827;
        }

        /* Header tweaks */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .page-header h1 {
            font-size: 20px;
        }

        .btn-primary {
            font-size: 13px;
            padding: 6px 14px;
        }
    </style>
</head>

<body class="dashboard">
    <?php require_once '../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Manage Payees</h1>
            <button onclick="openModal()" class="btn btn-primary">
                <span style="margin-right:4px;">+</span> Add New Payee
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success" style="padding: 8px 12px; font-size: 13px;"><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="padding: 8px 12px; font-size: 13px;"><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="data-table" style="width:100%; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="background:#1f2937;">
                        <th style="font-weight:600; color:#ffffff; border-bottom:1px solid #374151;">Name</th>
                        <th style="font-weight:600; color:#ffffff; border-bottom:1px solid #374151;">Type</th>
                        <th style="font-weight:600; color:#ffffff; border-bottom:1px solid #374151;">TIN</th>
                        <th style="font-weight:600; color:#ffffff; border-bottom:1px solid #374151;">Contact</th>
                        <th style="font-weight:600; color:#ffffff; border-bottom:1px solid #374151; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payees as $p): ?>
                        <tr style="border-bottom:1px solid #f3f4f6; cursor: pointer;" onclick='editPayee(<?= json_encode($p) ?>)'>
                            <td style="border-bottom:1px solid #f3f4f6; color:#111827; font-weight:500;">
                                <?= htmlspecialchars($p['name']) ?></td>
                            <td style="border-bottom:1px solid #f3f4f6;">
                                <span class="payee-type-badge"
                                    style="background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:500;">
                                    <?= htmlspecialchars($p['type']) ?>
                                </span>
                            </td>
                            <td style="border-bottom:1px solid #f3f4f6; color:#6b7280;">
                                <?= htmlspecialchars($p['tin'] ?? '-') ?></td>
                            <td style="border-bottom:1px solid #f3f4f6; color:#6b7280;">
                                <?= htmlspecialchars($p['contact_details'] ?? '-') ?></td>
                            <td style="border-bottom:1px solid #f3f4f6; text-align:right; white-space:nowrap;" onclick="event.stopPropagation()">
                                <button class="action-btn edit" onclick='editPayee(<?= json_encode($p) ?>)' title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <form method="POST" style="display:inline;"
                                    onsubmit="return confirm('Are you sure you want to remove this payee?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="action-btn delete" title="Delete">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path
                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                            </path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal -->
    <div id="payeeModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="font-size:18px; margin-bottom:16px;">Add Payee</h2>
            <form method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="payeeId">

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-size:13px; font-weight:500; margin-bottom:4px; display:block;">Name *</label>
                    <input type="text" name="name" id="payeeName" required class="form-control"
                        style="width:100%; padding:8px; font-size:13px; border:1px solid #d1d5db; border-radius:4px;">
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-size:13px; font-weight:500; margin-bottom:4px; display:block;">Type</label>
                    <select name="type" id="payeeType" class="form-control"
                        style="width:100%; padding:8px; font-size:13px; border:1px solid #d1d5db; border-radius:4px;">
                        <option value="Supplier">Supplier</option>
                        <option value="Staff">Staff</option>
                        <option value="Service Provider">Service Provider</option>
                        <option value="Government">Government</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label style="font-size:13px; font-weight:500; margin-bottom:4px; display:block;">TIN
                        (Optional)</label>
                    <input type="text" name="tin" id="payeeTin" class="form-control"
                        style="width:100%; padding:8px; font-size:13px; border:1px solid #d1d5db; border-radius:4px;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-size:13px; font-weight:500; margin-bottom:4px; display:block;">Contact
                        Details</label>
                    <textarea name="contact_details" id="payeeContact" class="form-control" rows="2"
                        style="width:100%; padding:8px; font-size:13px; border:1px solid #d1d5db; border-radius:4px;"></textarea>
                </div>

                <div class="form-actions"
                    style="margin-top: 20px; text-align: right; display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closeModal()" class="btn"
                        style="background:#fff; border:1px solid #d1d5db; color:#374151; padding:6px 12px; border-radius:4px; font-size:13px; cursor:pointer;">Cancel</button>
                    <button type="submit" class="btn btn-primary"
                        style="background:#E4002B; color:#fff; border:none; padding:6px 16px; border-radius:4px; font-size:13px; cursor:pointer;">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('payeeModal');

        function openModal() {
            document.getElementById('modalTitle').innerText = 'Add Payee';
            document.getElementById('formAction').value = 'create';
            document.getElementById('payeeId').value = '';
            document.getElementById('payeeName').value = '';
            document.getElementById('payeeType').value = 'Supplier';
            document.getElementById('payeeTin').value = '';
            document.getElementById('payeeContact').value = '';
            modal.style.display = "block";
        }

        function editPayee(payee) {
            document.getElementById('modalTitle').innerText = 'Edit Payee';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('payeeId').value = payee.id;
            document.getElementById('payeeName').value = payee.name;
            document.getElementById('payeeType').value = payee.type;
            document.getElementById('payeeTin').value = payee.tin;
            document.getElementById('payeeContact').value = payee.contact_details;
            modal.style.display = "block";
        }

        function closeModal() {
            modal.style.display = "none";
        }

        window.onclick = function (event) {
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>

</html>