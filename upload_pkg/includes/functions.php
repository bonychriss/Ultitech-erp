<?php
require_once 'config.php';

// Build a full URL path using APP_BASE_PATH.
// Example: app_url('/employee/dashboard.php') → '/payment-voucher-system/employee/dashboard.php' (local) or '/employee/dashboard.php' (prod)
function app_url($path = '/') {
    $base = rtrim(APP_BASE_PATH, '/');
    $p = '/' . ltrim((string)$path, '/');
    return $base . $p;
}

function forceHttps() {
    // Only force on production or if explicitly desired
    $isLocal = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
    if ($isLocal) return;

    // Check for HTTPS or Forwarded Proto (for proxies/load balancers)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if (!$isHttps) {
        $location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $location);
        exit;
    }
}

function authenticate($userOrEmail, $password) {
    global $pdo;
    
    try {
        // Allow login by username OR email for a simpler UX
        $stmt = $pdo->prepare("SELECT id, username, password, full_name, role, department FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
        $stmt->execute([$userOrEmail, $userOrEmail]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Harden session and ensure cookie is set
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            @session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            return true;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_ADMIN;
}

// Simple CSRF token utilities (idempotent). Stores tokens per session.
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// Finance users: identified by department value 'Finance' (case-insensitive).
// Admins are not treated as Finance by default for actions restricted to Finance only,
// but you can expand this if needed.
function isFinance() {
    // Admins have full access to finance controls
    if (isAdmin()) return true;

    if (!isset($_SESSION['department'])) return false;
    $dept = (string)$_SESSION['department'];
    // Treat any department string that contains the standalone word "finance" (case-insensitive) as Finance.
    // This tolerates values like "Finance", "FINANCE", "Finance Dept", "Finance Department".
    return (preg_match('/\bfinance\b/i', $dept) === 1);
}

function requireLogin() {
    if (!isLoggedIn()) {
        global $pdo;
        $needRegister = false;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 1");
            $row = $stmt->fetch();
            $needRegister = ((int)($row['c'] ?? 0) === 0);
        } catch (Exception $e) {
            // Fail-safe: if we cannot query, assume at least one user exists to avoid exposing registration unnecessarily
            $needRegister = false;
        }
        if ($needRegister) {
            header('Location: ' . app_url('/register.php'));
        } else {
            header('Location: ' . app_url('/login.php'));
        }
        exit;
    }
}

// System Time Helper
function getSystemTime() {
    global $pdo;
    static $cachedTime = null;
    
    if ($cachedTime !== null) return $cachedTime;

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $timezone = $settings['system_timezone'] ?? 'Africa/Dar_es_Salaam';
        $overrideEnabled = (int)($settings['system_time_override_enabled'] ?? 0);
        $overrideTime = $settings['system_override_time'] ?? '';

        date_default_timezone_set($timezone);

        if ($overrideEnabled && !empty($overrideTime)) {
            $cachedTime = new DateTime($overrideTime);
        } else {
            $cachedTime = new DateTime('now');
        }
    } catch (Exception $e) {
        $cachedTime = new DateTime('now');
    }
    
    return $cachedTime;
}

function getSystemTimeFormat() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_time_format'");
        $stmt->execute();
        $fmt = $stmt->fetchColumn();
        return ($fmt === '12') ? 'h:i A' : 'H:i';
    } catch (Exception $e) {
        return 'H:i';
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ../employee/dashboard.php');
        exit();
    }
}

function requireFinanceOrAdmin() {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    $department = $_SESSION['department'] ?? '';
    
    // Allow admins and finance department users
    if (!isAdmin() && strtolower($department) !== 'finance') {
        header('Location: ../../select-module.php?error=access_denied');
        exit();
    }
}


function logout() {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// ---------------- Flash notifications (session-based) ----------------
function set_flash($type, $message) {
    if (!isset($_SESSION)) { session_start(); }
    $_SESSION['flash'] = [
        'type' => (string)$type,
        'message' => (string)$message,
        'ts' => time()
    ];
}

function get_flash() {
    if (!isset($_SESSION)) { session_start(); }
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Backward-compatible camelCase aliases
if (!function_exists('setFlash')) {
    function setFlash($type, $message) { return set_flash($type, $message); }
}
if (!function_exists('getFlash')) {
    function getFlash() { return get_flash(); }
}

function generateVoucherNumber() {
    global $pdo;
    
    try {
        $year = date('Y');
        $prefix = "PV/UGC/$year/";
        
        // Get the highest existing sequence number for this year
        $stmt = $pdo->prepare("
            SELECT voucher_no FROM payment_vouchers 
            WHERE voucher_no LIKE ? 
            ORDER BY CAST(SUBSTRING_INDEX(voucher_no, '/', -1) AS UNSIGNED) DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefix . '%']);
        $lastVoucher = $stmt->fetch();
        
        if ($lastVoucher) {
            // Extract the sequence number from the last voucher
            $parts = explode('/', $lastVoucher['voucher_no']);
            $lastSequence = isset($parts[3]) ? intval($parts[3]) : 0;
            $nextSequence = $lastSequence + 1;
        } else {
            // First voucher of the year
            $nextSequence = 1;
        }
        
        // Keep trying until we find a unique number (safety check)
        $maxAttempts = 1000;
        $attempts = 0;
        
        do {
            $voucherNumber = $prefix . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);
            
            // Check if this voucher number already exists
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM payment_vouchers WHERE voucher_no = ?");
            $checkStmt->execute([$voucherNumber]);
            $exists = $checkStmt->fetch()['count'] > 0;
            
            if (!$exists) {
                return $voucherNumber;
            }
            
            $nextSequence++;
            $attempts++;
            
        } while ($attempts < $maxAttempts);
        
        // Fallback if we can't find a unique number
        return $prefix . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        
    } catch (Exception $e) {
        // Fallback voucher number if database query fails
        return "PV/UGC/" . date('Y') . "/" . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
    }
}

function canEditVoucher($voucher_id, $user_id) {
    global $pdo;
    // Include posted flag so we can lock editing after posting
    $stmt = $pdo->prepare("SELECT status, created_by, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id = ?");
    $stmt->execute([$voucher_id]);
    $voucher = $stmt->fetch();
    
    if (!$voucher) return false;
    
    // Admin can always edit
    if (isAdmin()) return true;
    // Once posted, only admin can edit
    if ((int)($voucher['is_posted'] ?? 0) === 1) return false;
    // Any logged-in employee can edit pending vouchers (regardless of creator)
    return $voucher['status'] === STATUS_PENDING;
}

