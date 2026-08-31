<?php
require_once '../../includes/functions.php';

global $pdo;

// Ensure categories table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Ensure 'status' column exists for legacy tables
    $chk = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_categories' AND COLUMN_NAME = 'status'");
    $chk->execute();
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE erp_categories ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
    }
    // Normalize any NULL statuses to 'active'
    $pdo->exec("UPDATE erp_categories SET status = 'active' WHERE status IS NULL");
} catch (Throwable $e) {
    // Surface minimal error for troubleshooting
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            if ($name === '') {
                throw new Exception('Category name is required');
            }
            $stmt = $pdo->prepare("INSERT INTO erp_categories (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description !== '' ? $description : null]);
            $success = 'Category created successfully';
        } elseif ($action === 'toggle' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $current = $pdo->prepare("SELECT status FROM erp_categories WHERE id = ?");
            $current->execute([$id]);
            $row = $current->fetch();
            if ($row) {
                $new = $row['status'] === 'active' ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE erp_categories SET status = ? WHERE id = ?");
                $upd->execute([$new, $id]);
                $success = 'Category status updated';
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            // Prevent delete if category in use
            $use = $pdo->prepare("SELECT COUNT(*) FROM erp_products WHERE category_id = ?");
            $use->execute([$id]);
            if ($use->fetchColumn() > 0) {
                throw new Exception('Cannot delete: category is used by products');
            }
            $del = $pdo->prepare("DELETE FROM erp_categories WHERE id = ?");
            $del->execute([$id]);
            $success = 'Category deleted';
        }
    } catch (Exception $ex) {
        $errors[] = $ex->getMessage();
    }
}

$categories = $pdo->query("SELECT * FROM erp_categories ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Categories - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; }
        .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        .container { max-width: 100%; padding: 24px; }
        .page-wrapper { margin-left: 220px; min-height: 100vh; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; } }
        .card { background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .card-body { padding: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; }
        .form-grid { display: grid; grid-template-columns: 1fr 2fr 120px; gap: 12px; align-items: end; }
        label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.875rem; }
        input, textarea, select { width: 100%; padding: 10px 12px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: #fff; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        .btn-danger { background: #dc3545; color: #fff; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 12px 16px; border-bottom: 1px solid #f1f3f4; vertical-align: middle; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-success { background: #e6f4ea; color: #137333; }
        .alert-error { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions">
            <a href="list.php" class="btn btn-secondary">â† Back to Products</a>
        </div></div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

        <div class="card" style="margin-bottom: 16px;">
            <div class="card-header"><strong>Create New Category</strong></div>
            <div class="card-body">
                <form method="POST" class="form-grid">
                    <div>
                        <label>Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Electronics">
                    </div>
                    <div>
                        <label>Description</label>
                        <input type="text" name="description" placeholder="Optional description">
                    </div>
                    <div>
                        <input type="hidden" name="action" value="create">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Add</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Existing Categories</strong></div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#5f6368; padding: 24px;">No categories yet. Create one above.</td></tr>
                        <?php else: foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td><?= htmlspecialchars($cat['description'] ?? '') ?></td>
                            <td>
                                <?php $st = isset($cat['status']) && $cat['status'] !== null && $cat['status'] !== '' ? $cat['status'] : 'active'; ?>
                                <span class="badge <?= $st === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= htmlspecialchars(ucfirst((string)$st)) ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-secondary">Toggle Status</button>
                                </form>
                                <form method="POST" style="display:inline-block; margin-left: 8px;" onsubmit="return confirm('Delete this category?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>

