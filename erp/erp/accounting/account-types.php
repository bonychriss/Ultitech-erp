<?php require_once '../../includes/functions.php';  global $pdo;
// Ensure table for account types
$pdo->exec("CREATE TABLE IF NOT EXISTS erp_account_types (\n    id INT AUTO_INCREMENT PRIMARY KEY,\n    name VARCHAR(50) UNIQUE NOT NULL,\n    status VARCHAR(20) NOT NULL DEFAULT 'active',\n    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Seed defaults if empty
$count = (int)$pdo->query("SELECT COUNT(*) FROM erp_account_types")->fetchColumn();
if ($count === 0) {
    $defaults = ['asset','liability','equity','revenue','expense'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO erp_account_types (name, status) VALUES (?, 'active')");
    foreach ($defaults as $d) { $stmt->execute([$d]); }
}
// Handle actions
$action = $_POST['action'] ?? '';
if ($action === 'create') {
    $name = trim(strtolower($_POST['name'] ?? ''));
    if ($name === '') { setFlash('error', 'Type name is required'); header('Location: account-types.php'); exit; }
    try { $stmt = $pdo->prepare("INSERT INTO erp_account_types (name, status) VALUES (?, 'active')"); $stmt->execute([$name]); setFlash('success', 'Type created'); } catch (Throwable $e) { setFlash('error', 'Could not create type: ' . $e->getMessage()); }
    header('Location: account-types.php'); exit;
}
if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT status FROM erp_account_types WHERE id = ?"); $stmt->execute([$id]); $t = $stmt->fetch();
    if ($t) { $new = ($t['status'] === 'active') ? 'inactive' : 'active'; $up = $pdo->prepare("UPDATE erp_account_types SET status = ? WHERE id = ?"); $up->execute([$new, $id]); setFlash('success', 'Type updated'); }
    header('Location: account-types.php'); exit;
}
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    // Prevent delete if any accounts use this type
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_accounts WHERE type = (SELECT name FROM erp_account_types WHERE id = ?)"); $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) { setFlash('error', 'Cannot delete: type in use'); header('Location: account-types.php'); exit; }
    $del = $pdo->prepare("DELETE FROM erp_account_types WHERE id = ?"); $del->execute([$id]); setFlash('success', 'Type deleted'); header('Location: account-types.php'); exit;
}
$types = $pdo->query("SELECT * FROM erp_account_types ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Account Types - ERP</title>
<link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
<style>
.page-wrapper { margin-left: 220px; min-height: 100vh; }
.container { padding: 24px; }
.card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; }
.card-body { padding: 16px; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
.badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; }
.badge-active { background: #e6f4ea; color: #137333; }
.badge-inactive { background: #fce8e6; color: #c5221f; }
.btn { padding: 8px 12px; border-radius: 4px; border: 1px solid #dadce0; background: #fff; cursor: pointer; color: #000; }
.btn-primary { background: #1a73e8; color: #000; border: none; }
.btn-secondary { color: #000; }
.header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
/* Keep simple: remove blue hover effects */
.btn:hover { background: inherit; color: inherit; border-color: inherit; }
a { color: inherit; text-decoration: none; }
a:hover { color: inherit; text-decoration: none; }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
  <div style="padding: 16px 24px 0; text-align: right;"><a href="create-account.php" class="btn">Back</a></div>
  <div class="container">
    <?php $f = getFlash(); if ($f) { echo '<div class="card"><div class="card-body">' . htmlspecialchars($f['message']) . '</div></div>'; } ?>
    <div class="card" style="margin-bottom: 16px;">
      <div class="card-body">
        <form method="post" style="display:flex; gap:8px; align-items:center;">
          <input type="hidden" name="action" value="create">
          <input type="text" name="name" placeholder="New type (e.g. other)" required style="flex:1;">
          <button type="submit" class="btn btn-primary">Add Type</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <table class="table">
          <thead><tr><th>Name</th><th>Status</th><th style="width:200px;">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($types as $t): ?>
            <tr>
              <td><?= htmlspecialchars($t['name']) ?></td>
              <td>
                <?php $s = strtolower($t['status'] ?? 'inactive'); $cls = $s === 'active' ? 'badge-active' : 'badge-inactive'; ?>
                <span class="badge <?= $cls ?>"><?= htmlspecialchars($s) ?></span>
              </td>
              <td>
                <form method="post" style="display:inline-block;">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn">Toggle</button>
                </form>
                <form method="post" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('Delete this type?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn">Delete</button>
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

