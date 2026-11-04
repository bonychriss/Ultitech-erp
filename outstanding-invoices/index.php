<?php
require_once '../../includes/functions.php';
requireLogin();
ensureOutstandingInvoicesSchema();
$rootPath = '../../';
$modulesLink = '../../select-module.php';
$logoBase = '../../';

$success = $error = '';

// Handle Actions (Create, Delete/Pay)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_invoice'])) {
        $type = $_POST['type'] ?? 'receivable'; // receivable or payable
        $date = $_POST['invoice_date'] ?? date('Y-m-d');
        $name = trim($_POST['entity_name'] ?? '');
        $invoiceNo = trim($_POST['invoice_number'] ?? '');
        $narration = trim($_POST['narration'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        
        // Handle Attachment Upload
        $attachmentPath = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../assets/uploads/invoices/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $newFilename = uniqid('inv_') . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $newFilename)) {
                $attachmentPath = 'assets/uploads/invoices/' . $newFilename;
            }
        }

        if (empty($name) || $amount <= 0) {
            $error = 'Please provide a valid Name and Amount.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_outstanding_invoices (type, invoice_date, entity_name, invoice_number, narration, amount, attachment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$type, $date, $name, $invoiceNo, $narration, $amount, $attachmentPath])) {
                $success = 'Invoice added successfully.';
            } else {
                $error = 'Failed to add invoice.';
            }
        }
    }
    
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    if (isset($_POST['mark_paid'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can mark invoices as paid.';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = 'Invoice marked as paid and removed.';
            }
        }
    }

    // Delete Invoice
    if (isset($_POST['delete_invoice'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can delete invoices.';
        } else {
            $id = (int)$_POST['id'];
            // Get attachment to delete file if exists
            $stmt = $pdo->prepare("SELECT attachment FROM erp_outstanding_invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                if ($inv && !empty($inv['attachment']) && file_exists('../../' . $inv['attachment'])) {
                    @unlink('../../' . $inv['attachment']);
                }
                $success = 'Invoice deleted successfully.';
            }
        }
    }
}

// Fetch Data
// Default tab: Receivables
$activeTab = $_GET['tab'] ?? 'receivables';
$typeFilter = ($activeTab === 'payables') ? 'payable' : 'receivable';

$stmt = $pdo->prepare("SELECT * FROM erp_outstanding_invoices WHERE type = ? AND status = 'outstanding' ORDER BY invoice_date DESC");
$stmt->execute([$typeFilter]);
$invoices = $stmt->fetchAll();

