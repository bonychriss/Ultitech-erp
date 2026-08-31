<?php
require_once '../includes/functions.php';
requireAdmin();

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $user_id = intval($_POST['user_id']);
        
        switch ($_POST['action']) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                $success = "User activated successfully.";
                break;
                
            case 'deactivate':
                // Prevent deactivating system admin
                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    if ($isSystemAdmin) {
                        $error = "Cannot deactivate system admin. This user is protected.";
                        break;
                    }
                }
                
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                $success = "User deactivated successfully.";
                break;
                
            // Removed make_admin action to prevent elevating users via this page
                
            case 'make_employee':
                // Prevent demoting system admin
                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    if ($isSystemAdmin) {
                        $error = "Cannot demote system admin. This user is protected.";
                        break;
                    }
                }
                
                $stmt = $pdo->prepare("UPDATE users SET role = 'employee' WHERE id = ?");
                $stmt->execute([$user_id]);
                $success = "User demoted to employee successfully.";
                break;
                
            case 'change_department':
                if (empty($_POST['department'])) {
                    $error = "Department cannot be empty.";
                    break;
                }
                
                // Prevent modifying system admin
                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    if ($isSystemAdmin) {
                        $error = "Cannot change department of system admin. This user is protected.";
                        break;
                    }
                }
                
                $newDept = $_POST['department'];
                $stmt = $pdo->prepare("UPDATE users SET department = ? WHERE id = ?");
                $stmt->execute([$newDept, $user_id]);
                $success = "User department updated to $newDept successfully.";
                break;
                
            case 'delete':
                // Prevent deletion of system admin
                $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    $error = "User not found.";
                    break;
                }
                
                // Check if this is the system admin
                $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                 strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                
                if ($isSystemAdmin) {
                    $error = "Cannot delete system admin. This user is protected.";
                    break;
                }
                
                // Check if user has any vouchers created by them
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM payment_vouchers WHERE created_by = ?");
                $stmt->execute([$user_id]);
                $voucher_count = $stmt->fetch()['count'];
                
                // Check if payment_vouchers.created_by constraint will block deletion
                // If user created vouchers, we need to handle this carefully
                // Note: created_by is NOT NULL, so we can't set it to NULL
                // We'll need to check if the database allows deletion or if we need to reassign
                
                // Delete user and handle foreign key constraints
                try {
                    $pdo->beginTransaction();
                    
                    // 1. Delete approval_logs records first (foreign key constraint without CASCADE)
                    // This is the main issue causing the error
                    try {
                        $stmt = $pdo->prepare("DELETE FROM approval_logs WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $deleted_logs = $stmt->rowCount();
                    } catch (Exception $e) {
                        // Table might not exist or no records - log but continue
                        error_log("Note: Could not delete approval_logs for user $user_id: " . $e->getMessage());
                        $deleted_logs = 0;
                    }
                    
                    // 2. Set NULL for payment_vouchers fields that can be nullified
                    // (approved_by, paid_by, posted_by can be set to NULL)
                    try {
                        $stmt = $pdo->prepare("UPDATE payment_vouchers SET approved_by = NULL WHERE approved_by = ?");
                        $stmt->execute([$user_id]);
                        
                        $stmt = $pdo->prepare("UPDATE payment_vouchers SET paid_by = NULL WHERE paid_by = ?");
                        $stmt->execute([$user_id]);
                        
                        // Check if posted_by column exists and update it
                        try {
                            $stmt = $pdo->prepare("UPDATE payment_vouchers SET posted_by = NULL WHERE posted_by = ?");
                            $stmt->execute([$user_id]);
                        } catch (Exception $e) {
                            // Column might not exist, ignore
                        }
                    } catch (Exception $e) {
                        error_log("Note: Could not update payment_vouchers for user $user_id: " . $e->getMessage());
                    }
                    
                    // 3. Delete notifications for this user (if user_id is set, not audience-based)
                    try {
                        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                    } catch (Exception $e) {
                        // Table might not exist, ignore
                        error_log("Note: Could not delete notifications for user $user_id: " . $e->getMessage());
                    }
                    
                    // 4. Handle payment_vouchers.created_by constraint if user created vouchers
                    // Reassign vouchers to system admin to avoid foreign key constraint violation
                    if ($voucher_count > 0) {
                        try {
                            // Find system admin user ID
                            $stmt = $pdo->prepare("SELECT id FROM users WHERE (LOWER(username) = 'admin' OR LOWER(email) = 'admin@ultimatetrading.com') AND role = 'admin' LIMIT 1");
                            $stmt->execute();
                            $adminUser = $stmt->fetch();
                            
                            if ($adminUser && isset($adminUser['id'])) {
                                // Reassign vouchers created by this user to system admin
                                $stmt = $pdo->prepare("UPDATE payment_vouchers SET created_by = ? WHERE created_by = ?");
                                $stmt->execute([$adminUser['id'], $user_id]);
                            }
                        } catch (Exception $e) {
                            error_log("Error reassigning vouchers for user $user_id: " . $e->getMessage());
                            // Continue - if deletion fails due to created_by constraint, error will be caught
                        }
                    }
                    
                    // 5. Delete the user
                    // Note: Tables with ON DELETE CASCADE will be handled automatically:
                    // - attendance (user_id)
                    // - voucher_attachments (uploaded_by)
                    // - messages (sender_id, recipient_id)
                    // - chat_group_members (user_id)
                    // - chat_group_reads (user_id)
                    // - message_reactions (user_id)
                    // - etc.
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    $pdo->commit();
                    
                    $success = "User deleted successfully.";
                    if ($deleted_logs > 0) {
                        $success .= " Deleted $deleted_logs approval log(s).";
                    }
                    if ($voucher_count > 0) {
                        $success .= " Note: User had $voucher_count voucher(s) created. Vouchers remain in the system but approval/paid/posted by fields have been cleared.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    
                    // Check if error is about created_by constraint
                    $errorMsg = $e->getMessage();
                    if (strpos($errorMsg, 'created_by') !== false || strpos($errorMsg, 'payment_vouchers') !== false) {
                        $error = "Cannot delete user: User has created $voucher_count voucher(s). Please reassign or delete the vouchers first.";
                    } else {
                        $error = "Error deleting user: " . $errorMsg;
                    }
                    error_log("User deletion error for user_id $user_id: " . $errorMsg);
                }
                break;
        }
    }
}

