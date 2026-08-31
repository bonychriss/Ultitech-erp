<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
ensureMultiCompanyControlSchema();

if (isset($control_pdo)) {
    $pdo = $control_pdo;
}

$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$isCompanyAdmin = ($role === 'company_admin');
$isAllowed = $isCompanyAdmin || isAdmin() || isSuperAdmin();
if (!$isAllowed) {
    http_response_code(403);
    die('Access denied.');
}

$sessionCompanyId = (int) (currentCompanyId() ?? 0);
$targetCompanyId = (int) ($_GET['company_id'] ?? $sessionCompanyId);
if (!isSuperAdmin()) {
    $targetCompanyId = $sessionCompanyId;
}
if ($targetCompanyId <= 0) {
    die('Company is required.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_employee') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $department = trim((string) ($_POST['department'] ?? 'General'));
            $userRole = trim((string) ($_POST['role'] ?? 'employee'));
            if ($fullName === '' || $email === '' || $username === '' || $password === '') {
                throw new RuntimeException('Full name, email, username, and password are required.');
            }
            if (($emailErr = validateNewUserEmailForIndex($email)) !== null) {
                throw new RuntimeException($emailErr);
            }
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(TRIM(email)) = ?");
            $dupStmt->execute([$username, normalizeLoginEmail($email)]);
            if ((int) $dupStmt->fetchColumn() > 0) {
                throw new RuntimeException('This email is already registered. Please use another email.');
            }
            $approvalStatus = 'approved';
            if (columnExists('companies', 'require_admin_approval_for_new_users')) {
                $chk = $pdo->prepare("SELECT require_admin_approval_for_new_users FROM companies WHERE id = ?");
                $chk->execute([$targetCompanyId]);
                $approvalRequired = (int) ($chk->fetchColumn() ?? 1);
                $approvalStatus = $approvalRequired === 1 ? 'pending' : 'approved';
            }
            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $insertCols = ['username', 'password', 'full_name', 'email'];
            $insertVals = [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email];
            if (in_array('role', $cols, true)) { $insertCols[] = 'role'; $insertVals[] = $userRole; }
            if (in_array('department', $cols, true)) { $insertCols[] = 'department'; $insertVals[] = $department; }
            if (in_array('company_id', $cols, true)) { $insertCols[] = 'company_id'; $insertVals[] = $targetCompanyId; }
            if (in_array('is_active', $cols, true)) { $insertCols[] = 'is_active'; $insertVals[] = 1; }
            if (in_array('status', $cols, true)) { $insertCols[] = 'status'; $insertVals[] = 'active'; }
            if (in_array('approval_status', $cols, true)) { $insertCols[] = 'approval_status'; $insertVals[] = $approvalStatus; }
            if (in_array('created_by', $cols, true)) { $insertCols[] = 'created_by'; $insertVals[] = (int) ($_SESSION['user_id'] ?? 0); }
            $sql = "INSERT INTO users (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', array_fill(0, count($insertCols), '?')) . ")";
            $pdo->prepare($sql)->execute($insertVals);
            $newUserId = (int) $pdo->lastInsertId();
            if ($newUserId > 0) {
                syncUserCompanyIndex($targetCompanyId, $newUserId);
            }
            $message = 'Employee created successfully.';
        } elseif ($action === 'toggle_user_status') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newActive = (int) ($_POST['new_active'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }
            $q = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND company_id = ?");
            $q->execute([$newActive, $userId, $targetCompanyId]);
            syncUserCompanyIndex($targetCompanyId, $userId);
            $message = 'User status updated.';
        } elseif ($action === 'approve_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Invalid user.');
            }
            $q = $pdo->prepare("UPDATE users SET approval_status = 'approved', status = 'active', is_active = 1 WHERE id = ? AND company_id = ?");
            $q->execute([$userId, $targetCompanyId]);
            $message = 'User approved.';
        } elseif ($action === 'reset_password') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');
            if ($userId <= 0 || strlen($newPassword) < 8) {
                throw new RuntimeException('Provide a valid user and password (min 8 chars).');
            }
            $q = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND company_id = ?");
            $q->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $targetCompanyId]);
            $message = 'Password reset successfully.';
        } elseif ($action === 'regenerate_invite_code') {
            $newCode = 'CMP-' . date('y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $q = $pdo->prepare("UPDATE companies SET invite_code = ? WHERE id = ?");
            $q->execute([$newCode, $targetCompanyId]);
            $message = 'Invite code regenerated.';
        }
    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

$companyStmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$companyStmt->execute([$targetCompanyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    die('Company not found.');
}

$users = [];
$userSql = "SELECT id, username, full_name, email, role, department, is_active, status, approval_status, created_at FROM users WHERE company_id = ? ORDER BY id DESC";
try {
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$targetCompanyId]);
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Fallback for older schema missing status/approval columns
    $userStmt = $pdo->prepare("SELECT id, username, full_name, email, role, department, is_active, created_at FROM users WHERE company_id = ? ORDER BY id DESC");
    $userStmt->execute([$targetCompanyId]);
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Company Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/../includes/header_employee.php'; ?>
<main class="main-content p-4">
  <div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Company Users: <?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?></h3>
      <div class="d-flex gap-2">
        <a href="company-settings.php?company_id=<?= (int) $targetCompanyId ?>" class="btn btn-outline-secondary btn-sm">Company Settings</a>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="regenerate_invite_code">
          <button class="btn btn-outline-primary btn-sm">Generate Invite Code</button>
        </form>
      </div>
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
      <div class="card-header">Register Employee Manually</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="create_employee">
          <div class="col-md-4"><label class="form-label">Full Name</label><input class="form-control" name="full_name" required></div>
          <div class="col-md-4"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div>
          <div class="col-md-4"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
          <div class="col-md-4"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
          <div class="col-md-4"><label class="form-label">Role</label><input class="form-control" name="role" value="employee"></div>
          <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" name="department" value="General"></div>
          <div class="col-12"><button class="btn btn-primary">Create Employee</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Existing Company Users</div>
      <div class="card-body table-responsive">
        <p class="small text-muted">Invite link: <?= htmlspecialchars(app_url('/company/register-employee.php?code=' . (string) ($company['invite_code'] ?? ''))) ?></p>
        <table class="table table-sm align-middle">
          <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Approval</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= (int) ($u['id'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string) ($u['full_name'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($u['username'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($u['role'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($u['department'] ?? '')) ?></td>
              <td><?= ((int) ($u['is_active'] ?? 1) === 1) ? 'active' : 'inactive' ?></td>
              <td><?= htmlspecialchars((string) ($u['approval_status'] ?? 'approved')) ?></td>
              <td class="d-flex gap-1 flex-wrap">
                <form method="post">
                  <input type="hidden" name="action" value="toggle_user_status">
                  <input type="hidden" name="user_id" value="<?= (int) ($u['id'] ?? 0) ?>">
                  <input type="hidden" name="new_active" value="<?= ((int) ($u['is_active'] ?? 1) === 1) ? 0 : 1 ?>">
                  <button class="btn btn-outline-secondary btn-sm"><?= ((int) ($u['is_active'] ?? 1) === 1) ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php if (strtolower((string) ($u['approval_status'] ?? 'approved')) === 'pending'): ?>
                <form method="post">
                  <input type="hidden" name="action" value="approve_user">
                  <input type="hidden" name="user_id" value="<?= (int) ($u['id'] ?? 0) ?>">
                  <button class="btn btn-outline-success btn-sm">Approve</button>
                </form>
                <?php endif; ?>
                <form method="post" class="d-flex gap-1">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="user_id" value="<?= (int) ($u['id'] ?? 0) ?>">
                  <input class="form-control form-control-sm" type="password" name="new_password" placeholder="New password" required>
                  <button class="btn btn-outline-warning btn-sm">Reset</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
