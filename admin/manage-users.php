<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$manageUsersFatal = '';
$manageUsersShowErrorPage = function ($message) {
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>User management error</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:720px">';
    echo '<h1>User management could not load</h1>';
    echo '<p style="color:#b91c1c;">' . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Upload the latest <code>admin/manage-users.php</code> and <code>includes/functions.php</code>, then reload.</p>';
    echo '</body></html>';
    exit;
};
set_exception_handler(function ($e) use (&$manageUsersFatal, $manageUsersShowErrorPage) {
    $manageUsersFatal = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    $manageUsersShowErrorPage($manageUsersFatal);
});

try {
    require_once __DIR__ . '/../includes/functions.php';
} catch (Throwable $e) {
    $manageUsersFatal = 'Bootstrap failed: ' . $e->getMessage();
}

if ($manageUsersFatal === '') {
    try {
        requireAdmin();
    } catch (Throwable $e) {
        $manageUsersFatal = $e->getMessage();
    }
}

if ($manageUsersFatal !== '') {
    $manageUsersShowErrorPage($manageUsersFatal);
}

$hasCompanyId = columnExists('users', 'company_id', $pdo);
$company_id = (int) currentCompanyId();

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

/**
 * Show plaintext password once to the admin who just set it (not stored retrievably).
 */
function manageUsersFlashPasswordOnce($userName, $plainPassword)
{
    $_SESSION['manage_users_pw_flash'] = array(
        'name' => $userName,
        'password' => $plainPassword,
        'at' => time(),
    );
}

/**
 * @return array<string, mixed>|false
 */
function manageUsersFetchUser($pdo, $userId, $hasCompanyId, $companyId)
{
    if ($userId <= 0) {
        return false;
    }
    if ($hasCompanyId) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$userId, $companyId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : false;
}

function manageUsersIsSystemAdminRow($user)
{
    return strtolower(trim((string) ($user['username'] ?? ''))) === 'admin'
        || strtolower(trim((string) ($user['email'] ?? ''))) === 'admin@ultimatetrading.com';
}

/**
 * User IDs eligible for bulk password reset (emails unchanged).
 *
 * @return int[]
 */
function manageUsersBulkResetEligibleIds($pdo, $hasCompanyId, $companyId, $currentUserId, $includeSelf, $includeSystemAdmin)
{
    $selectCols = array('id', 'username');
    if (function_exists('columnExists') && columnExists('users', 'email', $pdo)) {
        $selectCols[] = 'email';
    }
    $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM users';
    if ($hasCompanyId && $companyId > 0) {
        $sql .= ' WHERE company_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($companyId));
    } else {
        $stmt = $pdo->query($sql);
    }
    if (!$stmt) {
        return array();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = array();
    }
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (!$includeSelf && $id === $currentUserId) {
            continue;
        }
        if (!$includeSystemAdmin && manageUsersIsSystemAdminRow($row)) {
            continue;
        }
        $ids[] = $id;
    }

    return $ids;
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
                $success = "User department updated to $newDept successfully.";
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

