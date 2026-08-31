<?php
require_once '../includes/functions.php';
requireLogin();
global $pdo;

// Handle actions
$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['account_type'] ?? '';
        $fs = $_POST['financial_statement'] ?? '';
        
        if ($name && $type && $fs) {
            try {
                $stmt = $pdo->prepare("INSERT INTO erp_account_categories (name, account_type, financial_statement) VALUES (?, ?, ?)");
                $stmt->execute([$name, $type, $fs]);
                $feedback = 'Category created successfully.';
            } catch (Exception $e) {
                $feedback = 'Error: ' . $e->getMessage();
            }
        } else {
            $feedback = 'Error: All fields are required.';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM erp_account_categories WHERE id = ?");
            $stmt->execute([$id]);
            $feedback = 'Category deleted successfully.';
        } catch (Exception $e) {
            $feedback = 'Error: ' . $e->getMessage();
        }
    }
}

$categories = $pdo->query("SELECT * FROM erp_account_categories ORDER BY account_type, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Account Categories - ERP</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-wrapper { margin-left: 220px; min-height: 100vh; background: #f8fafc; }
        .container { padding: 32px; max-width: 1000px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .card-header h2 { font-size: 18px; font-weight: 600; color: #1e293b; margin: 0; }
        .card-body { padding: 24px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 13px; font-weight: 600; color: #64748b; }
        .form-control { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #334155; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
        .badge-asset { background: #dcfce7; color: #166534; }
        .badge-liability { background: #fee2e2; color: #991b1b; }
        .badge-equity { background: #fef9c3; color: #854d0e; }
        .badge-revenue { background: #dbeafe; color: #1e40af; }
        .badge-expense { background: #ffedd5; color: #9a3412; }
        
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; transition: all 0.2s; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: #fff; color: #ef4444; border: 1px solid #ef4444; padding: 6px 12px; font-size: 12px; }
        .btn-danger:hover { background: #ef4444; color: #fff; }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="page-wrapper">
        <div class="container">
            <div style="margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <a href="chart-of-accounts.php" style="color: #64748b;"><i class="fas fa-arrow-left"></i> Back</a>
                <h1 style="font-size: 24px; font-weight: 700; color: #0f172a; margin: 0;">Account Categories</h1>
            </div>

            <?php if ($feedback): ?>
                <div class="alert <?= strpos($feedback, 'Error') === 0 ? 'alert-error' : 'alert-success' ?>">
                    <?= htmlspecialchars($feedback) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-plus"></i> Add New Category</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Category Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Fixed Assets" required>
                            </div>
                            <div class="form-group">
                                <label>Account Type</label>
                                <select name="account_type" class="form-control" required>
                                    <option value="asset">Asset</option>
                                    <option value="liability">Liability</option>
                                    <option value="equity">Equity</option>
                                    <option value="revenue">Revenue</option>
                                    <option value="expense">Expense</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Financial Statement</label>
                                <select name="financial_statement" class="form-control" required>
                                    <option value="Balance Sheet">Balance Sheet</option>
                                    <option value="Profit & Loss">Profit & Loss</option>
                                    <option value="Cash Flow Statement">Cash Flow Statement</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Create Category</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Existing Categories</h2>
                </div>
                <div class="card-body" style="padding: 0;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Account Type</th>
                                <th>Financial Statement</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars($cat['account_type']) ?>">
                                            <?= htmlspecialchars($cat['account_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($cat['financial_statement']) ?></td>
                                    <td style="text-align: right;">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this category?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