function logVoucherAction($voucher_id, $user_id, $action, $comments = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO approval_logs (voucher_id, user_id, action, comments) VALUES (?, ?, ?, ?)");
        $stmt->execute([$voucher_id, $user_id, $action, $comments]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Strictly mark a voucher as paid (Finance/Admin only) and only if status is approved
// Returns ['ok'=>true] on success or ['ok'=>false,'error'=>'message'] on failure
function markVoucherPaidStrict($voucher_id, $user_id) {
    global $pdo;
    if (!isAdmin() && !isFinance()) {
        return ['ok'=>false, 'error'=>'Not authorized'];
    }
    try {
        $pdo->beginTransaction();
        // Lock the voucher row and fetch core fields needed for validation
        $stmt = $pdo->prepare("SELECT id, status, IFNULL(is_paid,0) AS is_paid, approved_by, COALESCE(payee_name,'') AS payee_name, COALESCE(total_amount,0) AS total_amount FROM payment_vouchers WHERE id=? FOR UPDATE");
        $stmt->execute([(int)$voucher_id]);
        $row = $stmt->fetch();
        if (!$row) { throw new Exception('Voucher not found'); }
        $statusLower = strtolower((string)($row['status'] ?? ''));
        if ($statusLower !== 'approved') { throw new Exception('Only approved vouchers can be marked paid'); }
        if ((int)($row['is_paid'] ?? 0) === 1) { throw new Exception('Voucher already paid'); }
        // Compute completeness (draft detection) using core fields and item count
    $payeeTrim = trim((string)$row['payee_name']);
    $payeeOk = $payeeTrim !== '' && stripos($payeeTrim, '(draft') !== 0; // treat placeholder '(Draft)' as incomplete
        $amountOk = (float)$row['total_amount'] > 0;
        $itemCount = 0;
        try {
            $ci = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_items WHERE voucher_id = ?');
            $ci->execute([(int)$voucher_id]);
            $itemCount = (int)($ci->fetch()['c'] ?? 0);
        } catch (Exception $eCount) { $itemCount = 0; }
        $hasItems = $itemCount > 0;

        // For Finance users, block marking paid if the voucher appears incomplete/draft
        if (!isAdmin()) {
            if (!$payeeOk || !$amountOk || !$hasItems) {
                throw new Exception('Voucher is incomplete (draft). Complete details and get admin approval before payment.');
            }
        }
        // Enforce that finance users can only mark paid if approved by an admin
        if (!isAdmin()) {
            $approverId = isset($row['approved_by']) ? (int)$row['approved_by'] : 0;
            if ($approverId <= 0) {
                throw new Exception('Approval must be completed by an admin before Finance can mark paid');
            }
            $u = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
            $u->execute([$approverId]);
            $ur = $u->fetch();
            if (!$ur || (string)$ur['role'] !== ROLE_ADMIN) {
                throw new Exception('Only admin-approved vouchers can be marked paid by Finance');
            }
        }

        $up = $pdo->prepare("UPDATE payment_vouchers SET is_paid=1, paid_by=?, paid_at=NOW() WHERE id=?");
        $up->execute([(int)$user_id, (int)$voucher_id]);
        logVoucherAction($voucher_id, $user_id, 'paid', null);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok'=>false, 'error'=>$e->getMessage()];
    }
    // Best-effort notify the creator
    try { notifyUserVoucherStatus($voucher_id, 'paid'); } catch (Exception $eN) { /* ignore */ }
    return ['ok'=>true];
}

// Mark a voucher as posted (final finance bookkeeping step)
// Preconditions: voucher exists, is_paid = 1, is_posted = 0, caller is finance OR admin.
// Returns array: ['ok'=>bool, 'error'=>string|null]
function markVoucherPosted($voucher_id, $user_id) {
    global $pdo;
    // Fetch current state
    $stmt = $pdo->prepare("SELECT id, IFNULL(is_paid,0) AS is_paid, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id=? LIMIT 1");
    $stmt->execute([(int)$voucher_id]);
    $row = $stmt->fetch();
    if (!$row) return ['ok'=>false,'error'=>'Voucher not found'];
    if ((int)$row['is_posted'] === 1) return ['ok'=>false,'error'=>'Already posted'];
    if ((int)$row['is_paid'] !== 1) return ['ok'=>false,'error'=>'Voucher must be paid first'];
    if (!isAdmin() && !isFinance()) return ['ok'=>false,'error'=>'Not authorized'];

    try {
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE payment_vouchers SET is_posted=1, posted_by=?, posted_at=NOW() WHERE id=?");
        $up->execute([(int)$user_id, (int)$voucher_id]);
        logVoucherAction($voucher_id, $user_id, 'posted', null);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'Database error posting voucher'];
    }
    // Notify creator (best-effort)
    try { notifyUserVoucherStatus($voucher_id, 'posted'); } catch (Exception $eN) { /* ignore */ }
    return ['ok'=>true,'error'=>null];
}

// -------------- Notifications --------------
function ensureNotificationsSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    
    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        audience ENUM('user','admin','all') NOT NULL DEFAULT 'user',
        title VARCHAR(150) NOT NULL,
        message TEXT,
        type VARCHAR(20) DEFAULT 'info',
        voucher_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (audience),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Add type column if missing (migration)
    try {
        $pdo->query("SELECT type FROM notifications LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(20) DEFAULT 'info' AFTER message");
        } catch (PDOException $e2) { /* ignore */ }
    }
    
    $ensured = true;
}

function createNotification($opts) {
    // $opts = ['user_id'=>int|null, 'audience'=>'user'|'admin'|'all', 'title'=>string, 'message'=>string, 'type'=>'info'|'success'|'warning'|'danger', 'voucher_id'=>int|null]
    global $pdo;
    ensureNotificationsSchema();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, audience, title, message, type, voucher_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $opts['user_id'] ?? null,
        $opts['audience'] ?? 'user',
        $opts['title'] ?? '',
        $opts['message'] ?? null,
        $opts['type'] ?? 'info',
        $opts['voucher_id'] ?? null,
    ]);
}

function getNotificationsForCurrentUser($limit = 10) {
    global $pdo;
    ensureNotificationsSchema();
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE (audience IN ('user','all') AND (user_id = ? OR audience='all')) ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return [];
}

function getNotificationsForCurrentUserPaged($limit = 20, $offset = 0) {
    global $pdo;
    ensureNotificationsSchema();
    $limit = (int)$limit;
    $offset = (int)$offset;
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE (audience IN ('user','all') AND (user_id = ? OR audience='all')) ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return [];
}

function getUnreadCountForCurrentUser() {
    global $pdo;
    ensureNotificationsSchema();
    if (isAdmin()) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM notifications WHERE audience IN ('admin','all') AND is_read = 0");
        return (int)$stmt->fetch()['c'];
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0 AND (user_id = ? OR audience='all') AND audience IN ('user','all')");
        $stmt->execute([$_SESSION['user_id']]);
        return (int)$stmt->fetch()['c'];
    }
    return 0;
}

function markAllNotificationsReadForCurrentUser() {
    global $pdo;
    ensureNotificationsSchema();
    if (isAdmin()) {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE audience IN ('admin','all')");
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR audience='all') AND audience IN ('user','all')");
        $stmt->execute([$_SESSION['user_id']]);
    }
}

function notifyAdminsNewVoucher($voucher_id) {
    global $pdo;
    // Fetch voucher info
    $stmt = $pdo->prepare("SELECT voucher_no, payee_name, total_amount FROM payment_vouchers WHERE id = ?");
    $stmt->execute([$voucher_id]);
    $v = $stmt->fetch();
    if (!$v) return;
    $title = 'New voucher submitted';
    $msg = sprintf('Voucher %s submitted for %s (%.2f).', $v['voucher_no'], $v['payee_name'], $v['total_amount']);
    createNotification([
        'user_id' => null,
        'audience' => 'admin',
        'title' => $title,
        'message' => $msg,
        'voucher_id' => $voucher_id,
    ]);
}

function notifyUserVoucherStatus($voucher_id, $status) {
    global $pdo;
    // fetch owner
    $stmt = $pdo->prepare("SELECT voucher_no, created_by FROM payment_vouchers WHERE id = ?");
    $stmt->execute([$voucher_id]);
    $v = $stmt->fetch();
    if (!$v) return;
    $title = 'Voucher ' . strtoupper($status);
    if ($status === 'posted') {
        $msg = sprintf('Your voucher %s has been posted (finalized).', $v['voucher_no']);
    } else {
        $msg = sprintf('Your voucher %s has been %s.', $v['voucher_no'], $status);
    }
    createNotification([
        'user_id' => (int)$v['created_by'],
        'audience' => 'user',
        'title' => $title,
        'message' => $msg,
        'voucher_id' => $voucher_id,
    ]);
}

/**
 * Notify the selected Finance user (Checked By) that a voucher needs checking.
 * Looks up payment_vouchers.checked_by (full name), resolves to users.id, and creates a user-scoped notification.
 * Safe to call even if no checked_by is set or user not found (no-op).
 */
function notifyCheckedByAssignee($voucher_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT voucher_no, checked_by FROM payment_vouchers WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$voucher_id]);
        $v = $stmt->fetch();
        if (!$v) return; // no voucher
        $checkedByName = trim((string)($v['checked_by'] ?? ''));
        if ($checkedByName === '') return; // nothing to notify

        // Resolve full_name -> user_id (active)
        $u = $pdo->prepare("SELECT id FROM users WHERE full_name = ? AND is_active = 1 LIMIT 1");
        $u->execute([$checkedByName]);
        $row = $u->fetch();
        if (!$row || empty($row['id'])) return; // user not found

        $title = 'Voucher requires checking';
        $msg = sprintf('You were chosen to check voucher %s. Please visit the voucher to confirm.', (string)$v['voucher_no']);
        createNotification([
            'user_id' => (int)$row['id'],
            'audience' => 'user',
            'title' => $title,
            'message' => $msg,
            'voucher_id' => (int)$voucher_id,
        ]);
    } catch (Exception $e) {
        // Best-effort; do not throw
        if (function_exists('app_log')) { app_log('notifyCheckedByAssignee failed for voucher '.$voucher_id.': '.$e->getMessage()); }
    }
}

function canDeleteVoucher($voucher_id, $user_id) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT status, created_by FROM payment_vouchers WHERE id = ?");
    $stmt->execute([$voucher_id]);
    $voucher = $stmt->fetch();

    if (!$voucher) return false;

    // Admin can always delete
    if (isAdmin()) return true;

    // Employee can delete their own voucher only if it's not approved yet (pending or rejected)
    return $voucher['created_by'] == $user_id && $voucher['status'] !== STATUS_APPROVED;
}

// -------------- Schema patchers --------------
// Ensure signature_path column on users exists
function ensureUserSignatureColumn() {
    global $pdo;
    static $ensuredSig = false;
    if ($ensuredSig) return;
    try {
        $pdo->query("SELECT signature_path FROM users LIMIT 1"); 
    } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN signature_path VARCHAR(255) NULL AFTER department"); } catch (PDOException $e2) { /* ignore */ }
    }
    $ensuredSig = true;
}