if (!function_exists('manageUsersInitials')) {
    function manageUsersInitials($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts) && count($parts) >= 2) {
            $a = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
            $b = function_exists('mb_substr') ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : substr($parts[count($parts) - 1], 0, 1);

            return strtoupper($a . $b);
        }

        return strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 2, 'UTF-8') : substr($name, 0, 2));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../assets/css/erp-modern-global.css?v=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --mu-bg: #f4f6fb;
            --mu-card: #ffffff;
            --mu-border: #e8ecf4;
            --mu-text: #111827;
            --mu-muted: #6b7280;
            --mu-purple: #7c3aed;
            --mu-purple-soft: #ede9fe;
            --mu-navy: #1e293b;
        }
        body.dashboard.mu-page { background: var(--mu-bg); font-family: 'Inter', system-ui, sans-serif; }
        .mu-main { padding: 28px 32px 40px; max-width: 1480px; margin: 0 auto; width: 100%; }

        .mu-page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }
        .mu-page-header h1 { margin: 0; font-size: 1.75rem; font-weight: 800; color: var(--mu-text); letter-spacing: -0.03em; }
        .mu-page-header p { margin: 6px 0 0; color: var(--mu-muted); font-size: 0.9rem; }
        .mu-btn-register {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            background: var(--mu-navy);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(30, 41, 59, 0.2);
            transition: background 0.2s, transform 0.15s;
        }
        .mu-btn-register:hover { background: #0f172a; transform: translateY(-1px); }
        .mu-header-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .mu-btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            background: #fff;
            color: #7c3aed;
            border: 1px solid #c4b5fd;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
        }
        .mu-btn-outline:hover { background: #f5f3ff; }
        .mu-warn-box {
            padding: 12px 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            font-size: 12px;
            color: #991b1b;
            margin-bottom: 14px;
            line-height: 1.5;
        }

        .mu-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 1100px) { .mu-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 520px) { .mu-stats { grid-template-columns: 1fr; } }
        .mu-stat-card {
            background: var(--mu-card);
            border: 1px solid var(--mu-border);
            border-radius: 14px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .mu-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .mu-stat-icon svg { width: 22px; height: 22px; flex-shrink: 0; }
        .mu-icon-svg { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; fill: currentColor; }
        .mu-stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .mu-stat-icon.green { background: #dcfce7; color: #16a34a; }
        .mu-stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .mu-stat-icon.orange { background: #ffedd5; color: #ea580c; }
        .mu-stat-value { font-size: 1.65rem; font-weight: 800; color: var(--mu-text); line-height: 1.1; }
        .mu-stat-label { font-size: 0.68rem; font-weight: 700; color: var(--mu-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 4px; }

        .mu-card {
            background: var(--mu-card);
            border: 1px solid var(--mu-border);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        .mu-toolbar {
            padding: 18px 22px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .mu-search { position: relative; flex: 1; min-width: 260px; max-width: 480px; }
        .mu-search input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            border: 1px solid var(--mu-border);
            border-radius: 10px;
            font-size: 0.875rem;
            background: #fafbfc;
        }
        .mu-search input:focus { outline: none; border-color: #c4b5fd; background: #fff; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12); }
        .mu-filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .mu-filters select {
            padding: 10px 36px 10px 14px;
            border: 1px solid var(--mu-border);
            border-radius: 10px;
            font-size: 0.8125rem;
            color: #374151;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 5l3 3 3-3'/%3E%3C/svg%3E") no-repeat right 12px center;
            appearance: none;
            cursor: pointer;
            min-width: 120px;
        }

        .mu-table-wrap { overflow-x: auto; }
        table.mu-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        table.mu-table thead th {
            background: #000000;
            color: #ffffff;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 12px 16px;
            border-bottom: 2px solid #000000;
            white-space: nowrap;
        }
        table.mu-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        table.mu-table tbody tr:hover { background: #fafbff; }
        table.mu-table tbody tr.mu-hidden { display: none; }

        .mu-user-cell { display: flex; align-items: center; gap: 12px; }
        .mu-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }
        .mu-avatar.c0 { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .mu-avatar.c1 { background: linear-gradient(135deg, #2563eb, #60a5fa); }
        .mu-avatar.c2 { background: linear-gradient(135deg, #059669, #34d399); }
        .mu-avatar.c3 { background: linear-gradient(135deg, #ea580c, #fb923c); }
        .mu-avatar.c4 { background: linear-gradient(135deg, #db2777, #f472b6); }
        .mu-user-name { font-weight: 600; color: var(--mu-text); }

        .mu-link { color: #2563eb; font-weight: 500; cursor: pointer; text-decoration: none; border: none; background: none; padding: 0; font-size: inherit; }
        .mu-link:hover { text-decoration: underline; }
        .mu-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .mu-pill.role-admin { background: #e0e7ff; color: #4338ca; }
        .mu-pill.role-employee { background: #e0f2fe; color: #0369a1; }
        .mu-pill.status-active { background: #dcfce7; color: #15803d; }
        .mu-pill.status-inactive { background: #fee2e2; color: #b91c1c; }

        .mu-engagement strong { display: block; font-weight: 600; color: var(--mu-text); font-size: 0.8125rem; }
        .mu-engagement span { font-size: 0.75rem; color: var(--mu-muted); }

        .mu-kebab {
            width: 36px;
            height: 36px;
            border: 1px solid var(--mu-border);
            border-radius: 8px;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .mu-kebab svg { width: 18px; height: 18px; fill: currentColor; }
        .mu-kebab:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--mu-text); }
        .mu-search .mu-icon-svg { width: 16px; height: 16px; position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        .actions-dropdown { position: relative; display: inline-block; }
        .actions-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            background: #fff;
            min-width: 200px;
            border: 1px solid var(--mu-border);
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
            z-index: 1000;
            display: none;
            padding: 6px 0;
        }
        .actions-dropdown-menu.show { display: block; }
        .actions-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: #475569;
            font-size: 0.8125rem;
            font-weight: 500;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            cursor: pointer;
        }
        .actions-dropdown-item:hover { background: #f8fafc; color: #111827; }
        .actions-dropdown-item.text-danger { color: #dc2626; }
        .actions-dropdown-item.text-success { color: #16a34a; }
        .actions-dropdown-divider { height: 1px; background: #f1f5f9; margin: 4px 0; }

        .mu-table-footer {
            padding: 16px 22px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            color: var(--mu-muted);
        }
        .mu-pagination { display: flex; align-items: center; gap: 6px; }
        .mu-page-btn {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border: 1px solid var(--mu-border);
            border-radius: 8px;
            background: #fff;
            color: #475569;
            font-weight: 600;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .mu-page-btn:hover:not(:disabled) { border-color: #c4b5fd; color: var(--mu-purple); }
        .mu-page-btn.active { background: var(--mu-purple); border-color: var(--mu-purple); color: #fff; }
        .mu-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .mu-per-page select {
            padding: 8px 32px 8px 12px;
            border: 1px solid var(--mu-border);
            border-radius: 8px;
            font-size: 0.8125rem;
            background: #fff;
            appearance: none;
            cursor: pointer;
        }

        .mu-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10050;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .mu-overlay.is-open { display: flex !important; }
        .mu-panel {
            background: #fff;
            padding: 24px 28px;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2);
        }
        .btn-ghost {
            border: 1px solid var(--mu-border);
            background: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-primary { background: var(--mu-navy); color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 600; cursor: pointer; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 600; cursor: pointer; }
        .mu-copy-box {
            margin-bottom: 16px;
            padding: 14px;
            background: #f8fafc;
            border: 1px solid var(--mu-border);
            border-radius: 10px;
        }
        .mu-copy-box label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .mu-copy-row { display: flex; gap: 8px; align-items: stretch; }
        .mu-copy-row input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.04em;
            background: #fff;
            color: #111827;
        }
        .mu-btn-copy {
            padding: 10px 16px;
            background: var(--mu-purple);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .mu-btn-copy:hover { background: #6d28d9; }
        .mu-btn-copy.copied { background: #16a34a; }
    </style>
</head>
<body class="dashboard mu-page">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="mu-main">
        <div class="mu-page-header">
            <div>
                <h1>User Management</h1>
                <p>Manage system access and roles for all staff members</p>
            </div>
            <div class="mu-header-actions">
                <button type="button" class="mu-btn-outline" onclick="openBulkResetModal()">
                    <svg class="mu-icon-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                    Reset all passwords
                </button>
                <button type="button" class="mu-btn-register" onclick="openRegisterModal()">
                    <svg class="mu-icon-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg>
                    Register New Employee
                </button>
            </div>
        </div>

        <div class="mu-stats">
            <div class="mu-stat-card">
                <div class="mu-stat-icon purple" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                </div>
                <div>
                    <div class="mu-stat-value"><?= count($users) ?></div>
                    <div class="mu-stat-label"><?= count($users) ?> Total Users</div>
                </div>
            </div>
            <div class="mu-stat-card">
                <div class="mu-stat-icon green" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
                </div>
                <div>
                    <div class="mu-stat-value"><?= $activeAdmins ?></div>
                    <div class="mu-stat-label"><?= $activeAdmins ?> Active Admins</div>
                </div>
            </div>
            <div class="mu-stat-card">
                <div class="mu-stat-icon blue" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                </div>
                <div>
                    <div class="mu-stat-value"><?= $activeEmployees ?></div>
                    <div class="mu-stat-label"><?= $activeEmployees ?> Active Employees</div>
                </div>
            </div>
            <div class="mu-stat-card">
                <div class="mu-stat-icon orange" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/></svg>
                </div>
                <div>
                    <div class="mu-stat-value"><?= count($dept_stats) ?></div>
                    <div class="mu-stat-label"><?= count($dept_stats) ?> Departments</div>
                </div>
            </div>
        </div>

        <div class="mu-card">
            <div class="mu-toolbar">
                <div class="mu-search">
                    <svg class="mu-icon-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                    <input type="text" id="searchInput" placeholder="Search users by name, email or department..." onkeyup="muApplyFilters()">
                </div>
                <div class="mu-filters">
                    <select id="filterRole" onchange="muApplyFilters()">
                        <option value="all">All Roles</option>
                        <option value="admin">Admins Only</option>
                        <option value="employee">Employees Only</option>
                    </select>
                    <select id="filterStatus" onchange="muApplyFilters()">
                        <option value="all">All Status</option>
                        <option value="1">Active Only</option>
                        <option value="0">Inactive Only</option>
                    </select>
                </div>
            </div>

            <div class="mu-table-wrap stacked-table">
            <table class="mu-table" id="usersTable">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Engagement</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $isUserAdmin = (($user['role'] ?? '') === 'admin');
                        $isSystemAdmin = (strtolower(trim($user['username'] ?? '')) === 'admin'
                            || strtolower(trim($user['email'] ?? '')) === 'admin@ultimatetrading.com');
                        $avatarClass = 'c' . ((int) $user['id'] % 5);
                        $searchBlob = strtolower(trim(($user['full_name'] ?? '') . ' ' . ($user['username'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . ($user['department'] ?? '')));
                    ?>
                    <tr class="mu-user-row" data-role="<?= htmlspecialchars($user['role'] ?? '') ?>" data-status="<?= (int) $user['is_active'] ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                        <td>
                            <div class="mu-user-cell">
                                <div class="mu-avatar <?= $avatarClass ?>"><?= htmlspecialchars(manageUsersInitials($user['full_name'] ?? $user['username'] ?? '')) ?></div>
                                <span class="mu-user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <?php if (!$isUserAdmin): ?>
                        <td>
                            <button type="button" class="mu-link"
                                onclick='openDeptModal(<?= (int) $user['id'] ?>, <?= json_encode($user['department'] ?: '') ?>, <?= json_encode($user['full_name']) ?>)'>
                                <?= htmlspecialchars($user['department'] ?: '—') ?>
                            </button>
                        </td>
                        <?php else: ?>
                        <td class="text-muted"><?= htmlspecialchars($user['department'] ?: '—') ?></td>
                        <?php endif; ?>
                        <td>
                            <span class="mu-pill <?= $isUserAdmin ? 'role-admin' : 'role-employee' ?>"><?= ucfirst($user['role']) ?></span>
                        </td>
                        <td>
                            <span class="mu-pill <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <td class="mu-engagement">
                            <strong><?= (int) $user['voucher_count'] ?> Vouchers</strong>
                            <span>TZS <?= number_format((float) ($user['approved_amount'] ?? 0), 2) ?></span>
                        </td>
                        <td style="color:#64748b;white-space:nowrap;"><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php if ((int) $user['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                <div class="actions-dropdown">
                                    <button type="button" class="mu-kebab" onclick="toggleRowDropdown(this)" aria-label="Actions">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                                    </button>
                                    <div class="actions-dropdown-menu">
                                        <?php if (!$isUserAdmin): ?>
                                            <button type="button" class="actions-dropdown-item" 
                                                    onclick='openDeptModal(<?= $user['id'] ?>, <?= json_encode($user['department'] ?: '') ?>, <?= json_encode($user['full_name']) ?>)'>
                                                <i class="fas fa-building"></i> Change Dept
                                            </button>
                                        <?php endif; ?>

                                        <?php if ((int) $user['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                            <button type="button" class="actions-dropdown-item"
                                                    data-user-id="<?= (int) $user['id'] ?>"
                                                    data-user-name="<?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    onclick="event.stopPropagation(); openPasswordModalFromBtn(this)">
                                                <i class="fas fa-key"></i> Set / Reset Password
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!$isSystemAdmin): ?>
                                            <?php if ($user['is_active']): ?>
                                                <button type="button" class="actions-dropdown-item text-danger" onclick="manageUser(<?= $user['id'] ?>, 'deactivate')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="actions-dropdown-item text-success" onclick="manageUser(<?= $user['id'] ?>, 'activate')">
                                                    <i class="fas fa-user-check"></i> Activate
                                                </button>
                                            <?php endif; ?>
                                            
                                            <div class="actions-dropdown-divider"></div>
                                            <button type="button" class="actions-dropdown-item text-danger" 
                                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?>', <?= $user['voucher_count'] ?>)">
                                                <i class="fas fa-trash-alt"></i> Delete User
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span style="font-size:0.75rem;color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="mu-table-footer">
                <span id="muPageInfo">Showing 1 to 10 of <?= count($users) ?> users</span>
                <div class="mu-pagination" id="muPagination"></div>
                <label class="mu-per-page">
                    <select id="muPerPage" onchange="muApplyFilters()">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                </label>
            </div>
        </div>
    </main>

    <!-- Bulk reset all passwords -->
    <div id="bulkResetModal" class="mu-overlay" aria-hidden="true">
        <div class="mu-panel" style="max-width:520px;" onclick="event.stopPropagation()">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;font-size:18px;font-weight:700;">Reset all user passwords</h3>
                <button type="button" onclick="closeBulkResetModal()" style="border:none;background:none;font-size:24px;cursor:pointer;">&times;</button>
            </div>
            <div class="mu-warn-box">
                <strong>Warning:</strong> Sets the same login password for every selected user in this company.
                <strong>Email addresses are not changed.</strong> Users will sign in with their existing email and this new password.
            </div>
            <p style="font-size:13px;color:#64748b;margin:0 0 14px;">
                Will update <strong id="bulkResetCountLabel"><?= (int) $bulkResetEligibleCount ?></strong> user(s)
                (excludes you and the system admin unless you check the options below).
            </p>
            <form method="POST" id="bulkResetForm" action="<?= htmlspecialchars($manageUsersFormAction, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                  data-base-count="<?= (int) $bulkResetEligibleCount ?>" data-has-system-admin="<?= $bulkHasSystemAdmin ? '1' : '0' ?>">
                <input type="hidden" name="action" value="reset_all_passwords">
                <div class="form-group" style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label for="bulk_password" style="font-size:12px;font-weight:600;">Shared password</label>
                        <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:11px;" onclick="manageUsersGeneratePassword('bulk_password','bulk_confirm_password'); manageUsersSyncBulkCopyField();">Generate</button>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <input type="password" id="bulk_password" name="bulk_password" required minlength="8" autocomplete="new-password"
                               style="flex:1;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;" oninput="manageUsersSyncBulkCopyField()">
                        <button type="button" class="btn-ghost" style="padding:8px 12px;font-size:12px;" onclick="manageUsersTogglePasswordVisibility('bulk_password', this)">Show</button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label for="bulk_confirm_password" style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Confirm password</label>
                    <input type="password" id="bulk_confirm_password" name="bulk_confirm_password" required minlength="8" autocomplete="new-password"
                           style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;" oninput="manageUsersSyncBulkCopyField()">
                </div>
                <div class="mu-copy-box">
                    <label for="bulkPwCopyField">Copy shared password</label>
                    <div class="mu-copy-row">
                        <input type="text" id="bulkPwCopyField" readonly placeholder="Enter or generate password">
                        <button type="button" class="mu-btn-copy" id="bulkPwCopyBtn" onclick="manageUsersCopyBulkPassword()">Copy password</button>
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:12px 0 8px;cursor:pointer;">
                    <input type="checkbox" name="bulk_include_self" value="1" id="bulk_include_self" onchange="manageUsersUpdateBulkCount()">
                    Also reset my password (current admin)
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:0 0 12px;cursor:pointer;">
                    <input type="checkbox" name="bulk_include_system_admin" value="1" id="bulk_include_system_admin" onchange="manageUsersUpdateBulkCount()">
                    Include system admin account
                </label>
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="bulk_confirm_phrase" style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Type <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">RESET ALL</code> to confirm</label>
                    <input type="text" id="bulk_confirm_phrase" name="bulk_confirm_phrase" required autocomplete="off" placeholder="RESET ALL"
                           style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;text-transform:uppercase;">
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" onclick="closeBulkResetModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="background:#dc2626;">Apply to all users</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal (mu-overlay: not Bootstrap .modal) -->
    <div id="passwordModal" class="mu-overlay" aria-hidden="true">
        <div class="mu-panel" onclick="event.stopPropagation()">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;font-size:18px;font-weight:700;">Set user password</h3>
                <button type="button" onclick="closePasswordModal()" style="border:none;background:none;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <p id="passwordModalUser" style="margin:0 0 16px;font-size:13px;color:#64748b;"></p>
            <form method="POST" id="passwordForm" action="<?= htmlspecialchars($manageUsersFormAction, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="passwordUserId" value="">
                <div class="form-group" style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label for="new_password" style="font-size:12px;font-weight:600;">New password</label>
                        <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:11px;" onclick="manageUsersGeneratePassword('new_password','confirm_password')">Generate</button>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"
                               style="flex:1;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;">
                        <button type="button" class="btn-ghost" style="padding:8px 12px;font-size:12px;white-space:nowrap;" onclick="manageUsersTogglePasswordVisibility('new_password', this)">Show</button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="confirm_password" style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;">Confirm password</label>
                    <div style="display:flex;gap:8px;">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"
                               style="flex:1;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;">
                        <button type="button" class="btn-ghost" style="padding:8px 12px;font-size:12px;white-space:nowrap;" onclick="manageUsersTogglePasswordVisibility('confirm_password', this)">Show</button>
                    </div>
                </div>
                <div class="mu-copy-box">
                    <label for="pwCopyField">Copy password for employee</label>
                    <div class="mu-copy-row">
                        <input type="text" id="pwCopyField" readonly placeholder="Generate or type a password above" aria-label="Password to copy">
                        <button type="button" class="mu-btn-copy" id="pwCopyBtn" onclick="manageUsersCopyPassword()">Copy password</button>
                    </div>
                    <p style="font-size:11px;color:#94a3b8;margin:8px 0 0;">Copy before or after saving. Minimum 8 characters.</p>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" onclick="closePasswordModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Department Modal -->
    <div id="deptModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:1000;">
        <div style="background:white; padding:20px; border-radius:8px; width:90%; max-width:350px;">
            <h3 style="margin-top:0;">Change Department</h3>
            <p id="deptModalUser" style="margin-bottom:15px; font-size:13px; color:#555;"></p>
            <form method="POST" action="<?= htmlspecialchars($manageUsersFormAction, ENT_QUOTES, 'UTF-8') ?>">
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

    <!-- Register Employee Modal -->
    <div id="registerModal" class="mu-overlay" aria-hidden="true">
        <div class="mu-panel" onclick="event.stopPropagation()">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;font-size:18px;font-weight:700;">Register New Employee</h3>
                <button type="button" onclick="closeRegisterModal()" style="border:none;background:none;font-size:24px;cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars($manageUsersFormAction, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="register">
                
                <div class="form-group">
                    <label for="reg_full_name">Full Name (used as username)</label>
                    <input type="text" id="reg_full_name" name="full_name" required placeholder="e.g. John Doe">
                </div>
                
                <div class="form-group">
                    <label for="reg_email">Email Address</label>
                    <input type="email" id="reg_email" name="email" required placeholder="john@example.com">
                </div>
                
                <div class="form-group">
                    <label for="reg_department">Department</label>
                    <select id="reg_department" name="department" required>
                        <option value="" disabled selected>Select department</option>
                        <option value="Procurement">Procurement</option>
                        <option value="IT">IT</option>
                        <option value="Finance">Finance</option>
                        <option value="Sales">Sales</option>
                        <option value="Driver">Driver</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <label for="reg_password">Password (min. 8 characters)</label>
                        <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:11px;" onclick="manageUsersGeneratePassword('reg_password','reg_confirm_password')">Generate</button>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:6px;">
                        <input type="password" id="reg_password" name="password" required minlength="8" autocomplete="new-password" style="flex:1;">
                        <button type="button" class="btn-ghost" style="padding:8px;font-size:12px;" onclick="manageUsersTogglePasswordVisibility('reg_password', this)">Show</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reg_confirm_password">Confirm password</label>
                    <div style="display:flex;gap:8px;margin-top:6px;">
                        <input type="password" id="reg_confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" style="flex:1;">
                        <button type="button" class="btn-ghost" style="padding:8px;font-size:12px;" onclick="manageUsersTogglePasswordVisibility('reg_confirm_password', this)">Show</button>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeRegisterModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Register Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/voucher-v5.js?v=9"></script>
    <script>
        function manageUsersSyncPasswordCopyField() {
            const src = document.getElementById('new_password');
            const preview = document.getElementById('pwCopyField');
            if (src && preview) {
                preview.value = src.value;
            }
        }

        function manageUsersCopyPassword() {
            manageUsersSyncPasswordCopyField();
            const pwd = (document.getElementById('new_password') || {}).value || '';
            if (pwd.length < 8) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'Enter or generate a password (min. 8 characters)', showConfirmButton: false, timer: 2500 });
                }
                return;
            }
            const done = function () {
                const btn = document.getElementById('pwCopyBtn');
                if (btn) {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function () {
                        btn.textContent = 'Copy password';
                        btn.classList.remove('copied');
                    }, 2000);
                }
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Password copied', showConfirmButton: false, timer: 2000 });
                }
            };
            const preview = document.getElementById('pwCopyField');
            if (preview) {
                preview.select();
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(pwd).then(done).catch(function () {
                    try {
                        document.execCommand('copy');
                        done();
                    } catch (e) {
                        Swal.fire({ toast: true, icon: 'error', title: 'Could not copy — select the password and press Ctrl+C', showConfirmButton: false, timer: 3000 });
                    }
                });
            } else {
                try {
                    document.execCommand('copy');
                    done();
                } catch (e) {
                    Swal.fire({ toast: true, icon: 'info', title: 'Select the password field and press Ctrl+C', showConfirmButton: false, timer: 3000 });
                }
            }
        }

        function manageUsersGeneratePassword(fieldId, confirmId) {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$';
            let pwd = '';
            for (let i = 0; i < 12; i++) {
                pwd += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const el = document.getElementById(fieldId);
            const el2 = document.getElementById(confirmId);
            if (el) { el.type = 'text'; el.value = pwd; }
            if (el2) { el2.type = 'text'; el2.value = pwd; }
            manageUsersSyncPasswordCopyField();
            manageUsersSyncBulkCopyField();
        }

        function manageUsersTogglePasswordVisibility(fieldId, btn) {
            const el = document.getElementById(fieldId);
            if (!el) return;
            const show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
        }

        function manageUser(userId, action) {
            const configs = {
                'activate': { title: 'Activate User?', text: 'This will restore the user\'s access to the system.', icon: 'question', color: '#27ae60' },
                'deactivate': { title: 'Deactivate User?', text: 'The user will no longer be able to log in.', icon: 'warning', color: '#b91c1c' }
            };
            
            const config = configs[action];
            
            Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonColor: config.color,
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
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
            });
        }

        function openDeptModal(userId, currentDept, userName) {
            document.getElementById('deptUserId').value = userId;
            document.getElementById('deptModalUser').textContent = 'For user: ' + userName;
            document.getElementById('deptSelect').value = currentDept;
            document.getElementById('deptModal').style.display = 'flex';
        }

        function muOpenOverlay(id) {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
            el.classList.add('is-open');
            el.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function muCloseOverlay(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('is-open');
            el.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.mu-overlay.is-open')) {
                document.body.style.overflow = '';
            }
        }

        function openRegisterModal() {
            muOpenOverlay('registerModal');
        }

        function closeRegisterModal() {
            muCloseOverlay('registerModal');
        }

        function openBulkResetModal() {
            const phrase = document.getElementById('bulk_confirm_phrase');
            const bp = document.getElementById('bulk_password');
            const bcp = document.getElementById('bulk_confirm_password');
            const copy = document.getElementById('bulkPwCopyField');
            if (phrase) phrase.value = '';
            if (bp) { bp.value = ''; bp.type = 'password'; }
            if (bcp) { bcp.value = ''; bcp.type = 'password'; }
            if (copy) copy.value = '';
            const incSelf = document.getElementById('bulk_include_self');
            const incAdmin = document.getElementById('bulk_include_system_admin');
            if (incSelf) incSelf.checked = false;
            if (incAdmin) incAdmin.checked = false;
            manageUsersUpdateBulkCount();
            muOpenOverlay('bulkResetModal');
        }

        function closeBulkResetModal() {
            muCloseOverlay('bulkResetModal');
        }

        function manageUsersUpdateBulkCount() {
            const form = document.getElementById('bulkResetForm');
            const label = document.getElementById('bulkResetCountLabel');
            if (!form || !label) return;
            let n = parseInt(form.getAttribute('data-base-count') || '0', 10);
            if (document.getElementById('bulk_include_self') && document.getElementById('bulk_include_self').checked) {
                n += 1;
            }
            if (document.getElementById('bulk_include_system_admin') && document.getElementById('bulk_include_system_admin').checked
                && form.getAttribute('data-has-system-admin') === '1') {
                n += 1;
            }
            label.textContent = String(n);
        }

        function manageUsersSyncBulkCopyField() {
            const src = document.getElementById('bulk_password');
            const preview = document.getElementById('bulkPwCopyField');
            if (src && preview) {
                preview.value = src.value;
            }
        }

        function manageUsersCopyBulkPassword() {
            manageUsersSyncBulkCopyField();
            const pwd = (document.getElementById('bulk_password') || {}).value || '';
            if (pwd.length < 8) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'Enter or generate a password (min. 8 characters)', showConfirmButton: false, timer: 2500 });
                return;
            }
            const preview = document.getElementById('bulkPwCopyField');
            if (preview) preview.select();
            const done = function () {
                const btn = document.getElementById('bulkPwCopyBtn');
                if (btn) {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function () { btn.textContent = 'Copy password'; btn.classList.remove('copied'); }, 2000);
                }
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Password copied', showConfirmButton: false, timer: 2000 });
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(pwd).then(done).catch(function () {
                    try { document.execCommand('copy'); done(); } catch (e) {}
                });
            } else {
                try { document.execCommand('copy'); done(); } catch (e) {}
            }
        }

        function openPasswordModalFromBtn(btn) {
            const userId = btn.getAttribute('data-user-id') || '';
            const userName = btn.getAttribute('data-user-name') || '';
            openPasswordModal(userId, userName);
        }

        function openPasswordModal(userId, userName) {
            document.getElementById('passwordUserId').value = userId;
            document.getElementById('passwordModalUser').textContent = 'User: ' + userName;
            const np = document.getElementById('new_password');
            const cp = document.getElementById('confirm_password');
            const copyField = document.getElementById('pwCopyField');
            const copyBtn = document.getElementById('pwCopyBtn');
            if (np) { np.value = ''; np.type = 'password'; }
            if (cp) { cp.value = ''; cp.type = 'password'; }
            if (copyField) { copyField.value = ''; }
            if (copyBtn) { copyBtn.textContent = 'Copy password'; copyBtn.classList.remove('copied'); }
            document.querySelectorAll('.actions-dropdown-menu').forEach(m => m.classList.remove('show'));
            muOpenOverlay('passwordModal');
            if (np && !np._muCopyBound) {
                np._muCopyBound = true;
                np.addEventListener('input', manageUsersSyncPasswordCopyField);
            }
            setTimeout(function () {
                const f = document.getElementById('new_password');
                if (f) f.focus();
            }, 100);
        }

        function closePasswordModal() {
            muCloseOverlay('passwordModal');
        }

        // Close modals on backdrop click
        document.addEventListener('click', function (event) {
            const registerModal = document.getElementById('registerModal');
            const deptModal = document.getElementById('deptModal');
            const passwordModal = document.getElementById('passwordModal');
            const bulkResetModal = document.getElementById('bulkResetModal');
            if (event.target === registerModal) closeRegisterModal();
            if (event.target === deptModal) deptModal.style.display = 'none';
            if (event.target === passwordModal) closePasswordModal();
            if (event.target === bulkResetModal) closeBulkResetModal();
        });
        
        function deleteUser(userId, userName, voucherCount) {
            let text = `Are you sure you want to permanently delete user "${userName}"?`;
            if (voucherCount > 0) {
                text += `\n\nWarning: This user has ${voucherCount} voucher(s) associated. They will be reassigned to the system admin.`;
            }
            text += '\n\nThis action cannot be undone.';
            
            Swal.fire({
                title: 'Delete Permanently?',
                text: text,
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Delete User',
                cancelButtonText: 'No, Keep User'
            }).then((result) => {
                if (result.isConfirmed) {
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
            });
        }
        
        let muCurrentPage = 1;

        function muGetVisibleRows() {
            const rows = Array.from(document.querySelectorAll('#usersTable tbody tr.mu-user-row'));
            const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
            const role = document.getElementById('filterRole').value;
            const status = document.getElementById('filterStatus').value;
            return rows.filter(function (row) {
                const blob = row.getAttribute('data-search') || '';
                const matchQ = q === '' || blob.indexOf(q) !== -1;
                const matchRole = role === 'all' || row.getAttribute('data-role') === role;
                const matchStatus = status === 'all' || row.getAttribute('data-status') === status;
                return matchQ && matchRole && matchStatus;
            });
        }

        function muRenderPagination(total, perPage, page) {
            const pages = Math.max(1, Math.ceil(total / perPage));
            if (page > pages) {
                page = pages;
            }
            muCurrentPage = page;
            const wrap = document.getElementById('muPagination');
            wrap.innerHTML = '';
            const prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'mu-page-btn';
            prev.innerHTML = '&lsaquo;';
            prev.disabled = page <= 1;
            prev.onclick = function () { muGoPage(page - 1); };
            wrap.appendChild(prev);
            let start = Math.max(1, page - 1);
            let end = Math.min(pages, start + 2);
            start = Math.max(1, end - 2);
            for (let p = start; p <= end; p++) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'mu-page-btn' + (p === page ? ' active' : '');
                b.textContent = String(p);
                b.onclick = (function (n) { return function () { muGoPage(n); }; })(p);
                wrap.appendChild(b);
            }
            const next = document.createElement('button');
            next.type = 'button';
            next.className = 'mu-page-btn';
            next.innerHTML = '&rsaquo;';
            next.disabled = page >= pages;
            next.onclick = function () { muGoPage(page + 1); };
            wrap.appendChild(next);
            return pages;
        }

        function muGoPage(page) {
            muCurrentPage = page;
            muApplyFilters(false);
        }

        function muApplyFilters(resetPage) {
            if (resetPage !== false) muCurrentPage = 1;
            const allRows = Array.from(document.querySelectorAll('#usersTable tbody tr.mu-user-row'));
            allRows.forEach(function (r) { r.classList.add('mu-hidden'); });
            const visible = muGetVisibleRows();
            const perPage = parseInt(document.getElementById('muPerPage').value, 10) || 10;
            const pages = muRenderPagination(visible.length, perPage, muCurrentPage);
            const start = (muCurrentPage - 1) * perPage;
            const end = Math.min(start + perPage, visible.length);
            for (let i = start; i < end; i++) {
                visible[i].classList.remove('mu-hidden');
            }
            const info = document.getElementById('muPageInfo');
            if (visible.length === 0) {
                info.textContent = 'Showing 0 of ' + allRows.length + ' users';
            } else {
                info.textContent = 'Showing ' + (start + 1) + ' to ' + end + ' of ' + visible.length + ' users'
                    + (visible.length < allRows.length ? ' (filtered from ' + allRows.length + ')' : '');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            muApplyFilters();
        });

        // Action Dropdown Toggle logic
        function toggleRowDropdown(btn) {
            const menu = btn.nextElementSibling;
            const isOpen = menu.classList.contains('show');
            
            // Close all other dropdowns
            document.querySelectorAll('.actions-dropdown-menu').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            
            menu.classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        window.addEventListener('click', function(e) {
            if (!e.target.closest('.actions-dropdown')) {
                document.querySelectorAll('.actions-dropdown-menu').forEach(m => m.classList.remove('show'));
            }
        });
    </script>
    <script>
        // SweetAlert Toast Logic
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        <?php if (isset($success)): ?>
            Toast.fire({
                icon: 'success',
                title: '<?= clean_input($success) ?>'
            });
        <?php endif; ?>

        <?php if (isset($error)): ?>
            Toast.fire({
                icon: 'error',
                title: '<?= clean_input($error) ?>'
            });
        <?php endif; ?>

        <?php
        if (!empty($_SESSION['manage_users_pw_flash']) && is_array($_SESSION['manage_users_pw_flash'])):
            $pwFlash = $_SESSION['manage_users_pw_flash'];
            unset($_SESSION['manage_users_pw_flash']);
            $flashName = (string) ($pwFlash['name'] ?? 'User');
            $flashPass = (string) ($pwFlash['password'] ?? '');
        ?>
        Swal.fire({
            title: 'Login password (copy now)',
            html: '<p style="margin:0 0 12px;font-size:14px;color:#475569;">For <strong><?= htmlspecialchars($flashName, ENT_QUOTES, 'UTF-8') ?></strong>. This is the only time it can be shown.</p>'
                + '<input type="text" id="pwFlashCopy" readonly value="<?= htmlspecialchars($flashPass, ENT_QUOTES, 'UTF-8') ?>" '
                + 'style="width:100%;padding:12px;font-size:16px;font-weight:700;letter-spacing:0.05em;border:2px solid #e2e8f0;border-radius:8px;text-align:center;">',
            icon: 'info',
            confirmButtonText: 'Copy password',
            confirmButtonColor: '#0f172a',
            showCancelButton: true,
            cancelButtonText: 'Close'
        }).then((result) => {
            if (result.isConfirmed) {
                const inp = document.getElementById('pwFlashCopy');
                if (inp) {
                    inp.select();
                    navigator.clipboard.writeText(inp.value).then(() => {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copied', showConfirmButton: false, timer: 2000 });
                    }).catch(() => {
                        document.execCommand('copy');
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copied', showConfirmButton: false, timer: 2000 });
                    });
                }
            }
        });
        <?php endif; ?>

        <?php
        if (!empty($_SESSION['manage_users_bulk_pw_flash']) && is_array($_SESSION['manage_users_bulk_pw_flash'])):
            $bulkFlash = $_SESSION['manage_users_bulk_pw_flash'];
            unset($_SESSION['manage_users_bulk_pw_flash']);
            $bulkFlashPass = (string) ($bulkFlash['password'] ?? '');
            $bulkFlashCount = (int) ($bulkFlash['count'] ?? 0);
        ?>
        Swal.fire({
            title: 'Shared password applied',
            html: '<p style="margin:0 0 12px;font-size:14px;color:#475569;">'
                + '<?= (int) $bulkFlashCount ?> user(s) now use this password. Emails were <strong>not</strong> changed.</p>'
                + '<input type="text" id="bulkPwFlashCopy" readonly value="<?= htmlspecialchars($bulkFlashPass, ENT_QUOTES, 'UTF-8') ?>" '
                + 'style="width:100%;padding:12px;font-size:16px;font-weight:700;letter-spacing:0.05em;border:2px solid #e2e8f0;border-radius:8px;text-align:center;">',
            icon: 'success',
            confirmButtonText: 'Copy password',
            confirmButtonColor: '#0f172a',
            showCancelButton: true,
            cancelButtonText: 'Close'
        }).then((result) => {
            if (result.isConfirmed) {
                const inp = document.getElementById('bulkPwFlashCopy');
                if (inp) {
                    inp.select();
                    navigator.clipboard.writeText(inp.value).then(() => {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copied', showConfirmButton: false, timer: 2000 });
                    }).catch(() => {
                        document.execCommand('copy');
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copied', showConfirmButton: false, timer: 2000 });
                    });
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