// Get all users with their voucher counts
$stmt = $pdo->prepare("
    SELECT u.*, 
           COUNT(pv.id) as voucher_count,
           SUM(CASE WHEN pv.status = 'approved' THEN pv.total_amount ELSE 0 END) as approved_amount
    FROM users u
    LEFT JOIN payment_vouchers pv ON u.id = pv.created_by
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Get department statistics
$stmt = $pdo->prepare("
    SELECT department, COUNT(*) as user_count
    FROM users 
    WHERE is_active = 1 AND role = 'employee'
    GROUP BY department
    ORDER BY user_count DESC
");
$stmt->execute();
$dept_stats = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Page-local compaction for manage-users */
        .main-content { font-size: 13px; }
        .actions .btn { padding: 6px 10px; font-size: 12px; }
        .dashboard-stats { gap: 10px; }
        .dashboard-stats .stat-card { padding: 10px 12px; }
        .dashboard-stats .stat-number { font-size: 18px; }
        .dashboard-stats .stat-label { font-size: 11px; }

        /* Search/filter controls */
        #searchInput { padding: 8px 10px !important; width: 260px !important; font-size: 12px; border-radius: 0; }
        select { padding: 8px 10px !important; font-size: 12px; border-radius: 0; }
        input, select, textarea, button { border-radius: 0; }

        /* Table density */
        table.data-table { font-size: 12px; }
        table.data-table th, table.data-table td { padding: 6px 8px; }
        table.data-table th { font-size: 12px; }
        .status-badge { font-size: 11px; padding: 2px 6px; }

        /* Action buttons inside table - styled as text links only */
        #usersTable button[onclick*="manageUser"],
        #usersTable button[onclick*="deleteUser"] {
            background: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            #searchInput { width: 100% !important; margin-bottom: 8px; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="actions">
            <a href="dashboard.php" class="icon-link icon-neutral" title="Back" aria-label="Back">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <a href="../register.php" class="btn">Register New Employee</a>
        </div>

        <?php if (isset($success)): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Department Statistics -->
        <div class="dashboard-stats">
            <?php foreach ($dept_stats as $dept): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $dept['user_count'] ?></div>
                <div class="stat-label"><?= htmlspecialchars($dept['department']) ?> Employees</div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="form-container">
            <h2>All System Users (<?= count($users) ?> total)</h2>
            
            <div style="margin-bottom: 20px;">
                <input type="text" id="searchInput" placeholder="Search users..." 
                       onkeyup="filterUserTable()" style="padding: 10px; width: 300px; margin-right: 10px;">
                <select onchange="filterUsersByRole(this.value)" style="padding: 10px; margin-right: 10px;">
                    <option value="all">All Roles</option>
                    <option value="admin">Admins Only</option>
                    <option value="employee">Employees Only</option>
                </select>
                <select onchange="filterUsersByStatus(this.value)" style="padding: 10px;">
                    <option value="all">All Status</option>
                    <option value="1">Active Only</option>
                    <option value="0">Inactive Only</option>
                </select>
            </div>

            <div class="table-wrap stacked-table">
            <table class="data-table" id="usersTable">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Vouchers</th>
                        <th>Total Approved</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr data-role="<?= $user['role'] ?>" data-status="<?= $user['is_active'] ?>">
                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td onclick='openDeptModal(<?= $user['id'] ?>, <?= json_encode($user['department'] ?: '') ?>, <?= json_encode($user['full_name']) ?>)' 
                            style="cursor: pointer; color: #2563eb; text-decoration: underline dashed; text-underline-offset: 4px;" title="Click to Change Department">
                            <?= htmlspecialchars($user['department']) ?> 
                            <svg style="width:12px; height:12px; vertical-align:middle; opacity:0.7;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </td>
                        <td>
                            <span class="status-badge <?= $user['role'] === 'admin' ? 'status-approved' : 'status-pending' ?>">
                                <?= ucfirst($user['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?= $user['is_active'] ? 'status-approved' : 'status-rejected' ?>">
                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= $user['voucher_count'] ?></td>
                        <td>TZS <?= number_format($user['approved_amount'], 2) ?></td>
                        <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php if ($user['id'] != $_SESSION['user_id']): // Can't manage own account ?>
                                <?php 
                                    // Check if this is the system admin
                                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                                ?>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                    <?php if (!$isSystemAdmin): ?>
                                        <?php if ($user['is_active']): ?>
                                            <button type="button" onclick="manageUser(<?= $user['id'] ?>, 'deactivate')"
                                                    class="btn-action" style="color: #b91c1c; cursor: pointer; text-decoration: underline; margin-right: 5px;">Deactivate</button>
                                        <?php else: ?>
                                            <button type="button" onclick="manageUser(<?= $user['id'] ?>, 'activate')" 
                                                    class="btn-action" style="color: #27ae60; cursor: pointer; text-decoration: underline; margin-right: 5px;">Activate</button>
                                        <?php endif; ?>
                                        
                                        <button type="button" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?>', <?= $user['voucher_count'] ?>)" 
                                                class="btn-action" style="color: #dc2626; cursor: pointer; text-decoration: underline;">Delete</button>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 11px; font-style: italic;">Protected</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999; font-size: 12px;">Own Account</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>

    <!-- Department Modal -->
    <div id="deptModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:1000;">
        <div style="background:white; padding:20px; border-radius:8px; width:90%; max-width:350px;">
            <h3 style="margin-top:0;">Change Department</h3>
            <p id="deptModalUser" style="margin-bottom:15px; font-size:13px; color:#555;"></p>
            <form method="POST">
                <input type="hidden" name="user_id" id="deptUserId">
                <input type="hidden" name="action" value="change_department">
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; font-size:12px; font-weight:600;">Select New Department</label>
                    <select name="department" id="deptSelect" required style="width:100%;">
                        <option value="Procurement">Procurement</option>
                        <option value="IT">IT</option>
                        <option value="Finance">Finance</option>
                        <option value="Sales">Sales</option>
                        <option value="Driver">Driver</option>
                    </select>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="document.getElementById('deptModal').style.display='none'" style="padding:8px 12px; background:#eee; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:8px 12px; background:#2563eb; color:white; border:none; border-radius:4px; cursor:pointer;">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/voucher-v5.js?v=9"></script>
    <script>
        function manageUser(userId, action) {
            const actions = {
                'activate': 'activate this user',
                'deactivate': 'deactivate this user', 
                'make_admin': 'promote this user to admin',
                'make_employee': 'demote this user to employee',
                'delete': 'permanently delete this user'
            };
            
            if (confirm(`Are you sure you want to ${actions[action]}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                
                const userInput = document.createElement('input');
                userInput.type = 'hidden';
                userInput.name = 'user_id';
                userInput.value = userId;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                
                form.appendChild(userInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function openDeptModal(userId, currentDept, userName) {
            document.getElementById('deptUserId').value = userId;
            document.getElementById('deptModalUser').textContent = 'For user: ' + userName;
            document.getElementById('deptSelect').value = currentDept;
            document.getElementById('deptModal').style.display = 'flex';
        }
        
        function deleteUser(userId, userName, voucherCount) {
            let message = `Are you sure you want to permanently delete user "${userName}"?`;
            if (voucherCount > 0) {
                message += `\n\nWarning: This user has ${voucherCount} voucher(s) associated. The user will be deleted, but vouchers will remain in the system.`;
            }
            message += '\n\nThis action cannot be undone.';
            
            if (confirm(message)) {
                const form = document.createElement('form');
                form.method = 'POST';
                
                const userInput = document.createElement('input');
                userInput.type = 'hidden';
                userInput.name = 'user_id';
                userInput.value = userId;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                
                form.appendChild(userInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function filterUserTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('usersTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) { // Skip header row
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length - 1; j++) { // Skip actions column
                    const cell = cells[j];
                    if (cell) {
                        const textValue = cell.textContent || cell.innerText;
                        if (textValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }
        
        function filterUsersByRole(role) {
            const table = document.getElementById('usersTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const rowRole = row.getAttribute('data-role');
                
                if (role === 'all' || rowRole === role) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
        
        function filterUsersByStatus(status) {
            const table = document.getElementById('usersTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const rowStatus = row.getAttribute('data-status');
                
                if (status === 'all' || rowStatus === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>