// -------------- Schema patch: add checked_by to payment_vouchers if missing --------------
function ensureCheckedByColumnOnPaymentVouchers() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    try {
        // Probe for column; if missing, an exception will be thrown
        $pdo->query("SELECT `checked_by` FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN checked_by VARCHAR(50) NULL AFTER prepared_by");
        } catch (PDOException $e2) {
            // Silently ignore to avoid blocking page load; forms will still work if column exists
        }
    }
    $ensured = true;
}

// Ensure payment confirmation columns exist
function ensurePaidColumnsOnPaymentVouchers() {
    global $pdo;
    static $ensuredPaid = false;
    if ($ensuredPaid) return;
    try { $pdo->query("SELECT is_paid, paid_by, paid_at FROM payment_vouchers LIMIT 1"); }
    catch (PDOException $e) {
        // Add columns if any missing; attempt individually for resilience
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_by"); } catch(PDOException $e2) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN paid_by INT NULL AFTER is_paid"); } catch(PDOException $e3) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN paid_at TIMESTAMP NULL AFTER paid_by"); } catch(PDOException $e4) { /* ignore */ }
        // Add FK if possible
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_payment_vouchers_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL"); } catch(PDOException $e5) { /* ignore */ }
    }
    $ensuredPaid = true;
}

// Ensure custom tasks table exists
ensureTasksSchema();

// Ensure schema patch is applied early for pages that reference the field
ensureCheckedByColumnOnPaymentVouchers();
ensurePaidColumnsOnPaymentVouchers();
ensureUserSignatureColumn();
// Ensure meetings-related tables/columns exist for signaling and participants
ensureMeetingsSchema();
// Ensure voucher attachments table exists early (dashboard queries it directly)
ensureVoucherAttachmentsSchema();
// Try to ensure the signatures directory exists at startup (best-effort)
ensureSignatureDir();
// Optionally perform heavier schema ensures only when explicitly enabled (reduces per-request latency)
if (defined('SCHEMA_EAGER_ENSURE') && SCHEMA_EAGER_ENSURE) {
    // Ensure swift document column exists for payment proof uploads
    ensureSwiftDocumentColumn();
    // Ensure posted columns (finance bookkeeping marker) exist
    ensurePostedColumnsOnPaymentVouchers();
    // Ensure attendance table early for pages relying on it
    ensureAttendanceTable();
}

// -------------- Voucher attachments schema & helpers --------------
function ensureVoucherAttachmentsSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    // Table to store individual uploaded supporting documents per voucher
    $pdo->exec("CREATE TABLE IF NOT EXISTS voucher_attachments (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        voucher_id INT NOT NULL,\n        file_path VARCHAR(300) NOT NULL,\n        original_name VARCHAR(255) NOT NULL,\n        mime_type VARCHAR(150) NOT NULL,\n        size_bytes INT NOT NULL DEFAULT 0,\n        uploaded_by INT NOT NULL,\n        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n        INDEX(voucher_id),\n        INDEX(uploaded_by),\n        FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id) ON DELETE CASCADE,\n        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $ensured = true;
}

// Ensure swift_document column on payment_vouchers (stores a single proof of payment file path)
function ensureSwiftDocumentColumn() {
    global $pdo;
    static $ensuredSwift = false;
    if ($ensuredSwift) return;
    try {
        $pdo->query("SELECT swift_document FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN swift_document VARCHAR(300) NULL AFTER paid_at"); } catch (PDOException $e2) { /* ignore */ }
    }
    $ensuredSwift = true;
}

// Ensure posted bookkeeping columns on payment_vouchers
function ensurePostedColumnsOnPaymentVouchers() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    try {
        $pdo->query("SELECT is_posted, posted_by, posted_at FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN is_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER swift_document"); } catch(PDOException $e2) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN posted_by INT NULL AFTER is_posted"); } catch(PDOException $e3) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN posted_at TIMESTAMP NULL AFTER posted_by"); } catch(PDOException $e4) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_payment_vouchers_posted_by FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL"); } catch(PDOException $e5) { /* ignore */ }
    }
    $ensured = true;
}

