<?php
require_once '../../includes/functions.php';

global $pdo;

// Get all suppliers
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

$sql = "SELECT * FROM erp_suppliers WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR supplier_code LIKE ?)";
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
$suppliers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 100%; padding: 24px; }
        
        .page-wrapper {
            margin-left: 220px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0; }
        }
        
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
        .badge-danger { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
            <a href="create-supplier.php" class="btn btn-primary">+ Add Supplier</a>
        </div></div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search suppliers..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            
            <?php if (empty($suppliers)): ?>
                <div style="text-align: center; padding: 64px 24px; color: #5f6368;">
                    <div style="font-size: 4rem; margin-bottom: 16px;"><i class="fas fa-truck-loading"></i></div>
                    <h3>No suppliers found</h3>
                    <p>Start by adding your first supplier</p>
                    <a href="create-supplier.php" class="btn btn-primary" style="margin-top: 16px;">+ Add Supplier</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Contact Person</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($supplier['supplier_code']) ?></strong></td>
                                <td><?= htmlspecialchars($supplier['name']) ?></td>
                                <td><?= htmlspecialchars($supplier['contact_person'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($supplier['email'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($supplier['phone'] ?? '-') ?></td>
                                <td>
                                    <span class="badge <?= $supplier['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= ucfirst($supplier['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit-supplier.php?id=<?= $supplier['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">Edit</a>
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


