<?php
require_once '../includes/functions.php';
requireLogin();
global $pdo;

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $cat = $_POST['category_name'] ?? '';
        if ($name && $cat) {
            try {
                $stmt = $pdo->prepare("INSERT INTO erp_reporting_groups (name, category_name) VALUES (?, ?)");
                $stmt->execute([$name, $cat]);
                $feedback = 'Reporting group created.';
            } catch (Exception $e) { $feedback = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM erp_reporting_groups WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            $feedback = 'Reporting group deleted.';
        } catch (Exception $e) { $feedback = 'Error: ' . $e->getMessage(); }
    }
}

$categories = $pdo->query("SELECT name FROM erp_account_categories WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$groups = $pdo->query("SELECT * FROM erp_reporting_groups ORDER BY category_name, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Reporting Groups - ERP</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-wrapper { margin-left: 220px; min-height: 100vh; background: #f8fafc; }
        .container { padding: 32px; max-width: 1000px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 24px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .form-control { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100%; box-sizing: border-box; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger { background: #fff; color: #ef4444; border: 1px solid #ef4444; padding: 6px 12px; }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="page-wrapper">
        <div class="container">
            <div style="margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #0f172a; margin: 0;">Reporting Groups</h1>
            </div>

            <?php if ($feedback): ?>
                <div style="padding: 12px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 20px;">
                    <?= htmlspecialchars($feedback) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h2>Add Reporting Group</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="form-grid">
                            <div><label>Group Name</label><input type="text" name="name" class="form-control" placeholder="e.g. VAT Payable" required></div>
                            <div><label>Parent Category</label>
                                <select name="category_name" class="form-control" required>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Group</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body" style="padding:0;">
                    <table class="table">
                        <thead><tr><th>Group Name</th><th>Parent Category</th><th style="text-align:right;">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($groups as $g): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($g['category_name']) ?></td>
                                    <td style="text-align:right;">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>">
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
