<?php
require_once 'config.php';
require_once __DIR__ . '/includes/functions.php';

// Fallback for APP_BASE_PATH if not defined in config.php (legacy support)
if (!defined('APP_BASE_PATH')) {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $path = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
    define('APP_BASE_PATH', str_replace('\\', '/', $path));
}

// Build a full URL path using APP_BASE_PATH.
// Example: app_url('/employee/dashboard.php') → '/payment-voucher-system/employee/dashboard.php' (local) or '/employee/dashboard.php' (prod)
if (!function_exists('app_url')) {
    function app_url($path = '/')
    {
        $base = rtrim(APP_BASE_PATH, '/');
        $p = '/' . ltrim((string) $path, '/');
        return $base . $p;
    }
}

if (!function_exists('forceHttps')) {
    function forceHttps()
    {
        // Only force on production or if explicitly desired
        $isLocal = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
        if ($isLocal)
            return;

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
}

if (!function_exists('authenticate')) {
    function authenticate($userOrEmail, $password)
    {
        global $pdo;

        try {
            // Allow login by username OR email for a simpler UX
            $stmt = $pdo->prepare("SELECT id, username, password, full_name, role, department FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$userOrEmail, $userOrEmail]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Harden session and ensure cookie is set
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
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
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin()
    {
        if (!isset($_SESSION['role'])) return false;
        $role = strtolower(trim((string)$_SESSION['role']));
        $roleAdmin = defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin';
        $roleCoAdmin = defined('ROLE_COMPANY_ADMIN') ? ROLE_COMPANY_ADMIN : 'company_admin';
        return $role === $roleAdmin || $role === $roleCoAdmin || in_array($role, ['admin', 'administrator', 'superadmin', 'super_admin', 'company_admin', 'company admin', 'owner'], true);
    }
}

// Simple CSRF token utilities (idempotent). Stores tokens per session.
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verify_csrf')) {
    function verify_csrf($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
    }
}

// Finance users: identified by department value 'Finance' (case-insensitive).
// Admins are not treated as Finance by default for actions restricted to Finance only,
// but you can expand this if needed.
if (!function_exists('isFinance')) {
    function isFinance()
    {
        // Admins have full access to finance controls
        if (isAdmin())
            return true;

        if (!isset($_SESSION['department']))
            return false;
        $dept = (string) $_SESSION['department'];
        // Treat any department string that contains the standalone word "finance" (case-insensitive) as Finance.
        // This tolerates values like "Finance", "FINANCE", "Finance Dept", "Finance Department".
        return (preg_match('/\bfinance\b/i', $dept) === 1);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        if (!isLoggedIn()) {
            global $pdo;
            $needRegister = false;
            try {
                $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 1");
                $row = $stmt->fetch();
                $needRegister = ((int) ($row['c'] ?? 0) === 0);
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
}

// System Time Helper
if (!function_exists('getSystemTime')) {
    function getSystemTime()
    {
        global $pdo;
        static $cachedTime = null;

        if ($cachedTime !== null)
            return $cachedTime;

        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $timezone = $settings['system_timezone'] ?? 'Africa/Dar_es_Salaam';
            $overrideEnabled = (int) ($settings['system_time_override_enabled'] ?? 0);
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
}

if (!function_exists('getSystemTimeFormat')) {
    function getSystemTimeFormat()
    {
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
}

if (!function_exists('requireAdmin')) {
    function requireAdmin()
    {
        requireLogin();
        if (!isAdmin()) {
            header('Location: ../employee/dashboard.php');
            exit();
        }
    }
}

if (!function_exists('requireFinanceOrAdmin')) {
    function requireFinanceOrAdmin()
    {
        requireLogin();
        $role = $_SESSION['role'] ?? '';
        $department = $_SESSION['department'] ?? '';

        // Allow admins and finance department users
        if (!isAdmin() && strtolower($department) !== 'finance') {
            header('Location: ../../select-module.php?error=access_denied');
            exit();
        }
    }
}


if (!function_exists('logout')) {
    function logout()
    {
        session_destroy();
        header('Location: ../login.php');
        exit();
    }
}

// ---------------- Flash notifications (session-based) ----------------
if (!function_exists('set_flash')) {
    function set_flash($type, $message)
    {
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['flash'] = [
            'type' => (string) $type,
            'message' => (string) $message,
            'ts' => time()
        ];
    }
}

if (!function_exists('get_flash')) {
    function get_flash()
    {
        if (!isset($_SESSION)) {
            session_start();
        }
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}

// Backward-compatible camelCase aliases
if (!function_exists('setFlash')) {
    function setFlash($type, $message)
    {
        return set_flash($type, $message);
    }
}
if (!function_exists('getFlash')) {
    function getFlash()
    {
        return get_flash();
    }
}

if (!function_exists('generateVoucherNumber')) {
    function generateVoucherNumber()
    {
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
}

if (!function_exists('canEditVoucher')) {
    function canEditVoucher($voucher_id, $user_id)
    {
        global $pdo;
        // Include posted flag so we can lock editing after posting
        $stmt = $pdo->prepare("SELECT status, created_by, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id = ?");
        $stmt->execute([$voucher_id]);
        $voucher = $stmt->fetch();

        if (!$voucher)
            return false;

        // Admin can always edit
        if (isAdmin())
            return true;
        // Once posted, only admin can edit
        if ((int) ($voucher['is_posted'] ?? 0) === 1)
            return false;
        // Any logged-in employee can edit pending vouchers (regardless of creator)
        return $voucher['status'] === STATUS_PENDING;
    }
}

if (!function_exists('logVoucherAction')) {
    function logVoucherAction($voucher_id, $user_id, $action, $comments = null)
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare("INSERT INTO approval_logs (voucher_id, user_id, action, comments) VALUES (?, ?, ?, ?)");
            $stmt->execute([$voucher_id, $user_id, $action, $comments]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

// Strictly mark a voucher as paid (Finance/Admin only) and only if status is approved
// Returns ['ok'=>true] on success or ['ok'=>false,'error'=>'message'] on failure
if (!function_exists('markVoucherPaidStrict')) {
    function markVoucherPaidStrict($voucher_id, $user_id)
    {
        global $pdo;
        if (!isAdmin() && !isFinance()) {
            return ['ok' => false, 'error' => 'Not authorized'];
        }
        try {
            $pdo->beginTransaction();
            // Lock the voucher row and fetch core fields needed for validation
            $stmt = $pdo->prepare("SELECT id, status, IFNULL(is_paid,0) AS is_paid, approved_by, COALESCE(payee_name,'') AS payee_name, COALESCE(total_amount,0) AS total_amount FROM payment_vouchers WHERE id=? FOR UPDATE");
            $stmt->execute([(int) $voucher_id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new Exception('Voucher not found');
            }
            $statusLower = strtolower((string) ($row['status'] ?? ''));
            if ($statusLower !== 'approved') {
                throw new Exception('Only approved vouchers can be marked paid');
            }
            if ((int) ($row['is_paid'] ?? 0) === 1) {
                throw new Exception('Voucher already paid');
            }
            // Compute completeness (draft detection) using core fields and item count
            $payeeTrim = trim((string) $row['payee_name']);
            $payeeOk = $payeeTrim !== '' && stripos($payeeTrim, '(draft') !== 0; // treat placeholder '(Draft)' as incomplete
            $amountOk = (float) $row['total_amount'] > 0;
            $itemCount = 0;
            try {
                $ci = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_items WHERE voucher_id = ?');
                $ci->execute([(int) $voucher_id]);
                $itemCount = (int) ($ci->fetch()['c'] ?? 0);
            } catch (Exception $eCount) {
                $itemCount = 0;
            }
            $hasItems = $itemCount > 0;

            // For Finance users, block marking paid if the voucher appears incomplete/draft
            if (!isAdmin()) {
                if (!$payeeOk || !$amountOk || !$hasItems) {
                    throw new Exception('Voucher is incomplete (draft). Complete details and get admin approval before payment.');
                }
            }
            // Enforce that finance users can only mark paid if approved by an admin
            if (!isAdmin()) {
                $approverId = isset($row['approved_by']) ? (int) $row['approved_by'] : 0;
                if ($approverId <= 0) {
                    throw new Exception('Approval must be completed by an admin before Finance can mark paid');
                }
                $u = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
                $u->execute([$approverId]);
                $ur = $u->fetch();
                if (!$ur || (string) $ur['role'] !== ROLE_ADMIN) {
                    throw new Exception('Only admin-approved vouchers can be marked paid by Finance');
                }
            }

            $up = $pdo->prepare("UPDATE payment_vouchers SET is_paid=1, paid_by=?, paid_at=NOW() WHERE id=?");
            $up->execute([(int) $user_id, (int) $voucher_id]);
            logVoucherAction($voucher_id, $user_id, 'paid', null);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        // Best-effort notify the creator
        try {
            notifyUserVoucherStatus($voucher_id, 'paid');
        } catch (Exception $eN) { /* ignore */
        }
        return ['ok' => true];
    }
}

// Mark a voucher as posted (final finance bookkeeping step)
// Preconditions: voucher exists, is_paid = 1, is_posted = 0, caller is finance OR admin.
// Returns array: ['ok'=>bool, 'error'=>string|null]
if (!function_exists('markVoucherPosted')) {
    function markVoucherPosted($voucher_id, $user_id)
    {
        global $pdo;
        // Fetch current state
        $stmt = $pdo->prepare("SELECT id, IFNULL(is_paid,0) AS is_paid, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id=? LIMIT 1");
        $stmt->execute([(int) $voucher_id]);
        $row = $stmt->fetch();
        if (!$row)
            return ['ok' => false, 'error' => 'Voucher not found'];
        if ((int) $row['is_posted'] === 1)
            return ['ok' => false, 'error' => 'Already posted'];
        if ((int) $row['is_paid'] !== 1)
            return ['ok' => false, 'error' => 'Voucher must be paid first'];
        if (!isAdmin() && !isFinance())
            return ['ok' => false, 'error' => 'Not authorized'];

        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_posted=1, posted_by=?, posted_at=NOW() WHERE id=?");
            $up->execute([(int) $user_id, (int) $voucher_id]);
            logVoucherAction($voucher_id, $user_id, 'posted', null);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            return ['ok' => false, 'error' => 'Database error posting voucher'];
        }
        // Notify creator (best-effort)
        try {
            notifyUserVoucherStatus($voucher_id, 'posted');
        } catch (Exception $eN) { /* ignore */
        }
        return ['ok' => true, 'error' => null];
    }
}
// Schema bootstrap + extended helpers: includes/functions.php (required above).
