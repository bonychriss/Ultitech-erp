<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$id = $_GET['id'] ?? 0;

// Get customer details
$stmt = $pdo->prepare("SELECT * FROM erp_customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    die("Customer not found");
}

// Get customer invoices
$stmt = $pdo->prepare("SELECT * FROM erp_invoices WHERE customer_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll();

// Get customer notes
$stmt = $pdo->prepare("SELECT n.*, u.full_name as user_name FROM erp_customer_notes n JOIN users u ON n.user_id = u.id WHERE customer_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$notes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($customer['name']) ?> - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 1200px; margin: 24px auto; padding: 0 24px; display: grid; grid-template-columns: 300px 1fr; gap: 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-weight: 600; font-size: 1rem; }
        .card-body { padding: 20px; }
        
        .info-group { margin-bottom: 16px; }
        .info-label { font-size: 0.75rem; color: #5f6368; text-transform: uppercase; margin-bottom: 4px; font-weight: 500; }
        .info-value { font-size: 0.95rem; color: #202124; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; font-size: 0.75rem; font-weight: 500; color: #5f6368; border-bottom: 1px solid #e0e0e0; }
        .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        
        .note-item { padding: 12px 0; border-bottom: 1px solid #f1f3f4; }
        .note-meta { font-size: 0.75rem; color: #5f6368; margin-bottom: 4px; }
        
        @media (max-width: 900px) { .container { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($customer['name']) ?></h1>
        <div>
            <a href="list.php" class="btn btn-secondary" style="margin-right: 8px;">Back</a>
            <a href="edit.php?id=<?= $customer['id'] ?>" class="btn btn-primary">Edit Customer</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Sidebar: Customer Info -->
        <div class="sidebar">
            <div class="card">
                <div class="card-body">
                    <div class="info-group">
                        <div class="info-label">Customer Code</div>
                        <div class="info-value"><?= htmlspecialchars($customer['customer_code']) ?></div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="badge <?= $customer['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                <?= ucfirst($customer['status']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($customer['email'] ?? '-') ?></div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= htmlspecialchars($customer['phone'] ?? '-') ?></div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?= nl2br(htmlspecialchars($customer['address'] ?? '-')) ?></div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Tax ID</div>
                        <div class="info-value"><?= htmlspecialchars($customer['tax_id'] ?? '-') ?></div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-label">Balance</div>
                        <div class="info-value" style="font-size: 1.25rem; font-weight: 600; color: <?= $customer['balance'] > 0 ? '#c5221f' : '#137333' ?>">
                            TSh <?= number_format($customer['balance'], 2) ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Notes</div>
                    <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;" onclick="addNote()">+ Add</button>
                </div>
                <div class="card-body">
                    <?php if (empty($notes)): ?>
                        <div style="color: #5f6368; font-size: 0.875rem;">No notes added yet.</div>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="note-item">
                                <div class="note-meta"><?= htmlspecialchars($note['user_name']) ?> â€¢ <?= date('M d, Y', strtotime($note['created_at'])) ?></div>
                                <div><?= nl2br(htmlspecialchars($note['note'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Main Content: Invoices & Activity -->
        <div class="main">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Invoices & Transactions</div>
                    <a href="../sales/create-invoice.php?customer_id=<?= $customer['id'] ?>" class="btn btn-primary">+ New Invoice</a>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php if (empty($invoices)): ?>
                        <div style="padding: 32px; text-align: center; color: #5f6368;">
                            No invoices found for this customer.
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td><a href="../sales/view-invoice.php?id=<?= $invoice['id'] ?>" style="color: #1a73e8; text-decoration: none; font-weight: 500;"><?= htmlspecialchars($invoice['invoice_number']) ?></a></td>
                                        <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                        <td><?= $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '-' ?></td>
                                        <td><?= number_format($invoice['total'], 2) ?></td>
                                        <td><?= number_format($invoice['balance'], 2) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = [
                                                'draft' => 'badge-info',
                                                'sent' => 'badge-warning',
                                                'paid' => 'badge-success',
                                                'partial' => 'badge-warning',
                                                'overdue' => 'badge-danger',
                                                'cancelled' => 'badge-danger'
                                            ];
                                            ?>
                                            <span class="badge <?= $statusClass[$invoice['status']] ?? 'badge-info' ?>">
                                                <?= ucfirst($invoice['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../sales/view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function addNote() {
            const note = prompt('Enter note:');
            if (note) {
                // Implement AJAX call to add note
                alert('Note feature coming soon!');
            }
        }
    </script>
</body>
</html>

