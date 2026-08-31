<?php
require_once '../../includes/functions.php';
requireLogin();
global $pdo;
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? 'all';
$sql = "SELECT * FROM erp_accounts WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (code LIKE ? OR name LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam];
}
if ($type !== 'all') {
    $sql .= " AND type = ?";
    $params[] = $type;
}
$sql .= " ORDER BY code ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts - ERP</title>
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
        .badge-asset { background: #e8f0fe; color: #1967d2; }
        .badge-liability { background: #fce8e6; color: #c5221f; }
        .badge-equity { background: #f3e8fd; color: #9334e6; }
        .badge-revenue { background: #e6f4ea; color: #137333; }
        .badge-expense { background: #fef7e0; color: #b06000; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“Š Chart of Accounts</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
            <a href="create-account.php" class="btn btn-primary">+ New Account</a>
        </div>
    </div>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search accounts..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Types</option>
                        <option value="asset" <?= $type == 'asset' ? 'selected' : '' ?>>Assets</option>
                        <option value="liability" <?= $type == 'liability' ? 'selected' : '' ?>>Liabilities</option>
                        <option value="equity" <?= $type == 'equity' ? 'selected' : '' ?>>Equity</option>
                        <option value="revenue" <?= $type == 'revenue' ? 'selected' : '' ?>>Revenue</option>
                        <option value="expense" <?= $type == 'expense' ? 'selected' : '' ?>>Expenses</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accounts)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 32px; color: #5f6368;">No accounts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $acc): ?>
                            <tr>
                                <td style="font-family: monospace;"><?= htmlspecialchars($acc['code']) ?></td>
                                <td style="font-weight: 500;"><?= htmlspecialchars($acc['name']) ?></td>
                                <td><span class="badge badge-<?= $acc['type'] ?>"><?= ucfirst($acc['type']) ?></span></td>
                                <td><?= htmlspecialchars($acc['description'] ?? '-') ?></td>
                                <td>
                                    <?php if (!$acc['is_system']): ?>
                                        <a href="edit-account.php?id=<?= $acc['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">Edit</a>
                                    <?php else: ?>
                                        <span style="color: #5f6368; font-size: 0.75rem;">System</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