// Calculate Total Outstanding
$totalOutstanding = 0;
foreach ($invoices as $inv) {
    $totalOutstanding += $inv['amount'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Invoices - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        body { background: #f3f4f6; }
        .main-content { max-width: 1200px; margin: 0 auto; padding: 12px 20px 20px; }
        .outstanding-invoice-header-actions {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .new-invoice-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }
        .new-invoice-btn:hover { background: #1d4ed8; }
        .oi-invoice-picker {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(560px, 72vw);
            margin: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            padding: 18px;
            z-index: 20;
            animation: oi-panel-pop 0.18s ease-out;
        }
        .oi-invoice-picker[hidden] { display: none; }
        .oi-invoice-picker-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        @keyframes oi-panel-pop {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .oi-invoice-picker-card {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            text-decoration: none;
            color: #0f172a;
            transition: 0.2s ease;
        }
        .oi-invoice-picker-card.active {
            border-color: #93c5fd;
            background: #eff6ff;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.08);
        }
        .oi-invoice-picker-card:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        .oi-picker-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .oi-picker-icon--in { background: #dcfce7; color: #15803d; }
        .oi-picker-icon--out { background: #fee2e2; color: #b91c1c; }
        .oi-invoice-picker-card strong {
            display: block;
            font-size: 1rem;
            margin-bottom: 4px;
        }
        .oi-invoice-picker-card small {
            color: #475569;
            line-height: 1.4;
            display: block;
        }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; }
        .tab-link { padding: 10px 20px; text-decoration: none; color: #6b7280; border-bottom: 2px solid transparent; font-weight: 500; }
        .tab-link.active { color: #2563eb; border-bottom-color: #2563eb; }
        .tab-link:hover { color: #111; }
        
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 5px; color: #374151; }
        .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; }
        .btn-submit { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 500; }
        .btn-submit:hover { background: #1d4ed8; }
        
        .invoice-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .invoice-table th, .invoice-table td { text-align: left; padding: 12px; border-bottom: 1px solid #e5e7eb;font-size: 0.9rem; }
        .invoice-table th { background: #f9fafb; font-weight: 600; color: #374151; }
        .invoice-table tr:hover { background: #f9fafb; }
        
        .amount-col { font-weight: 600; color: #111; text-align: right !important; }
        .actions-col { text-align: right !important; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 500; }
        .badge-att { background: #e0f2fe; color: #0369a1; text-decoration: none; }
        
        .total-box { font-size: 1.1rem; font-weight: bold; text-align: right; margin-top: 10px; color: #059669; }
        
        .btn-pay { background: transparent; border: 1px solid #10b981; color: #10b981; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; }
        .btn-pay:hover { background: #10b981; color: #fff; }
    </style>
</head>
<body>
    <?php
    $employeeHeaderTitle = 'Outstanding Invoices';
    $employeeHeaderRightHtml = '
        <div class="outstanding-invoice-header-actions">
            <button type="button" class="new-invoice-btn" data-toggle-target="new-outstanding-invoice-panel" aria-expanded="false">New Outstanding Invoice</button>
            <section class="oi-invoice-picker" id="new-outstanding-invoice-panel" hidden>
                <div class="oi-invoice-picker-grid">
                    <a href="' . oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'receivables' ? 'active' : '') . '">
                        <span class="oi-picker-icon oi-picker-icon--in">↗</span>
                        <div>
                            <strong>Receivables</strong>
                            <small>Track customer invoices still waiting to be collected.</small>
                        </div>
                    </a>
                    <a href="' . oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-invoice-picker-card ' . ($activeTab === 'payables' ? 'active' : '') . '">
                        <span class="oi-picker-icon oi-picker-icon--out">↘</span>
                        <div>
                            <strong>Payables</strong>
                            <small>Track supplier invoices still waiting to be paid.</small>
                        </div>
                    </a>
                </div>
            </section>
        </div>
    ';
    include '../../includes/header_employee.php';
    ?>

        <?php if ($success): ?><div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="tabs">
            <a href="?tab=receivables" class="tab-link <?= $activeTab === 'receivables' ? 'active' : '' ?>">Receivables (Income)</a>
            <a href="?tab=payables" class="tab-link <?= $activeTab === 'payables' ? 'active' : '' ?>">Payables (Expenses)</a>
        </div>

        <!-- Add New Form -->
        <div class="card" id="new-outstanding-invoice-form">
            <h3 style="font-size:1rem; margin-bottom:15px; color:#374151;">Add <?= $activeTab === 'receivables' ? 'Receivable' : 'Payable' ?></h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="create_invoice" value="1">
                <input type="hidden" name="type" value="<?= $typeFilter ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= $activeTab === 'receivables' ? 'Customer Name' : 'Supplier Name' ?></label>
                        <input type="text" name="entity_name" placeholder="e.g. John Doe / ABC Corp" required>
                    </div>
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" name="invoice_number" placeholder="e.g. INV-001">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="narration" placeholder="Details of goods/service">
                    </div>
                    <div class="form-group">
                        <label>Amount (TZS)</label>
                        <input type="number" step="0.01" name="amount" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Attachment (Invoice)</label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="form-group" style="padding-bottom:1px;">
                        <button type="submit" class="btn-submit">+ Add Invoice</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- List -->
        <div class="card">
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Invoice No</th>
                        <th>Description</th>
                        <th>Attachment</th>
                        <th class="amount-col">Amount</th>
                        <th class="actions-col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:#6b7280;">No outstanding invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td><?= htmlspecialchars($inv['entity_name']) ?></td>
                            <td><?= htmlspecialchars($inv['invoice_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($inv['narration']) ?></td>
                            <td>
                                <?php if (!empty($inv['attachment'])): ?>
                                    <a href="../../<?= htmlspecialchars($inv['attachment']) ?>" target="_blank" class="badge badge-att">View File</a>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="amount-col"><?= number_format($inv['amount'], 2) ?></td>
                            <td class="actions-col">
                                <?php if (isFinance()): ?>
                                <div style="display:flex; gap:8px; justify-content:flex-end;">
                                    <form method="POST" onsubmit="return confirm('Mark this invoice as Paid?');" style="display:inline;">
                                        <input type="hidden" name="mark_paid" value="1">
                                        <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="btn-pay">Mark Paid</button>
                                    </form>
                                    
                                    <button type="button" onclick="confirmDelete(<?= $inv['id'] ?>)" class="icon-btn icon-danger" style="border:1px solid #fee2e2; background:#fff; width:28px; height:28px; border-radius:4px;" title="Delete">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        </svg>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (!empty($invoices)): ?>
                <div class="total-box">
                    Total Outstanding: <?= number_format($totalOutstanding, 2) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Custom Confirmation Modal -->
    <div id="deleteModal" class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3>Delete Invoice?</h3>
            <p>Are you sure you want to permanently delete this invoice? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn-cancel">Cancel</button>
                <form method="POST" id="deleteForm" style="display:inline;">
                    <input type="hidden" name="delete_invoice" value="1">
                    <input type="hidden" id="delete_id" name="id" value="">
                    <button type="submit" class="btn-delete">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>

    <style>
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .custom-modal {
            background: white;
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalPop 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes modalPop {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .modal-icon {
            width: 48px;
            height: 48px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .custom-modal h3 {
            margin: 0 0 8px;
            font-size: 1.125rem;
            color: #111827;
        }
        .custom-modal p {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .btn-cancel {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: white;
            color: #374151;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-cancel:hover { background: #f3f4f6; }
        .btn-delete {
            padding: 8px 16px;
            border: none;
            background: #dc2626;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-delete:hover { background: #b91c1c; }
    </style>

    <script>
        function confirmDelete(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        // Close on clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeleteModal();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const trigger = document.querySelector('.new-invoice-btn');
            const panel = document.getElementById('new-outstanding-invoice-panel');

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', function () {
                const isHidden = panel.hidden;
                panel.hidden = !isHidden;
                trigger.setAttribute('aria-expanded', String(!isHidden));
            });
        });
    </script>

</body>
</html>
