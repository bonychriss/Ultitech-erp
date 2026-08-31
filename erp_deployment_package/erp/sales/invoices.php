<?php
require_once '../../includes/functions.php';
requireLogin();

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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; }
        
        .filters { display: flex; gap: 12px; margin-bottom: 20px; }
        .search-box { flex: 1; max-width: 400px; }
        .search-box input { width: 100%; padding: 10px 16px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .filter-select { padding: 10px 16px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; background: white; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“„ Invoices</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
            <a href="create-invoice.php" class="btn btn-primary">+ New Invoice</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search invoices..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            
            <?php if (empty($invoices)): ?>
                <div style="text-align: center; padding: 64px 24px; color: #5f6368;">
                    <div style="font-size: 4rem; margin-bottom: 16px;">ðŸ“„</div>
                    <h3>No invoices found</h3>
                    <p>Create your first invoice to get started</p>
                    <a href="create-invoice.php" class="btn btn-primary" style="margin-top: 16px;">+ New Invoice</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong></td>
                                <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></td>
                                <td><?= $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '-' ?></td>
                                <td>TSh <?= number_format($invoice['total'], 2) ?></td>
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
                                    <a href="view-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

