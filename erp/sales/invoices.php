<?php
require_once '../../includes/functions.php';

global $pdo;

// Get invoices
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

$sql = "SELECT i.*, c.name as customer_name 
        FROM erp_invoices i 
        JOIN erp_customers c ON i.customer_id = c.id 
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (i.invoice_number LIKE ? OR c.name LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam];
}

if ($status !== 'all') {
    $sql .= " AND i.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY i.created_at DESC";

$invoices = [];
$error_message = '';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
    if (defined('APP_ENV') && APP_ENV === 'production') {
        error_log($e->getMessage());
        $error_message = "Unable to load invoices. Please ensure the database is updated.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        
        /* Layout & Container - specific overrides */
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; padding: 10px !important; width: 100% !important; } }
        
        .header { background: transparent !important; margin-bottom: 20px; padding: 0 !important; display: flex !important; justify-content: space-between; align-items: center; border: none !important; }
        .header h2 { font-size: 1.75rem; font-weight: 600; color: #1f2937; margin: 0; }
        .header-actions { display: flex; gap: 12px; margin: 0 !important; }

        .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        
        /* Card: Flat, no padding on container itself */
        .card { background: white; border-radius: 0; border: none !important; overflow: visible; box-shadow: none !important; width: 100%; max-width: 100% !important; }
        
        .card-header { padding: 0 0 20px 0; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: transparent; }
        
        .table { width:100%; border-collapse:collapse; } 
        .table th { text-align:left; padding:12px 16px; font-size:0.8rem; font-weight:600; color:#4b5563; text-transform:uppercase; border-bottom:2px solid #e5e7eb; background:#f8f9fa; } 
        .table td { padding:16px; border-bottom:1px solid #f3f4f6; color: #1f2937; vertical-align: middle; }
        .table tr:hover { background:#f8fafc; } 
        
        .btn { padding:8px 16px; border-radius:6px; text-decoration:none; font-size:0.9rem; font-weight:500; cursor:pointer; border:none; display:inline-flex; align-items: center; gap: 6px; } 
        .btn-primary { background:#1a73e8; color:white; } 
        .btn-secondary { background:#fff; color:#374151; border:1px solid #d1d5db; } 
        .btn-success { background:#10b981; color:white; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-warning { background:#fef3c7; color:#d97706; } 
        .badge-success { background:#d1fae5; color:#059669; } 
        .badge-info { background:#dbeafe; color:#2563eb; } 
        .badge-danger { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="page-wrapper">
        <!-- Header -->
        <div class="header">
            <h2>Invoices</h2>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="create-invoice.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Invoice
                </a>
            </div>
        </div>
    
        <div class="container">
            <?php if ($error_message): ?>
                <div class="card" style="padding: 16px; background: #fee2e2; color: #dc2626; border-color: #fecaca; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <!-- Filter Toolbar -->
                <div class="card-header">
                    <form method="GET" style="display: flex; gap: 12px; width: 100%; align-items: center;">
                        <button type="submit" style="display: none;"></button> <!-- Implicit submit -->
                        
                        <div style="position: relative; width: 300px;">
                            <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                            <input type="text" name="search" placeholder="Search Number or Customer..." value="<?= htmlspecialchars($search) ?>" style="width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff;" onchange="this.form.submit()">
                        </div>
                        
                        <select name="status" style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; color: #374151; min-width: 150px;" onchange="this.form.submit()">
                            <option value="all">All Status</option>
                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        </select>
                    </form>
                </div>
                
                <?php if (empty($invoices)): ?>
                    <div style="text-align: center; padding: 64px 24px; color: #6b7280;">
                        <div style="font-size: 3rem; margin-bottom: 16px; color: #d1d5db;"><i class="fas fa-file-invoice"></i></div>
                        <h3 style="margin-bottom: 8px; font-weight: 500;">No invoices found</h3>
                        <p>Create your first invoice to get started</p>
                        <a href="create-invoice.php" class="btn btn-primary" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> New Invoice
                        </a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th style="text-align: right;">Amount</th>
                                <th>Status</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td style="font-weight: 500; font-family: monospace; color: #111827;"><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                    <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                    <td><?= $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '-' ?></td>
                                    <td style="font-weight: 600; text-align: right;">TSh <?= number_format($invoice['total'], 2) ?></td>
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
                                    <td style="text-align: center;">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn-icon" style="text-decoration: none; color: #6b7280; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s;" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <!-- Simple dropdown could go here -->
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>


