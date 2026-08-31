<?php
require_once '../includes/functions.php';
requireLogin();

if (!isCompanyAdmin() && !isSuperAdmin()) {
    header('Location: ../select-module.php?error=access_denied');
    exit;
}

$currentCompany = getCurrentCompany();
$companyId = (int)$currentCompany['id'];

$msg = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = (int)$_POST['user_id'];
    $action = $_POST['action'];

    try {
        // Verify user belongs to this company
        $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userCid = (int)$stmt->fetchColumn();

        if ($userCid !== $companyId && !isSuperAdmin()) {
            throw new Exception("Unauthorized access to employee record.");
        }

        switch ($action) {
            case 'toggle_status':
                $newStatus = (int)$_POST['status'];
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$newStatus, $userId]);
                syncUserCompanyIndex($companyId, $userId);
                $msg = "Employee status updated.";
                break;
            
            case 'delete':
                removeUserCompanyIndex($companyId, $userId, 'inactive');
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $msg = "Employee record deleted.";
                break;
            
            case 'add':
                $name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $password = $_POST['password'];
                $role = $_POST['role'] ?? 'employee';

                if (empty($name) || empty($email) || empty($password)) {
                    throw new Exception("All fields are required.");
                }

                $email = normalizeLoginEmail($email);
                if (($emailErr = validateNewUserEmailForIndex($email)) !== null) {
                    throw new Exception($emailErr);
                }

                // Check if email exists in tenant DB
                $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    throw new Exception('This email is already registered. Please use another email.');
                }

                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $username = strstr($email, '@', true) ?: $name;

                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, company_id, status, approval_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', 'approved', NOW())");
                $stmt->execute([$username, $hashed, $name, $email, $role, $companyId]);
                $newUserId = (int) $pdo->lastInsertId();
                if ($newUserId > 0) {
                    syncUserCompanyIndex($companyId, $newUserId);
                }
                $msg = "Employee registered successfully.";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch Employees
$stmt = $pdo->prepare("SELECT * FROM users WHERE company_id = ? AND role != 'company_admin' ORDER BY full_name ASC");
$stmt->execute([$companyId]);
$employees = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - <?= h($currentCompany['company_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.3);
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #10b981;
            --danger: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 260px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--glass-border);
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-main);
            border-radius: 12px;
            transition: all 0.2s;
            font-weight: 500;
        }

        .nav-item:hover, .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
        }

        .section-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-2px); }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th {
            text-align: left;
            padding: 15px;
            color: var(--text-muted);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #e5e7eb;
        }

        td {
            padding: 15px;
            font-size: 15px;
            border-bottom: 1px solid #f3f4f6;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 24px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-family: inherit;
        }

        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="nav-item" style="font-weight: 700; color: var(--primary); margin-bottom: 20px;">
            <i class="fas fa-rocket"></i> OmmyERP
        </div>
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-th-large"></i> Dashboard
        </a>
        <a href="manage-employees.php" class="nav-item active">
            <i class="fas fa-users"></i> Employees
        </a>
        <a href="../select-module.php" class="nav-item">
            <i class="fas fa-th"></i> App Launcher
        </a>
        <a href="../logout.php" class="nav-item" style="margin-top: auto; color: #ef4444;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>

    <main class="main-content">
        <div class="header">
            <div>
                <h1 style="font-size: 28px; font-weight: 700;">Employee Directory</h1>
                <p style="color: var(--text-muted); font-size: 14px;">Manage your team and their system access</p>
            </div>
            <button class="btn btn-primary" onclick="openModal()">
                <i class="fas fa-plus"></i> Add Employee
            </button>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="section-card">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted);">No employees registered yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $e): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= h($e['full_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);"><?= h($e['email']) ?></div>
                                </td>
                                <td>
                                    <span class="badge" style="background: #f3f4f6; color: #374151;"><?= ucfirst($e['role']) ?></span>
                                </td>
                                <td>
                                    <?php if ($e['is_active']): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($e['created_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="status" value="<?= $e['is_active'] ? 0 : 1 ?>">
                                        <button type="submit" class="btn" style="padding: 5px; color: var(--text-muted);">
                                            <i class="fas <?= $e['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this employee record?')">
                                        <input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn" style="padding: 5px; color: var(--danger);">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Add Employee Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;">Register New Employee</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="John Doe" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="john@company.com" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required minlength="6">
                </div>
                <div class="form-group">
                    <label>System Role</label>
                    <select name="role" class="form-control">
                        <option value="employee">Standard Employee</option>
                        <option value="admin">Branch Admin</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <button type="button" class="btn" style="flex: 1; background: #f3f4f6;" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() { document.getElementById('addModal').style.display = 'flex'; }
        function closeModal() { document.getElementById('addModal').style.display = 'none'; }
        
        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) closeModal();
        }
    </script>
</body>
</html>