// -------------- Meetings schema (tables for meetings, participants, and signaling) --------------
function ensureMeetingsSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    try {
        // meetings table
        $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            created_by INT NOT NULL,
            meeting_code VARCHAR(20) NOT NULL UNIQUE,
            scheduled_time DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            INDEX(created_by),
            CONSTRAINT fk_meetings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // meeting_participants table
        $pdo->exec("CREATE TABLE IF NOT EXISTS meeting_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            peer_id VARCHAR(100) NULL,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            left_at TIMESTAMP NULL DEFAULT NULL,
            is_muted TINYINT(1) NOT NULL DEFAULT 0,
            is_video_on TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_meeting_user_active (meeting_id, user_id, joined_at),
            INDEX(meeting_id),
            INDEX(user_id),
            INDEX(peer_id),
            CONSTRAINT fk_participants_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // meeting_signals table (for WebRTC offers/answers/ICE)
        $pdo->exec("CREATE TABLE IF NOT EXISTS meeting_signals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            signal_type VARCHAR(20) NOT NULL,
            signal_data LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX(meeting_id),
            INDEX(to_user_id),
            INDEX(from_user_id),
            CONSTRAINT fk_signals_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            CONSTRAINT fk_signals_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_signals_to_user FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Lightweight column ensures if tables exist but columns are missing
        // Probe meetings.is_locked
        try { $pdo->query("SELECT is_locked FROM meetings LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meetings ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meetings.host_id
        try { $pdo->query("SELECT host_id FROM meetings LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meetings ADD COLUMN host_id INT NULL AFTER created_by, ADD CONSTRAINT fk_meetings_host FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE SET NULL"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meetings.start_time
        try { $pdo->query("SELECT start_time FROM meetings LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meetings ADD COLUMN start_time DATETIME NULL AFTER scheduled_time"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meetings.end_time
        try { $pdo->query("SELECT end_time FROM meetings LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meetings ADD COLUMN end_time DATETIME NULL AFTER start_time"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meeting_participants.peer_id
        try { $pdo->query("SELECT peer_id FROM meeting_participants LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN peer_id VARCHAR(100) NULL AFTER user_id, ADD INDEX(peer_id)"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meeting_participants.role
        try { $pdo->query("SELECT role FROM meeting_participants LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN role ENUM('host','guest') DEFAULT 'guest' AFTER peer_id"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meeting_participants.is_video_on
        try { $pdo->query("SELECT is_video_on FROM meeting_participants LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN is_video_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_muted"); } catch (PDOException $e2) { /* ignore */ } }
        // Probe meeting_signals.created_at
        try { $pdo->query("SELECT created_at FROM meeting_signals LIMIT 1"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE meeting_signals ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER signal_data"); } catch (PDOException $e2) { /* ignore */ } }
        
        // Create meeting chat messages table
        $pdo->exec("CREATE TABLE IF NOT EXISTS meeting_chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(meeting_id),
            INDEX(user_id),
            INDEX(sent_at),
            CONSTRAINT fk_chat_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $ex) {
        // Do not block page loads on ensure errors
        if (function_exists('error_log')) { error_log('ensureMeetingsSchema error: ' . $ex->getMessage()); }
    }
    $ensured = true;
}

// Get chat messages for a meeting
function getMeetingChatMessages($meeting_id, $limit = 50) {
    global $pdo;
    ensureMeetingsSchema();
    $stmt = $pdo->prepare("SELECT mcm.*, u.full_name 
        FROM meeting_chat_messages mcm
        JOIN users u ON mcm.user_id = u.id
        WHERE mcm.meeting_id = ?
        ORDER BY mcm.sent_at ASC
        LIMIT ?");
    $stmt->bindValue(1, (int)$meeting_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Send a chat message in a meeting
function sendMeetingChatMessage($meeting_id, $user_id, $message) {
    global $pdo;
    ensureMeetingsSchema();
    $stmt = $pdo->prepare("INSERT INTO meeting_chat_messages (meeting_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([(int)$meeting_id, (int)$user_id, trim($message)]);
    return $pdo->lastInsertId();
}

// Ensure Petty Cash Schema
function ensurePettyCashSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    
    try {
        // Petty Cash Vouchers Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_vouchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            voucher_number VARCHAR(50) UNIQUE NOT NULL,
            date DATE NOT NULL,
            custodian_id INT NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT,
            amount DECIMAL(10,2) NOT NULL,
            receipt_path VARCHAR(255),
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejection_reason TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(voucher_number),
            INDEX(date),
            INDEX(status),
            INDEX(custodian_id),
            INDEX(category),
            FOREIGN KEY (custodian_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Petty Cash Replenishments Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_replenishments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            replenishment_number VARCHAR(50) UNIQUE NOT NULL,
            date DATE NOT NULL,
            custodian_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            previous_balance DECIMAL(10,2) NOT NULL,
            new_balance DECIMAL(10,2) NOT NULL,
            notes TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(date),
            INDEX(status),
            INDEX(custodian_id),
            FOREIGN KEY (custodian_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Petty Cash Balance Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS petty_cash_balance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            custodian_id INT NOT NULL UNIQUE,
            current_balance DECIMAL(10,2) NOT NULL DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (custodian_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
    } catch (Exception $ex) {
        if (function_exists('error_log')) { error_log('ensurePettyCashSchema error: ' . $ex->getMessage()); }
    }
    $ensured = true;
}

// Generate unique voucher number
function generatePettyCashVoucherNumber() {
    global $pdo;
    $prefix = 'PCV-' . date('Ym') . '-';
    $stmt = $pdo->query("SELECT voucher_number FROM petty_cash_vouchers WHERE voucher_number LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int)substr($last, -4) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// Generate unique replenishment number
function generateReplenishmentNumber() {
    global $pdo;
    $prefix = 'REP-' . date('Ym') . '-';
    $stmt = $pdo->query("SELECT replenishment_number FROM petty_cash_replenishments WHERE replenishment_number LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int)substr($last, -4) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// Get petty cash balance for custodian
function getPettyCashBalance($custodian_id) {
    global $pdo;
    ensurePettyCashSchema();
    $stmt = $pdo->prepare("SELECT current_balance FROM petty_cash_balance WHERE custodian_id = ?");
    $stmt->execute([(int)$custodian_id]);
    $balance = $stmt->fetchColumn();
    return $balance !== false ? (float)$balance : 0.00;
}

// Update petty cash balance
function updatePettyCashBalance($custodian_id, $amount, $operation = 'subtract') {
    global $pdo;
    ensurePettyCashSchema();
    
    // Get current balance
    $current = getPettyCashBalance($custodian_id);
    
    // Calculate new balance
    if ($operation === 'add') {
        $new_balance = $current + $amount;
    } else {
        $new_balance = $current - $amount;
    }
    
    // Update or insert
    $stmt = $pdo->prepare("INSERT INTO petty_cash_balance (custodian_id, current_balance) VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE current_balance = ?");
    $stmt->execute([(int)$custodian_id, $new_balance, $new_balance]);
    
    return $new_balance;
}

// Get all petty cash vouchers
function getAllPettyCashVouchers($filters = []) {
    global $pdo;
    ensurePettyCashSchema();
    
    $where = [];
    $params = [];
    
    if (!empty($filters['status'])) {
        $where[] = "v.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['custodian_id'])) {
        $where[] = "v.custodian_id = ?";
        $params[] = (int)$filters['custodian_id'];
    }
    
    if (!empty($filters['category'])) {
        $where[] = "v.category = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['date_from'])) {
        $where[] = "v.date >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $where[] = "v.date <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT v.*, 
            c.full_name as custodian_name,
            a.full_name as approved_by_name,
            cr.full_name as created_by_name
        FROM petty_cash_vouchers v
        LEFT JOIN users c ON v.custodian_id = c.id
        LEFT JOIN users a ON v.approved_by = a.id
        LEFT JOIN users cr ON v.created_by = cr.id
        {$whereClause}
        ORDER BY v.date DESC, v.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get single voucher
function getPettyCashVoucher($id) {
    global $pdo;
    ensurePettyCashSchema();
    
    $stmt = $pdo->prepare("SELECT v.*, 
            c.full_name as custodian_name,
            a.full_name as approved_by_name,
            cr.full_name as created_by_name
        FROM petty_cash_vouchers v
        LEFT JOIN users c ON v.custodian_id = c.id
        LEFT JOIN users a ON v.approved_by = a.id
        LEFT JOIN users cr ON v.created_by = cr.id
        WHERE v.id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

// Create petty cash voucher
function createPettyCashVoucher($data) {
    global $pdo;
    ensurePettyCashSchema();
    
    $voucher_number = generatePettyCashVoucherNumber();
    
    $stmt = $pdo->prepare("INSERT INTO petty_cash_vouchers 
        (voucher_number, date, custodian_id, category, description, amount, receipt_path, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $voucher_number,
        $data['date'],
        (int)$data['custodian_id'],
        $data['category'],
        $data['description'],
        (float)$data['amount'],
        $data['receipt_path'] ?? null,
        (int)$data['created_by']
    ]);
    
    return $pdo->lastInsertId();
}

// Approve petty cash voucher
function approvePettyCashVoucher($voucher_id, $approved_by) {
    global $pdo;
    ensurePettyCashSchema();
    
    // Get voucher details
    $voucher = getPettyCashVoucher($voucher_id);
    if (!$voucher) return false;
    
    // Update voucher status
    $stmt = $pdo->prepare("UPDATE petty_cash_vouchers 
        SET status = 'approved', approved_by = ?, approved_at = NOW() 
        WHERE id = ?");
    $stmt->execute([(int)$approved_by, (int)$voucher_id]);
    
    // Deduct from balance
    updatePettyCashBalance($voucher['custodian_id'], $voucher['amount'], 'subtract');
    
    return true;
}

// Reject petty cash voucher
function rejectPettyCashVoucher($voucher_id, $approved_by, $reason = '') {
    global $pdo;
    ensurePettyCashSchema();
    
    $stmt = $pdo->prepare("UPDATE petty_cash_vouchers 
        SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? 
        WHERE id = ?");
    $stmt->execute([(int)$approved_by, $reason, (int)$voucher_id]);
    
    return true;
}

// Get petty cash categories
function getPettyCashCategories() {
    return [
        'Office Supplies',
        'Transport/Fuel',
        'Meals & Refreshments',
        'Utilities',
        'Maintenance',
        'Postage & Courier',
        'Miscellaneous'
    ];
}

function ensureVoucherUploadsDir() {
    $dir = dirname(__DIR__) . '/assets/uploads/vouchers';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (is_dir($dir) && !is_writable($dir)) { @chmod($dir, 0775); }
    return $dir;
}

// Retrieve attachments for a voucher
function getVoucherAttachments($voucherId) {
    global $pdo;
    ensureVoucherAttachmentsSchema();
    $stmt = $pdo->prepare("SELECT id, file_path, original_name, mime_type, size_bytes, uploaded_at FROM voucher_attachments WHERE voucher_id = ? ORDER BY id");
    $stmt->execute([(int)$voucherId]);
    return $stmt->fetchAll();
}

// Record a single attachment row (internal helper)
function addVoucherAttachment($voucherId, $storedPath, $originalName, $mimeType, $sizeBytes, $uploadedBy) {
    global $pdo;
    ensureVoucherAttachmentsSchema();
    $stmt = $pdo->prepare("INSERT INTO voucher_attachments (voucher_id, file_path, original_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([(int)$voucherId, $storedPath, $originalName, $mimeType, (int)$sizeBytes, (int)$uploadedBy]);
}

// -------------- Signature helpers --------------
function getUserSignaturePathById($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch();
    return $row && !empty($row['signature_path']) ? $row['signature_path'] : null;
}

function getUserSignaturePathByName($fullName) {
    global $pdo;
    if (!$fullName) return null;
    $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE full_name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$fullName]);
    $row = $stmt->fetch();
    return $row && !empty($row['signature_path']) ? $row['signature_path'] : null;
}

function ensureSignatureDir() {
    $dir = dirname(__DIR__) . '/assets/signatures';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Try to ensure writable
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return $dir;
}

/**
 * Centralized signature upload handler.
 * Supports either a file upload (PNG/JPEG) or a base64 canvas payload (data:image/*;base64).
 * Normalizes output to PNG, stores in assets/signatures, and updates users.signature_path.
 * Returns associative array: ['ok'=>bool, 'path'=>string|null, 'error'=>string|null]
 * Security / validation steps:
 *  - Validates upload error codes
 *  - Strict MIME sniffing via finfo (fallback on extension)
 *  - Size limit (default 500KB)
 *  - Optional dimension enforcement (max 1200x600)
 *  - Generates unique hashed file name per upload to avoid caching collisions
 *  - Ensures directory exists and is writable
 */
function handleUserSignatureUpload($userId, $fileField = 'signature_file', $maxBytes = 500000) {
    global $pdo;
    ensureUserSignatureColumn();
    $userId = (int)$userId;
    $dir = ensureSignatureDir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok'=>false,'error'=>'Signature directory not writable','path'=>null];
    }

    $savedPath = null;
    $sourceType = null; // 'canvas' | 'upload'

    // 1. Canvas base64 path
    if (!empty($_POST['signatureData']) && strpos($_POST['signatureData'], 'data:image') === 0) {
        $raw = (string)$_POST['signatureData'];
        $comma = strpos($raw, ',');
        if ($comma === false) {
            return ['ok'=>false,'error'=>'Malformed data URI','path'=>null];
        }
        $b64 = substr($raw, $comma + 1);
        $bin = base64_decode($b64);
        if ($bin === false) {
            return ['ok'=>false,'error'=>'Invalid base64 data','path'=>null];
        }
        if (strlen($bin) > $maxBytes) {
            return ['ok'=>false,'error'=>'Canvas image exceeds size limit','path'=>null];
        }
        // Optional dimension check
        $info = @getimagesizefromstring($bin);
        if ($info) {
            if ($info[0] > 1600 || $info[1] > 800) {
                return ['ok'=>false,'error'=>'Image dimensions too large (max 1600x800)','path'=>null];
            }
        }
        $name = 'sig_' . $userId . '_' . substr(hash('sha256', $userId . '|' . microtime(true) . '|' . random_bytes(8)), 0, 16) . '.png';
        $target = $dir . '/' . $name;
        if (@file_put_contents($target, $bin, LOCK_EX) === false) {
            return ['ok'=>false,'error'=>'Failed to write signature file','path'=>null];
        }
        $savedPath = 'assets/signatures/' . $name;
        $sourceType = 'canvas';
    }

    // 2. File upload path (only if not already saved)
    if (!$savedPath && isset($_FILES[$fileField])) {
        $err = $_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            if ($err === UPLOAD_ERR_NO_FILE) {
                return ['ok'=>false,'error'=>'No file selected','path'=>null];
            }
            $map = [
                UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server size limit',
                UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds form size limit',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'PHP extension blocked the upload'
            ];
            return ['ok'=>false,'error'=>$map[$err] ?? 'Unknown upload error','path'=>null];
        }
        $tmp = $_FILES[$fileField]['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            return ['ok'=>false,'error'=>'Invalid upload (tmp not found)','path'=>null];
        }
        $size = (int)($_FILES[$fileField]['size'] ?? 0);
        if ($size <= 0) { return ['ok'=>false,'error'=>'Empty file','path'=>null]; }
        if ($size > $maxBytes) { return ['ok'=>false,'error'=>'File exceeds size limit (500KB)','path'=>null]; }

        // MIME sniff
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $tmp) : null;
        if ($finfo) { @finfo_close($finfo); }
        $allowed = ['image/png','image/jpeg'];
        if (!$mime || !in_array(strtolower($mime), $allowed, true)) {
            return ['ok'=>false,'error'=>'Only PNG or JPEG images are allowed','path'=>null];
        }

        // Load & normalize to PNG (if GD available). If GD missing and MIME is png, move file directly.
        $pngName = 'sig_' . $userId . '_' . substr(hash('sha256', $userId . '|' . microtime(true) . '|' . random_bytes(8)), 0, 16) . '.png';
        $target = $dir . '/' . $pngName;
        if (function_exists('imagecreatefromstring')) {
            $blob = @file_get_contents($tmp);
            if ($blob === false) { return ['ok'=>false,'error'=>'Failed to read uploaded file','path'=>null]; }
            $img = @imagecreatefromstring($blob);
            if (!$img) { return ['ok'=>false,'error'=>'Unsupported or corrupted image','path'=>null]; }
            // Optional dimension constraint
            $w = imagesx($img); $h = imagesy($img);
            if ($w > 1600 || $h > 800) { imagedestroy($img); return ['ok'=>false,'error'=>'Image dimensions too large (max 1600x800)','path'=>null]; }
            if (!@imagepng($img, $target)) { imagedestroy($img); return ['ok'=>false,'error'=>'Failed saving PNG','path'=>null]; }
            imagedestroy($img);
        } else {
            if (strtolower($mime) !== 'image/png') {
                return ['ok'=>false,'error'=>'PNG only (enable GD for JPEG support)','path'=>null]; }
            if (!@move_uploaded_file($tmp, $target)) {
                return ['ok'=>false,'error'=>'Failed moving uploaded file','path'=>null]; }
        }
        $savedPath = 'assets/signatures/' . $pngName;
        $sourceType = 'upload';
    }

    if (!$savedPath) {
        return ['ok'=>false,'error'=>'No signature data provided','path'=>null];
    }

    // Persist path to user row (replace previous if any)
    try {
        $stmt = $pdo->prepare('UPDATE users SET signature_path = ? WHERE id = ?');
        $stmt->execute([$savedPath, $userId]);
    } catch (Exception $e) {
        return ['ok'=>false,'error'=>'DB error saving signature','path'=>null];
    }
    return ['ok'=>true,'error'=>null,'path'=>$savedPath,'source'=>$sourceType];
}

/**
 * Render signature <img> tag for a user by ID or by exact full name.
 * $subject may be int user id or string user full_name.
 * $opts: ['class'=>string additional classes, 'maxHeight'=>int px, 'alt'=>string]
 * Returns HTML string (safe) or empty string if no signature or not found.
 */
function renderSignatureTag($subject, $opts = []) {
    $sigPath = null;
    if (is_int($subject) || ctype_digit($subject)) {
        $sigPath = getUserSignaturePathById((int)$subject);
    } else if (is_string($subject) && trim($subject) !== '') {
        $sigPath = getUserSignaturePathByName($subject);
    }
    if (!$sigPath) return '';
    // Normalize relative path for caller context (account pages are one level deeper usually)
    $rel = $sigPath;
    if (strpos($rel, 'assets/') === 0) {
        // Caller must prepend appropriate ../ if needed; we keep raw path here.
    }
    $class = 'signature-img' . (!empty($opts['class']) ? (' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', $opts['class'])) : '');
    $maxH = isset($opts['maxHeight']) ? (int)$opts['maxHeight'] : 52;
    $alt = htmlspecialchars($opts['alt'] ?? 'Signature', ENT_QUOTES, 'UTF-8');
    return '<img src="' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="' . $class . '" style="max-height:' . $maxH . 'px; width:auto; object-fit:contain; display:inline-block;" />';
}

/**
 * Delete a user's signature file and clear DB reference.
 */
function deleteUserSignature($userId) {
    global $pdo;
    $userId = (int)$userId;
    $existing = getUserSignaturePathById($userId);
    if ($existing) {
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($existing, '/\\'));
        if (is_file($abs)) { @unlink($abs); }
    }
    $stmt = $pdo->prepare('UPDATE users SET signature_path = NULL WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Record attendance entry using existing account signature image.
 * $signType: 'sign_in' | 'sign_out'
 * Reads signature file from users.signature_path, converts to data URL (PNG), and stores with basic telemetry.
 */
function recordAttendanceWithAccountSignature($userId, $signType, $lat = 0.0, $lon = 0.0, $distance = 0.0, $deviceInfo = null, $ip = null) {
    global $pdo;
    ensureAttendanceTable();
    $userId = (int)$userId;
    $signType = ($signType === 'sign_out') ? 'sign_out' : 'sign_in';

    $sigPath = getUserSignaturePathById($userId);
    if (!$sigPath) {
        return ['ok'=>false, 'error'=>'No signature on file. Please add one in My Account.'];
    }
    // Build absolute path
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($sigPath, '/\\'));
    if (!is_file($abs) || !is_readable($abs)) {
        return ['ok'=>false, 'error'=>'Signature file not found on server'];
    }
    $bytes = @file_get_contents($abs);
    if ($bytes === false) {
        return ['ok'=>false, 'error'=>'Failed reading signature image'];
    }
    // Assume PNG as stored by handler; if not, best-effort MIME via finfo.
    $mime = 'image/png';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $m = @finfo_file($fi, $abs); if ($m) { $mime = $m; } @finfo_close($fi); }
    }
    $payload = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    // Basic sanitation for numbers
    $lat = is_numeric($lat) ? (float)$lat : 0.0;
    $lon = is_numeric($lon) ? (float)$lon : 0.0;
    $distance = is_numeric($distance) ? (float)$distance : 0.0;
    $deviceInfo = $deviceInfo !== null ? substr((string)$deviceInfo, 0, 255) : null;
    $ip = $ip !== null ? substr((string)$ip, 0, 45) : null;

    try {
        // Prevent consecutive same-type sign attempts
        $last = getLastAttendanceForUser($userId);
        if ($last) {
            $lastType = (string)($last['sign_type'] ?? '');
            if ($lastType === $signType) {
                if ($signType === 'sign_in') {
                    return ['ok'=>false,'error'=>'Already signed in. Please sign out before signing in again.'];
                } else {
                    return ['ok'=>false,'error'=>'Already signed out. Please sign in before signing out again.'];
                }
            }
        }
        // Additional rule: require at least one Sign In today before allowing Sign Out
        if ($signType === 'sign_out') {
            $q = $pdo->prepare("SELECT COUNT(*) AS c FROM attendance WHERE user_id = ? AND sign_type = 'sign_in' AND DATE(signed_at) = CURDATE()");
            $q->execute([$userId]);
            $c = (int)($q->fetch()['c'] ?? 0);
            if ($c === 0) {
                return ['ok'=>false,'error'=>'You must sign in today before signing out.'];
            }
        }
        // Compute geofence distance and enforce ONLY for sign-in (not sign-out)
        $distanceMeters = 0;
        $bypassGeofence = false;

        // Check IP Bypass
        if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED && defined('OFFICE_IPS') && OFFICE_IPS !== '') {
             $allowedIps = array_map('trim', explode(',', OFFICE_IPS));
             if (in_array((string)$ip, $allowedIps)) {
                 $bypassGeofence = true;
             }
        }

        if ($signType === 'sign_in' && defined('OFFICE_LAT') && defined('OFFICE_LON') && OFFICE_LAT != 0.0 && OFFICE_LON != 0.0) {
            // Calculate distance
             if ($lat != 0.0 || $lon != 0.0) {
                $distanceMeters = haversineDistanceMeters($lat, $lon, (float)OFFICE_LAT, (float)OFFICE_LON);
            }

            // Enforce Geofence (only if NOT bypassing via IP)
            // Also respect OFFICE_LOCATION_ENABLED if defined
            $locationEnabled = defined('OFFICE_LOCATION_ENABLED') ? OFFICE_LOCATION_ENABLED : true;
            
            if ($locationEnabled && !$bypassGeofence) {
                // If client sent 0,0 and we need location, fail
                if ($lat == 0.0 && $lon == 0.0) {
                     return ['ok'=>false,'error'=>'Location not detected. Please enable GPS and try again.'];
                }

                if (defined('OFFICE_RADIUS_M') && $distanceMeters > (int)OFFICE_RADIUS_M) {
                    return ['ok'=>false,'error'=>'You are outside the office geofence. Distance: ' . number_format($distanceMeters) . 'm.'];
                }
            }
        } elseif ($signType === 'sign_out') {
            // For sign-out, still calculate distance for record-keeping but don't enforce
            if (defined('OFFICE_LAT') && defined('OFFICE_LON') && OFFICE_LAT != 0.0 && OFFICE_LON != 0.0 && $lat != 0.0 && $lon != 0.0) {
                $distanceMeters = haversineDistanceMeters($lat, $lon, (float)OFFICE_LAT, (float)OFFICE_LON);
            }
        }

    $stmt = $pdo->prepare("INSERT INTO attendance (user_id, signature_image, latitude, longitude, distance_from_office, sign_type, device_info, ip_address) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$userId, $payload, $lat, $lon, $distanceMeters, $signType, $deviceInfo, $ip]);
        return ['ok'=>true];
    } catch (Exception $e) {
        return ['ok'=>false, 'error'=>'Failed to record attendance'];
    }
}

// Haversine distance in meters (small helper)
if (!function_exists('haversineDistanceMeters')) {
    function haversineDistanceMeters($lat1, $lon1, $lat2, $lon2) {
        $R = 6371000; // Earth radius meters
        $lat1 = deg2rad($lat1); $lon1 = deg2rad($lon1); $lat2 = deg2rad($lat2); $lon2 = deg2rad($lon2);
        $dLat = $lat2 - $lat1; $dLon = $lon2 - $lon1;
        $a = sin($dLat/2)**2 + cos($lat1) * cos($lat2) * sin($dLon/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return (int)round($R * $c);
    }
}

// Fetch most recent attendance record for a user
function getLastAttendanceForUser($userId) {
    global $pdo;
    ensureAttendanceTable();
    $stmt = $pdo->prepare("SELECT id, sign_type, signed_at, latitude, longitude, distance_from_office FROM attendance WHERE user_id = ? ORDER BY signed_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int)$userId]);
    return $stmt->fetch();
}

// Determine if current user is in an "attendance locked" state (signed in today and not yet signed out)
function isAttendanceLocked($userId = null) {
    global $pdo;
    if (isAdmin()) { return false; }
    if ($userId === null) {
        if (!isLoggedIn()) { return false; }
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }
    $userId = (int)$userId;
    if ($userId <= 0) { return false; }
    try {
        ensureAttendanceTable();
        // Look only at today's attendance trail; if last action is sign_in, consider locked
        $q = $pdo->prepare("SELECT sign_type FROM attendance WHERE user_id = ? AND DATE(signed_at) = CURDATE() ORDER BY signed_at DESC, id DESC LIMIT 1");
        $q->execute([$userId]);
        $row = $q->fetch();
        if (!$row) { return false; }
        return (strtolower((string)($row['sign_type'] ?? '')) === 'sign_in');
    } catch (Exception $e) {
        return false; // fail-open to avoid blocking access on DB error
    }
}

// Guard for voucher module pages: redirect employees who are currently signed in (attendance) to the sign page
function enforceVoucherAccessUnlocked($redirectTo = null) {
    requireLogin();
    if (isAdmin()) { return; }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && isAttendanceLocked($uid)) {
        if ($redirectTo === null) {
            // Build portable path using APP_BASE_PATH
            $redirectTo = rtrim(APP_BASE_PATH, '/') . '/employee/sign.php';
        }
        $sep = (strpos($redirectTo, '?') === false) ? '?' : '&';
        header('Location: ' . $redirectTo . $sep . 'locked=1');
        exit();
    }
}

// -------------- Messages (Direct chat) --------------
function ensureProfilePhotoColumn() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Ignore if exists
    }
}

function ensureMessagesSchema() {
    global $pdo;
    ensureProfilePhotoColumn();
    static $ensured = false;
    if ($ensured) return;
    // Base table (allow recipient_id to be NULL so group messages can be stored)
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        recipient_id INT NULL,
        group_id INT NULL,
        reply_to_id INT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (recipient_id),
        INDEX (group_id),
        INDEX (sender_id),
        INDEX (reply_to_id),
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // If table already existed with NOT NULL recipient_id, relax it to NULL
    try { $pdo->exec("ALTER TABLE messages MODIFY COLUMN recipient_id INT NULL"); } catch (PDOException $e) { /* ignore if already NULL */ }
    // Ensure group_id exists on existing installs
    try { $pdo->query("SELECT group_id FROM messages LIMIT 1"); }
    catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN group_id INT NULL AFTER recipient_id"); } catch (PDOException $e2) { /* ignore */ }
        try { $pdo->exec("CREATE INDEX idx_messages_group_id ON messages(group_id)"); } catch (PDOException $e3) { /* ignore */ }
    }

    // Add reply_to_id if missing on existing installs
    try { $pdo->query("SELECT reply_to_id FROM messages LIMIT 1"); }
    catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN reply_to_id INT NULL AFTER recipient_id"); } catch (PDOException $e2) { /* ignore */ }
        try { $pdo->exec("CREATE INDEX idx_messages_reply_to_id ON messages(reply_to_id)"); } catch (PDOException $e3) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE messages ADD CONSTRAINT fk_messages_reply FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL"); } catch (PDOException $e4) { /* ignore */ }
    }

    // Attachments table for messages
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        file_path VARCHAR(300) NOT NULL,
        file_name VARCHAR(200) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        size_bytes INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (message_id),
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure message_reads table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (message_id, user_id),
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Ensure message_reactions table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (message_id, user_id, reaction),
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure pinned_messages table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS pinned_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        group_id INT NOT NULL,
        pinned_by INT NOT NULL,
        pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (message_id),
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure typing_indicators table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS typing_indicators (
        user_id INT NOT NULL,
        group_id INT NOT NULL,
        last_typed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Group chat core tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_by),
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_group_members (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        role ENUM('owner','member') NOT NULL DEFAULT 'member',
        PRIMARY KEY (group_id, user_id),
        INDEX (user_id),
        FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Add FK on messages.group_id (best-effort; may fail if already exists)
    try { $pdo->exec("ALTER TABLE messages ADD CONSTRAINT fk_messages_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE"); } catch (PDOException $e) { /* ignore */ }

    // Per-user group read tracker table
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_group_reads (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        last_read_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (group_id, user_id),
        INDEX (user_id),
        FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Message reactions table
    try { $pdo->query("SELECT id FROM message_reactions LIMIT 1"); }
    catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id, reaction),
            INDEX (message_id),
            INDEX (user_id),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    // Message edits and deletion tracking
    try { $pdo->query("SELECT edited_at FROM messages LIMIT 1"); }
    catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN edited_at TIMESTAMP NULL AFTER created_at"); } catch (PDOException $e2) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read"); } catch (PDOException $e3) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN edited_message TEXT NULL AFTER message"); } catch (PDOException $e4) { /* ignore */ }
    }
    
    // Pinned messages table
    try { $pdo->query("SELECT id FROM pinned_messages LIMIT 1"); }
    catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pinned_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            group_id INT NOT NULL,
            pinned_by INT NOT NULL,
            pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_pin (message_id),
            INDEX (group_id),
            INDEX (pinned_by),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (pinned_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    // Typing indicators table
    try { $pdo->query("SELECT id FROM typing_indicators LIMIT 1"); }
    catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS typing_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            group_id INT NOT NULL,
            last_typed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_group (user_id, group_id),
            INDEX (group_id),
            INDEX (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    // Message read receipts (who has seen which message)
    try { $pdo->query("SELECT id FROM message_reads LIMIT 1"); }
    catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS message_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_read (message_id, user_id),
            INDEX (message_id),
            INDEX (user_id),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    $ensured = true;
}



// Ensure uploads directory for chat attachments exists
function ensureMessageUploadsDir() {
    $dir = dirname(__DIR__) . '/assets/uploads/messages';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (is_dir($dir) && !is_writable($dir)) { @chmod($dir, 0775); }
    return $dir;
}

// Ensure a default global chat group exists and that the given user is a member
function ensureGlobalGroupAndMembership($userId) {
    global $pdo;
    ensureMessagesSchema();
    // Find or create the General group
    $stmt = $pdo->prepare("SELECT id FROM chat_groups WHERE name = 'General' LIMIT 1");
    $stmt->execute();
    $gidRow = $stmt->fetch();
    if ($gidRow && !empty($gidRow['id'])) {
        $gid = (int)$gidRow['id'];
    } else {
        $ins = $pdo->prepare("INSERT INTO chat_groups (name, created_by) VALUES ('General', ?)");
        $ins->execute([(int)$userId]);
        $gid = (int)$pdo->lastInsertId();
    }
    // Ensure membership for this user
    $mem = $pdo->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $mem->execute([$gid, (int)$userId]);
    return $gid;
}

function updateGroupLastRead($groupId, $userId) {
    global $pdo;
    ensureMessagesSchema();
    $up = $pdo->prepare("INSERT INTO chat_group_reads (group_id, user_id, last_read_at) VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE last_read_at = NOW()");
    $up->execute([(int)$groupId, (int)$userId]);
}

function getUnreadMessagesCountForCurrentUser() {
    global $pdo;
    ensureMessagesSchema();
    if (!isLoggedIn()) return 0;
    $uid = (int)$_SESSION['user_id'];
    $gid = ensureGlobalGroupAndMembership($uid);
    // Get last read time for this user in the global group
    $stmt = $pdo->prepare("SELECT last_read_at FROM chat_group_reads WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$gid, $uid]);
    $row = $stmt->fetch();
    $last = $row && !empty($row['last_read_at']) ? $row['last_read_at'] : null;
    if ($last) {
        $q = $pdo->prepare("SELECT COUNT(*) AS c FROM messages WHERE group_id = ? AND created_at > ? AND sender_id <> ?");
        $q->execute([$gid, $last, $uid]);
    } else {
        // No last read marker; count all messages except user's own as unread
        $q = $pdo->prepare("SELECT COUNT(*) AS c FROM messages WHERE group_id = ? AND sender_id <> ?");
        $q->execute([$gid, $uid]);
    }
    $r = $q->fetch();
    return (int)($r['c'] ?? 0);
}

// -------------- Attendance/Signature System --------------
function ensureAttendanceTable() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            signature_image TEXT NOT NULL COMMENT 'Base64 encoded signature image',
            latitude DECIMAL(10, 8) NOT NULL COMMENT 'Employee GPS latitude',
            longitude DECIMAL(11, 8) NOT NULL COMMENT 'Employee GPS longitude',
            distance_from_office DECIMAL(10, 2) NOT NULL COMMENT 'Distance in meters from office',
            sign_type ENUM('sign_in', 'sign_out') NOT NULL DEFAULT 'sign_in',
            device_info VARCHAR(255) NULL COMMENT 'Browser/device information',
            ip_address VARCHAR(45) NULL COMMENT 'IP address',
            signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (signed_at),
            INDEX (sign_type),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ensured = true;
    } catch (PDOException $e) {
        error_log('Failed to create attendance table: ' . $e->getMessage());
    }
}

// Lightweight application logger (append-only). Writes to storage/logs/app.log
if (!function_exists('app_log')) {
    function app_log($message) {
        try {
            $base = dirname(__DIR__);
            $logDir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
            $file = $logDir . DIRECTORY_SEPARATOR . 'app.log';
            $line = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($message) ? $message : json_encode($message)) . "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // ignore logging errors
        }
    }
}

// -------------- Daily Task System --------------
function createTask($user_id, $type, $description) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, type, description, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$user_id, $type, $description]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function updateTaskStatus($task_id, $status, $feedback = null) {
    global $pdo;
    try {
        $sql = "UPDATE tasks SET status = ?";
        $params = [$status];
        if ($feedback !== null) {
            $sql .= ", admin_feedback = ?";
            $params[] = $feedback;
        }
        $sql .= " WHERE id = ?";
        $params[] = $task_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getTasks($user_id, $filter_type = null) {
    global $pdo;
    $sql = "SELECT * FROM tasks WHERE user_id = ?";
    $params = [$user_id];
    if ($filter_type) {
        $sql .= " AND type = ?";
        $params[] = $filter_type;
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAllTasks($filter_date = null, $filter_user = null) {
    global $pdo;
    $sql = "SELECT t.*, u.full_name, u.department FROM tasks t JOIN users u ON t.user_id = u.id WHERE 1=1";
    $params = [];
    if ($filter_date) {
        $sql .= " AND DATE(t.created_at) = ?";
        $params[] = $filter_date;
    }
    if ($filter_user) {
        $sql .= " AND t.user_id = ?";
        $params[] = $filter_user;
    }
    $sql .= " ORDER BY t.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPendingTasks($status = 'pending') {
    global $pdo;
    $sql = "SELECT t.*, u.full_name, u.department FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.status = ? ORDER BY t.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

function getTaskById($task_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT t.*, u.full_name, u.department FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $stmt->execute([$task_id]);
    return $stmt->fetch();
}

// Check if user has created a daily task today
function hasDailyTaskToday($user_id) {
    global $pdo;
    try {
        // Check if tasks table exists first
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'tasks'");
        if ($tableCheck->rowCount() === 0) {
            // Table doesn't exist yet, return false (no task requirement)
            return false;
        }
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM tasks 
            WHERE user_id = ? 
            AND type = 'daily' 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (PDOException $e) {
        // On any error, fail gracefully (don't block sign-out)
        error_log("hasDailyTaskToday error: " . $e->getMessage());
        return false;
    }
}

// ==================== MEETING FUNCTIONS ====================

/**
 * Generate a unique meeting code
 */
function generateMeetingCode() {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return substr($code, 0, 3) . '-' . substr($code, 3, 3);
}

/**
 * Create a new meeting
 */
function createMeeting($title, $user_id, $scheduled_time = null) {
    global $pdo;
    
    // Generate unique meeting code
    do {
        $code = generateMeetingCode();
        $stmt = $pdo->prepare("SELECT id FROM meetings WHERE meeting_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    
    $stmt = $pdo->prepare("
        INSERT INTO meetings (title, created_by, meeting_code, scheduled_time, status)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $status = $scheduled_time ? 'scheduled' : 'active';
    $stmt->execute([$title, $user_id, $code, $scheduled_time, $status]);
    
    return [
        'id' => $pdo->lastInsertId(),
        'code' => $code
    ];
}

/**
 * Get meeting by ID
 */
function getMeetingById($meeting_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as creator_name, u.department as creator_department
        FROM meetings m
        JOIN users u ON m.created_by = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$meeting_id]);
    return $stmt->fetch();
}

/**
 * Get meeting by code
 */
function getMeetingByCode($code) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as creator_name, u.department as creator_department
        FROM meetings m
        JOIN users u ON m.created_by = u.id
        WHERE m.meeting_code = ?
    ");
    $stmt->execute([$code]);
    return $stmt->fetch();
}

/**
 * Get all meetings for a user
 */
function getUserMeetings($user_id, $status = null) {
    global $pdo;
    
    $sql = "
        SELECT DISTINCT m.*, u.full_name as creator_name, u.department as creator_department,
               (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id AND left_at IS NULL) as active_participants
        FROM meetings m
        JOIN users u ON m.created_by = u.id
        LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
        WHERE (m.created_by = ? OR mp.user_id = ? OR u.role = 'admin')
    ";
    
    $params = [$user_id, $user_id];
    
    if ($status) {
        $sql .= " AND m.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY m.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get all meetings (admin only)
 */
function getAllMeetings($status = null) {
    global $pdo;
    
    $sql = "
        SELECT m.*, u.full_name as creator_name, u.department as creator_department,
               (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id AND left_at IS NULL) as active_participants
        FROM meetings m
        JOIN users u ON m.created_by = u.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($status) {
        $sql .= " AND m.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY m.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Join a meeting
 */
function joinMeeting($meeting_id, $user_id, $peer_id = null) {
    global $pdo;
    
    // Check if meeting exists and is not locked
    $meeting = getMeetingById($meeting_id);
    if (!$meeting || $meeting['is_locked']) {
        return false;
    }
    
    // Check if user is already in meeting
    $stmt = $pdo->prepare("
        SELECT id FROM meeting_participants 
        WHERE meeting_id = ? AND user_id = ? AND left_at IS NULL
    ");
    $stmt->execute([$meeting_id, $user_id]);
    
    if ($row = $stmt->fetch()) {
        // Update peer_id if provided
        if ($peer_id) {
            $update = $pdo->prepare("UPDATE meeting_participants SET peer_id = ? WHERE id = ?");
            $update->execute([$peer_id, $row['id']]);
        }
        return true; // Already in meeting
    }
    
    // Add user to participants
    $stmt = $pdo->prepare("
        INSERT INTO meeting_participants (meeting_id, user_id, joined_at, peer_id)
        VALUES (?, ?, NOW(), ?)
    ");
    return $stmt->execute([$meeting_id, $user_id, $peer_id]);
}

/**
 * Leave a meeting
 */
function leaveMeeting($meeting_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE meeting_participants 
        SET left_at = NOW()
        WHERE meeting_id = ? AND user_id = ? AND left_at IS NULL
    ");
    
    return $stmt->execute([$meeting_id, $user_id]);
}

/**
 * Get meeting participants
 */
function getMeetingParticipants($meeting_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT mp.*, u.full_name, u.department
        FROM meeting_participants mp
        JOIN users u ON mp.user_id = u.id
        WHERE mp.meeting_id = ? AND mp.left_at IS NULL
        ORDER BY mp.joined_at ASC
    ");
    
    $stmt->execute([$meeting_id]);
    return $stmt->fetchAll();
}

/**
 * Update meeting status
 */
function updateMeetingStatus($meeting_id, $status) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE meetings 
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    return $stmt->execute([$status, $meeting_id]);
}

/**
 * Delete a meeting
 */
function deleteMeeting($meeting_id) {
    global $pdo;
    
    // Delete participants first (foreign key constraint likely)
    $stmt = $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);
    
    // Delete chat messages if any
    $stmt = $pdo->prepare("DELETE FROM meeting_chat_messages WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);

    // Delete signals if any
    $stmt = $pdo->prepare("DELETE FROM meeting_signals WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);

    // Delete the meeting
    $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
    return $stmt->execute([$meeting_id]);
}

/**
 * End a meeting (and auto-delete it)
 */
function endMeeting($meeting_id) {
    return deleteMeeting($meeting_id);
}

/**
 * Ensure schema for Outstanding Invoices module
 */
function ensureOutstandingInvoicesSchema() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_outstanding_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('receivable', 'payable') NOT NULL,
        invoice_date DATE NOT NULL,
        entity_name VARCHAR(255) NOT NULL,
        invoice_number VARCHAR(50) DEFAULT NULL,
        narration TEXT,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        attachment VARCHAR(255) NULL,
        status ENUM('outstanding', 'paid') NOT NULL DEFAULT 'outstanding',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

/**
 * Lock/unlock a meeting
 */
function toggleMeetingLock($meeting_id, $is_locked) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE meetings 
        SET is_locked = ?
        WHERE id = ?
    ");
    
    return $stmt->execute([$is_locked, $meeting_id]);
}


// -------------- WhatsApp Notification Helpers --------------

/**
 * Clean phone number for WhatsApp
 */
function cleanWhatsAppNumber($number) {
    // Remove non-numeric characters except +
    $clean = preg_replace('/[^0-9+]/', '', $number);
    // If starts with 0, replace with 255 (TZ) - user preference
    if (strpos($clean, '0') === 0) {
        $clean = '255' . substr($clean, 1);
    }
    // Ensure no + for 'wa.me' link format usually, but 'wa.me/' accepts raw. 
    // Best practice: remove +.
    return ltrim($clean, '+');
}

/**
 * Generate WhatsApp Link
 */
function getWhatsAppLink($number, $message) {
    if (empty($number)) return null;
    $cleanNum = cleanWhatsAppNumber($number);
    return "https://wa.me/{$cleanNum}?text=" . urlencode($message);
}

/**
 * Determine the next person to notify based on sequential logic.
 * Flow:
 * 1. Prepared By -> Applicant (if different)
 * 2. Applicant -> Dept Manager
 * 3. Dept Manager -> Checked By
 * 4. Checked By -> Admins (General Manager)
 * 
 * Returns array: ['role' => string, 'name' => string, 'number' => string|null, 'message' => string]
 */
function getVoucherNotificationTarget($voucher, $currentUserFullName) {
    global $pdo;
    
    // Normalize names for comparison
    $me = strtolower(trim($currentUserFullName));
    $preparedBy = strtolower(trim($voucher['prepared_by'] ?? ''));
    $applicant = strtolower(trim($voucher['applicant'] ?? ''));
    $deptMgr = strtolower(trim($voucher['department_manager'] ?? ''));
    $checkedBy = strtolower(trim($voucher['checked_by'] ?? ''));
    
    $targetName = null;
    $targetRole = '';
    
    // Logic Flow
    
    // 1. If I am Prepared By, and Applicant is someone else, notify Applicant
    if ($me === $preparedBy && $preparedBy !== $applicant) {
        $targetName = $voucher['applicant'];
        $targetRole = 'Applicant';
    }
    // 2. If I am Applicant (or Prepared By acting for Applicant), notify Dept Manager
    else if ($me === $applicant || ($me === $preparedBy && $preparedBy === $applicant)) {
        $targetName = $voucher['department_manager'];
        $targetRole = 'Department Manager';
    }
    // 3. If I am Dept Manager, notify Checked By
    else if ($me === $deptMgr) {
        $targetName = $voucher['checked_by'];
        $targetRole = 'Checked By';
    }
    // 4. If I am Checked By, notify Admins (General Manager)
    else if ($me === $checkedBy) {
        // Special case: Notify any Admin or specific GM
        // Ideally we fetch a specific admin. For now, let's look for "General Manager" or first Admin.
        $targetRole = 'General Manager';
        // We will handle fetching the actual user in the next step
    }
    
    if (!$targetName && $targetRole !== 'General Manager') {
        return null;
    }
    
    $targetNumber = null;
    
    if ($targetRole === 'General Manager') {
        // Fetch valid admin/GM number and name
        // Try to find user with role 'admin' who has a whatsapp number
        $stmt = $pdo->query("SELECT full_name, whatsapp_number FROM users WHERE role = 'admin' AND whatsapp_number IS NOT NULL LIMIT 1");
        $gmRow = $stmt->fetch();
        if ($gmRow) {
            $targetNumber = $gmRow['whatsapp_number'];
            $targetName = $gmRow['full_name'];
        }
    } else {
        // Fetch target user's number
        $stmt = $pdo->prepare("SELECT whatsapp_number FROM users WHERE full_name = ? LIMIT 1");
        $stmt->execute([$targetName]);
        $targetNumber = $stmt->fetchColumn();
    }
    
    if (!$targetNumber) return null;
    
    // Construct Message
    $vNo = $voucher['voucher_no'] ?? '???';
    
    // User requested format: "Hello, (Name) Payment Voucher PV... has been generated and is ready for your review."
    // We strive to use the actual name.
    $displayName = $targetName ? $targetName : $targetRole;
    
    $msg = "Hello, {$displayName} Payment Voucher {$vNo} has been generated and is ready for your review.";
    
    return [
        'role' => $targetRole,
        'name' => $targetName, // might be null for GM
        'number' => $targetNumber,
        'message' => $msg,
        'link' => getWhatsAppLink($targetNumber, $msg)
    ];
}

// Ensure Tasks schema
function ensureTasksSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    
    try {
        $pdo->exec("ALTER TABLE tasks MODIFY COLUMN type ENUM('daily', 'community', 'overtime') NOT NULL DEFAULT 'daily'");
        $ensured = true;
    } catch (Exception $e) {
        // Ignore error if column modification fails (e.g., if already exists or table structure differs slightly)
        // Ideally checking column type first is better, but this is a quick patch for the requested feature.
        // Fallback or explicit Create if not exists:
         try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type ENUM('daily', 'community', 'overtime') NOT NULL DEFAULT 'daily',
                description TEXT NOT NULL,
                admin_feedback TEXT DEFAULT NULL,
                is_completed BOOLEAN DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
         } catch (Exception $ex) {
             error_log("Failed to create/alter tasks table: " . $ex->getMessage());
         }
    }
}


// ==================== PASSWORD RESET FUNCTIONS ====================

// Ensure Password Reset Schema exists
function ensurePasswordResetSchema() {
    global $pdo;
    try {
        // Check if columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'reset_token'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL AFTER password");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'reset_expires'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL AFTER reset_token");
        }
    } catch (PDOException $e) {
        // Ignore if already exists or other error (best effort)
    }
}

// Generate Reset Token
function generatePasswordResetToken($email) {
    global $pdo;
    ensurePasswordResetSchema();
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return false; // User not found
    }
    
    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Store hash of token for security
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
    $stmt->execute([$tokenHash, $expires, $user['id']]);
    
    return $token; // Return raw token to send to user
}

// Verify Reset Token
function verifyResetToken($token) {
    global $pdo;
    ensurePasswordResetSchema();
    
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$tokenHash]);
    return $stmt->fetchColumn(); // Returns user_id or false
}

// Reset Password
function resetUserPassword($userId, $newPassword) {
    global $pdo;
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    return $stmt->execute([$hash, $userId]);
}

?>