<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Get all customers
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

$sql = "SELECT * FROM erp_customers WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR customer_code LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam, $searchParam, $searchParam];
}

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: #f5f5f5;
        }
        
        .header {
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 500;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .btn-primary {
            background: #1a73e8;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1557b0;
        }
        
        .btn-secondary {
            background: #fff;
            color: #202124;
            border: 1px solid #dadce0;
        }
        
        .btn-secondary:hover {
            background: #f8f9fa;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .search-box {
            flex: 1;
            max-width: 400px;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        
        .filter-select {
            padding: 10px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 0.875rem;
            background: white;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #5f6368;
            text-transform: uppercase;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        
        .table td {
            padding: 16px;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        
        .empty-state {
            text-align: center;
            padding: 64px 24px;
            color: #5f6368;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ‘¥ Customers</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
            <a href="create.php" class="btn btn-primary">+ Add Customer</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search customers..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            
            <?php if (empty($customers)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">ðŸ‘¥</div>
                    <h3>No customers found</h3>
                    <p>Start by adding your first customer</p>
                    <a href="create.php" class="btn btn-primary" style="margin-top: 16px;">+ Add Customer</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($customer['customer_code']) ?></strong></td>
                                <td><?= htmlspecialchars($customer['name']) ?></td>
                                <td><?= htmlspecialchars($customer['email'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                                <td>TSh <?= number_format($customer['balance'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $customer['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= ucfirst($customer['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= $customer['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View</a>
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

