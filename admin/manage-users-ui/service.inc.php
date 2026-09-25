<?php

declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function manageUsersUi_collect_state(): array
{
    global $pdo;

    $success = null;
    $error = null;

    $hasCompanyId = columnExists('users', 'company_id', $pdo);
$company_id = (int) currentCompanyId();
if (function_exists('ensureUsersExtraRolesColumn')) {
    ensureUsersExtraRolesColumn($pdo);
}

$manageUsersFormAction = 'manage-users.php';
$manageUsersQuery = array();
if (!empty($_GET['module'])) {
    $manageUsersQuery['module'] = (string) $_GET['module'];
}
if (!empty($_GET['company_slug'])) {
    $manageUsersQuery['company_slug'] = (string) $_GET['company_slug'];
}
if ($manageUsersQuery !== array()) {
    $manageUsersFormAction .= '?' . http_build_query($manageUsersQuery);
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        switch ($_POST['action']) {
            case 'activate':
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                    if ($company_id > 0 && function_exists('syncUserCompanyIndex')) {
                        syncUserCompanyIndex($company_id, $user_id);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $success = "User activated successfully.";
                break;
                
            case 'deactivate':
                // Prevent deactivating system admin
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $user = $stmt->fetch();
                
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    if ($isSystemAdmin) {
                        $error = "Cannot deactivate system admin. This user is protected.";
                        break;
                    }
                }
                
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                    if ($company_id > 0 && function_exists('syncUserCompanyIndex')) {
                        syncUserCompanyIndex($company_id, $user_id);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $success = "User deactivated successfully.";
                break;
                
            // Removed make_admin action to prevent elevating users via this page
                
            case 'make_employee':
                // Prevent demoting system admin
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $user = $stmt->fetch();
                
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    if ($isSystemAdmin) {
                        $error = "Cannot demote system admin. This user is protected.";
                        break;
                    }
                }
                
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("UPDATE users SET role = 'employee' WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET role = 'employee' WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $success = "User demoted to employee successfully.";
                break;
                
            case 'reset_password':
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                if ($user_id <= 0) {
                    $error = 'Invalid user selected.';
                    break;
                }
                if ($newPassword === '' || $confirmPassword === '') {
                    $error = 'Enter and confirm the new password.';
                    break;
                }
                if ($newPassword !== $confirmPassword) {
                    $error = 'Password and confirmation do not match.';
                    break;
                }
                if (strlen($newPassword) < 8) {
                    $error = 'Password must be at least 8 characters.';
                    break;
                }
                $targetUser = manageUsersFetchUser($pdo, $user_id, $hasCompanyId, $company_id);
                if (!$targetUser) {
                    $error = 'User not found.';
                    break;
                }
                if ((int) $targetUser['id'] === (int) ($_SESSION['user_id'] ?? 0)) {
                    $error = 'Use My Account or profile settings to change your own password.';
                    break;
                }
                try {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    if ($hasCompanyId) {
                        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND company_id = ?');
                        $stmt->execute([$hash, $user_id, $company_id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $stmt->execute([$hash, $user_id]);
                    }
                    if (function_exists('syncLoginPasswordToControlPlane') && !empty($targetUser['email'])) {
                        syncLoginPasswordToControlPlane(array((string) $targetUser['email']), $hash, $company_id);
                    }
                    $displayName = (string) ($targetUser['full_name'] ?? $targetUser['username'] ?? 'user');
                    manageUsersFlashPasswordOnce($displayName, $newPassword);
                    $success = 'Password updated for ' . $displayName . '. Copy it from the dialog — it cannot be viewed again later.';
                } catch (PDOException $e) {
                    $error = 'Could not update password: ' . $e->getMessage();
                }
                break;

            case 'reset_all_passwords':
                $newPassword = (string) ($_POST['bulk_password'] ?? '');
                $confirmPassword = (string) ($_POST['bulk_confirm_password'] ?? '');
                $confirmPhrase = strtoupper(trim((string) ($_POST['bulk_confirm_phrase'] ?? '')));
                $includeSelf = !empty($_POST['bulk_include_self']);
                $includeSystemAdmin = !empty($_POST['bulk_include_system_admin']);

                if ($newPassword === '' || $confirmPassword === '') {
                    $error = 'Enter and confirm the shared password.';
                    break;
                }
                if ($newPassword !== $confirmPassword) {
                    $error = 'Password and confirmation do not match.';
                    break;
                }
                if (strlen($newPassword) < 8) {
                    $error = 'Password must be at least 8 characters.';
                    break;
                }
                if ($confirmPhrase !== 'RESET ALL') {
                    $error = 'Type RESET ALL in the confirmation box to proceed.';
                    break;
                }

                $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
                $eligibleIds = manageUsersBulkResetEligibleIds(
                    $pdo,
                    $hasCompanyId,
                    $company_id,
                    $currentUserId,
                    $includeSelf,
                    $includeSystemAdmin
                );

                if ($eligibleIds === []) {
                    $error = 'No users matched the selected options.';
                    break;
                }

                try {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
                    $sql = 'UPDATE users SET password = ? WHERE id IN (' . $placeholders . ')';
                    $params = array_merge([$hash], $eligibleIds);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $updated = $stmt->rowCount();
                    if ($updated <= 0) {
                        $updated = count($eligibleIds);
                    }

                    if (function_exists('syncLoginPasswordToControlPlane') && function_exists('columnExists') && columnExists('users', 'email', $pdo)) {
                        $ph = implode(',', array_fill(0, count($eligibleIds), '?'));
                        $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id IN (' . $ph . ')');
                        $emailStmt->execute($eligibleIds);
                        $emails = array();
                        while ($er = $emailStmt->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($er['email'])) {
                                $emails[] = (string) $er['email'];
                            }
                        }
                        if ($emails !== array()) {
                            syncLoginPasswordToControlPlane($emails, $hash, $company_id);
                        }
                    }

                    $_SESSION['manage_users_bulk_pw_flash'] = array(
                        'password' => $newPassword,
                        'count' => $updated,
                        'at' => time(),
                    );
                    $success = 'Shared password applied to ' . $updated . ' user(s). Emails were not changed. Copy the password from the dialog.';
                } catch (PDOException $e) {
                    $error = 'Bulk reset failed: ' . $e->getMessage();
                }
                break;

            case 'change_department':
                if (empty($_POST['department'])) {
                    $error = "Department cannot be empty.";
                    break;
                }
                
                // Prevent modifying system admin
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("SELECT username, email, role FROM users WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("SELECT username, email, role FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                $user = $stmt->fetch();
                
                // Prevent modifying any admin
                if ($user) {
                    $isSystemAdmin = (strtolower(trim($user['username'])) === 'admin' || 
                                     strtolower(trim($user['email'])) === 'admin@ultimatetrading.com');
                    
                    if ($isSystemAdmin) {
                        $error = "Cannot change department of system admin. This user is protected.";
                        break;
                    }
                    
                    if ($user['role'] === 'admin') {
                        $error = "Cannot change department of an administrator.";
                        break;
                    }
                }
                
                $newDept = $_POST['department'];
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("UPDATE users SET department = ? WHERE id = ? AND company_id = ?");
                    $stmt->execute([$newDept, $user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET department = ? WHERE id = ?");
                    $stmt->execute([$newDept, $user_id]);
                }
                // Drop the new primary department from extra_roles if it was listed there.
                if (function_exists('ensureUsersExtraRolesColumn') && ensureUsersExtraRolesColumn($pdo) && columnExists('users', 'extra_roles', $pdo)) {
                    $extraStmt = $hasCompanyId
                        ? $pdo->prepare('SELECT extra_roles FROM users WHERE id = ? AND company_id = ? LIMIT 1')
                        : $pdo->prepare('SELECT extra_roles FROM users WHERE id = ? LIMIT 1');
                    $extraStmt->execute($hasCompanyId ? [$user_id, $company_id] : [$user_id]);
                    $extraRow = $extraStmt->fetch(PDO::FETCH_ASSOC);
                    $extras = function_exists('parseUserExtraRoles') ? parseUserExtraRoles($extraRow['extra_roles'] ?? null) : [];
                    $newKey = mb_strtolower(trim((string) $newDept));
                    $extras = array_values(array_filter($extras, static function ($r) use ($newKey) {
                        return mb_strtolower(trim((string) $r)) !== $newKey;
                    }));
                    $encoded = function_exists('encodeUserExtraRoles') ? encodeUserExtraRoles($extras) : json_encode($extras);
                    $up = $hasCompanyId
                        ? $pdo->prepare('UPDATE users SET extra_roles = ? WHERE id = ? AND company_id = ?')
                        : $pdo->prepare('UPDATE users SET extra_roles = ? WHERE id = ?');
                    $up->execute($hasCompanyId ? [$encoded, $user_id, $company_id] : [$encoded, $user_id]);
                }
                $success = "User department updated to $newDept successfully.";
                break;

            case 'change_access_roles':
                if ($user_id <= 0) {
                    $error = 'Invalid user.';
                    break;
                }
                if (!function_exists('ensureUsersExtraRolesColumn') || !ensureUsersExtraRolesColumn($pdo) || !columnExists('users', 'extra_roles', $pdo)) {
                    $error = 'Access roles are not available on this database yet.';
                    break;
                }
                $targetUser = manageUsersFetchUser($pdo, $user_id, $hasCompanyId, $company_id);
                if (!$targetUser) {
                    $error = 'User not found.';
                    break;
                }
                if (manageUsersIsSystemAdminRow($targetUser)) {
                    $error = 'Cannot change access roles of system admin. This user is protected.';
                    break;
                }
                if (($targetUser['role'] ?? '') === 'admin') {
                    $error = 'Cannot change access roles of an administrator.';
                    break;
                }
                $primaryDept = trim((string) ($targetUser['department'] ?? ''));
                $posted = $_POST['access_roles'] ?? [];
                if (!is_array($posted)) {
                    $posted = [$posted];
                }
                $roleOptions = manageUsersAccessRoleOptions();
                try {
                    if ($company_id > 0 && function_exists('fetchCompanySettingsMap')) {
                        $settingsMap = fetchCompanySettingsMap($pdo, $company_id);
                        $raw = trim((string) ($settingsMap['departments'] ?? ''));
                        if ($raw !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded) && $decoded !== []) {
                                $roleOptions = manageUsersAccessRoleOptions(array_map('strval', $decoded));
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // keep defaults
                }
                $allowed = [];
                foreach ($roleOptions as $opt) {
                    $allowed[mb_strtolower(trim((string) $opt))] = trim((string) $opt);
                }
                $extras = [];
                foreach ($posted as $item) {
                    $name = trim((string) $item);
                    if ($name === '') {
                        continue;
                    }
                    $key = mb_strtolower($name);
                    if ($primaryDept !== '' && $key === mb_strtolower($primaryDept)) {
                        continue;
                    }
                    if (!isset($allowed[$key])) {
                        continue;
                    }
                    $extras[$key] = $allowed[$key];
                }
                $encoded = function_exists('encodeUserExtraRoles') ? encodeUserExtraRoles(array_values($extras)) : json_encode(array_values($extras));
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare('UPDATE users SET extra_roles = ? WHERE id = ? AND company_id = ?');
                    $stmt->execute([$encoded, $user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET extra_roles = ? WHERE id = ?');
                    $stmt->execute([$encoded, $user_id]);
                }
                if ((int) ($_SESSION['user_id'] ?? 0) === $user_id && function_exists('hydrateSessionAccessRoles')) {
                    hydrateSessionAccessRoles([
                        'department' => $primaryDept,
                        'extra_roles' => $encoded,
                    ]);
                }
                $count = count($extras);
                $success = $count > 0
                    ? ('Access roles updated (' . $count . ' additional). User can use features for '
                        . ($primaryDept !== '' ? $primaryDept . ' + ' : '')
                        . implode(', ', array_values($extras)) . '. Ask the user to refresh or re-login if already signed in.')
                    : ('Additional access roles cleared. User keeps primary department'
                        . ($primaryDept !== '' ? ': ' . $primaryDept : '') . '.');
                break;
                
            case 'delete':
                // Prevent deletion of system admin
                if ($hasCompanyId) {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ? AND company_id = ?");
                    $stmt->execute([$user_id, $company_id]);
                } else {
                    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
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
                
                // Delete user and handle foreign key constraints
                try {
                    $pdo->beginTransaction();
                    
                    // 1. Delete approval_logs records first
                    try {
                        $stmt = $pdo->prepare("DELETE FROM approval_logs WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $deleted_logs = $stmt->rowCount();
                    } catch (Exception $e) {
                        error_log("Note: Could not delete approval_logs for user $user_id: " . $e->getMessage());
                        $deleted_logs = 0;
                    }
                    
                    // 2. Set NULL for payment_vouchers fields that can be nullified
                    try {
                        $stmt = $pdo->prepare("UPDATE payment_vouchers SET approved_by = NULL WHERE approved_by = ?");
                        $stmt->execute([$user_id]);
                        
                        $stmt = $pdo->prepare("UPDATE payment_vouchers SET paid_by = NULL WHERE paid_by = ?");
                        $stmt->execute([$user_id]);
                        
                        try {
                            $stmt = $pdo->prepare("UPDATE payment_vouchers SET posted_by = NULL WHERE posted_by = ?");
                            $stmt->execute([$user_id]);
                        } catch (Exception $e) {
                        }
                    } catch (Exception $e) {
                        error_log("Note: Could not update payment_vouchers for user $user_id: " . $e->getMessage());
                    }
                    
                    // 3. Delete notifications for this user
                    try {
                        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                    } catch (Exception $e) {
                        error_log("Note: Could not delete notifications for user $user_id: " . $e->getMessage());
                    }
                    
                    // 4. Handle payment_vouchers.created_by constraint if user created vouchers
                    if ($voucher_count > 0) {
                        try {
                            // Find system admin user ID
                            if ($hasCompanyId) {
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE (LOWER(username) = 'admin' OR LOWER(email) = 'admin@ultimatetrading.com') AND role = 'admin' AND company_id = ? LIMIT 1");
                                $stmt->execute([$company_id]);
                            } else {
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE (LOWER(username) = 'admin' OR LOWER(email) = 'admin@ultimatetrading.com') AND role = 'admin' LIMIT 1");
                                $stmt->execute();
                            }
                            $adminUser = $stmt->fetch();
                            
                            if ($adminUser && isset($adminUser['id'])) {
                                $stmt = $pdo->prepare("UPDATE payment_vouchers SET created_by = ? WHERE created_by = ?");
                                $stmt->execute([$adminUser['id'], $user_id]);
                            }
                        } catch (Exception $e) {
                            error_log("Error reassigning vouchers for user $user_id: " . $e->getMessage());
                        }
                    }
                    
                    // 5. Remove from login index, then delete the user
                    if ($hasCompanyId && $company_id > 0 && function_exists('removeUserCompanyIndex')) {
                        removeUserCompanyIndex($company_id, $user_id, 'inactive');
                    } elseif (!empty($user['email']) && function_exists('removeUserCompanyIndexByEmail')) {
                        removeUserCompanyIndexByEmail((string) $user['email'], 'inactive');
                    }
                    if ($hasCompanyId) {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
                        $stmt->execute([$user_id, $company_id]);
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                    }
                    
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
                    
                    $errorMsg = $e->getMessage();
                    if (strpos($errorMsg, 'created_by') !== false || strpos($errorMsg, 'payment_vouchers') !== false) {
                        $error = "Cannot delete user: User has created $voucher_count voucher(s). Please reassign or delete the vouchers first.";
                    } else {
                        $error = "Error deleting user: " . $errorMsg;
                    }
                    error_log("User deletion error for user_id $user_id: " . $errorMsg);
                }
                break;
            
            case 'register':
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                $department = $_POST['department'] ?? '';

                if ($full_name === '' || $email === '' || $password === '' || $department === '') {
                    $error = 'Please fill in all required fields.';
                } elseif ($password !== $confirmPassword) {
                    $error = 'Password and confirmation do not match.';
                } elseif (function_exists('validateNewUserEmailForIndex') && ($emailErr = validateNewUserEmailForIndex($email)) !== null) {
                    $error = $emailErr;
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters long.';
                } else {
                    try {
                        // Unique checks
                        if ($hasCompanyId) {
                            $stmt = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND company_id = ?');
                            $stmt->execute([$full_name, $email, $company_id]);
                        } else {
                            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
                            $stmt->execute([$full_name, $email]);
                        }
                        if ($stmt->fetch()) {
                            $error = 'Full name (username) or email already exists.';
                        } else {
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            if ($hasCompanyId) {
                                $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, department, created_at, is_active, company_id) VALUES (?, ?, ?, ?, "employee", ?, NOW(), 1, ?)');
                                $exec_result = $stmt->execute([$full_name, $hashed, $full_name, $email, $department, $company_id]);
                            } else {
                                $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, department, created_at, is_active) VALUES (?, ?, ?, ?, "employee", ?, NOW(), 1)');
                                $exec_result = $stmt->execute([$full_name, $hashed, $full_name, $email, $department]);
                            }
                            if ($exec_result) {
                                $newUserId = (int) $pdo->lastInsertId();
                                if ($newUserId > 0 && $hasCompanyId && $company_id > 0 && function_exists('syncUserCompanyIndex')) {
                                    syncUserCompanyIndex($company_id, $newUserId);
                                }
                                manageUsersFlashPasswordOnce($full_name, $password);
                                $success = 'Employee registered successfully! Copy the login password from the dialog.';
                            } else {
                                $error = 'Registration failed. Please try again.';
                            }
                        }
                    } catch (PDOException $e) {
                        $error = 'Database error: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get all users (with voucher stats when payment_vouchers exists)
$users = array();
$useVoucherStats = function_exists('tableExists') && tableExists('payment_vouchers', $pdo);
try {
    if ($useVoucherStats) {
        if ($hasCompanyId) {
            $stmt = $pdo->prepare("
                SELECT u.*,
                       COUNT(pv.id) as voucher_count,
                       SUM(CASE WHEN pv.status = 'approved' THEN pv.total_amount ELSE 0 END) as approved_amount
                FROM users u
                LEFT JOIN payment_vouchers pv ON u.id = pv.created_by
                WHERE u.company_id = ?
                GROUP BY u.id
                ORDER BY u.created_at DESC
            ");
            $stmt->execute(array($company_id));
        } else {
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
        }
    } elseif ($hasCompanyId) {
        $stmt = $pdo->prepare('SELECT u.*, 0 AS voucher_count, 0 AS approved_amount FROM users u WHERE u.company_id = ? ORDER BY u.created_at DESC');
        $stmt->execute(array($company_id));
    } else {
        $stmt = $pdo->query('SELECT u.*, 0 AS voucher_count, 0 AS approved_amount FROM users u ORDER BY u.created_at DESC');
    }
    if ($stmt) {
        $users = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('manage-users user list: ' . $e->getMessage());
    try {
        if ($hasCompanyId) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE company_id = ? ORDER BY created_at DESC');
            $stmt->execute(array($company_id));
        } else {
            $stmt = $pdo->query('SELECT * FROM users ORDER BY created_at DESC');
        }
        $users = $stmt ? $stmt->fetchAll() : array();
        foreach ($users as $i => $row) {
            if (!isset($users[$i]['voucher_count'])) {
                $users[$i]['voucher_count'] = 0;
            }
            if (!isset($users[$i]['approved_amount'])) {
                $users[$i]['approved_amount'] = 0;
            }
        }
    } catch (Throwable $e2) {
        error_log('manage-users user list fallback: ' . $e2->getMessage());
        $users = array();
        $error = 'Could not load users: ' . $e2->getMessage();
    }
}
if (!is_array($users)) {
    $users = array();
}

// Get department statistics
if ($hasCompanyId) {
    $stmt = $pdo->prepare("
        SELECT department, COUNT(*) as user_count
        FROM users 
        WHERE is_active = 1 AND role = 'employee' AND company_id = ?
        GROUP BY department
        ORDER BY user_count DESC
    ");
    $stmt->execute([$company_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT department, COUNT(*) as user_count
        FROM users 
        WHERE is_active = 1 AND role = 'employee'
        GROUP BY department
        ORDER BY user_count DESC
    ");
    $stmt->execute();
}
$dept_stats = $stmt->fetchAll();

$activeAdmins = 0;
$activeEmployees = 0;
foreach ($users as $u) {
    if (!empty($u['is_active'])) {
        if (($u['role'] ?? '') === 'admin') {
            $activeAdmins++;
        } else {
            $activeEmployees++;
        }
    }
}

try {
    $bulkResetEligibleCount = count(manageUsersBulkResetEligibleIds(
        $pdo,
        $hasCompanyId,
        $company_id,
        (int) ($_SESSION['user_id'] ?? 0),
        false,
        false
    ));
} catch (Throwable $e) {
    error_log('manage-users bulk reset count: ' . $e->getMessage());
    $bulkResetEligibleCount = 0;
}
$bulkHasSystemAdmin = false;
foreach ($users as $u) {
    if (manageUsersIsSystemAdminRow($u)) {
        $bulkHasSystemAdmin = true;
        break;
    }
}

    $pwFlash = null;
    if (!empty($_SESSION['manage_users_pw_flash']) && is_array($_SESSION['manage_users_pw_flash'])) {
        $pwFlash = $_SESSION['manage_users_pw_flash'];
        unset($_SESSION['manage_users_pw_flash']);
    }
    $bulkPwFlash = null;
    if (!empty($_SESSION['manage_users_bulk_pw_flash']) && is_array($_SESSION['manage_users_bulk_pw_flash'])) {
        $bulkPwFlash = $_SESSION['manage_users_bulk_pw_flash'];
        unset($_SESSION['manage_users_bulk_pw_flash']);
    }

    if ($success === null && !empty($_SESSION['manage_users_flash_success'])) {
        $success = (string) $_SESSION['manage_users_flash_success'];
        unset($_SESSION['manage_users_flash_success']);
    }
    if ($error === null && !empty($_SESSION['manage_users_flash_error'])) {
        $error = (string) $_SESSION['manage_users_flash_error'];
        unset($_SESSION['manage_users_flash_error']);
    }

    $defaultDepartments = ['General', 'Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Warehouse', 'Management'];
    $departments = $defaultDepartments;
    try {
        if ($company_id > 0 && function_exists('fetchCompanySettingsMap')) {
            $settingsMap = fetchCompanySettingsMap($pdo, $company_id);
            if (function_exists('companySettingsDepartmentsFromMap')) {
                $departments = companySettingsDepartmentsFromMap($settingsMap, $defaultDepartments);
            } else {
                $raw = trim((string) ($settingsMap['departments'] ?? ''));
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && $decoded !== []) {
                        $clean = [];
                        foreach ($decoded as $item) {
                            $name = trim((string) $item);
                            if ($name === '') {
                                continue;
                            }
                            $key = mb_strtolower($name);
                            if (isset($clean[$key])) {
                                continue;
                            }
                            $clean[$key] = $name;
                        }
                        if ($clean !== []) {
                            $departments = array_values($clean);
                        }
                    }
                }
                if (!in_array('Warehouse', $departments, true)
                    && !in_array('warehouse', array_map('mb_strtolower', $departments), true)
                ) {
                    $departments[] = 'Warehouse';
                }
            }
        }
    } catch (Throwable $e) {
        $departments = $defaultDepartments;
    }

    $accessRoleOptions = manageUsersAccessRoleOptions($departments);

    return [
        'formAction' => $manageUsersFormAction,
        'currentUserId' => (int) ($_SESSION['user_id'] ?? 0),
        'success' => $success !== null ? (string) $success : '',
        'error' => $error !== null ? (string) $error : '',
        'users' => $users,
        'deptStats' => $dept_stats,
        'activeAdmins' => $activeAdmins,
        'activeEmployees' => $activeEmployees,
        'bulkResetEligibleCount' => (int) $bulkResetEligibleCount,
        'bulkHasSystemAdmin' => $bulkHasSystemAdmin,
        'departments' => $departments,
        'accessRoleOptions' => $accessRoleOptions,
        'pwFlash' => $pwFlash,
        'bulkPwFlash' => $bulkPwFlash,
    ];
}
