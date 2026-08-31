<?php
// Simple environment awareness for dual setup (Local + InfinityFree Production)
// Order of precedence: includes/env.php (if present) > auto-detect > defaults

// Auto-detect environment and base path
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$APP_BASE_PATH = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

// Normalize slashes for Windows/Unix consistency
$APP_BASE_PATH = str_replace('\\', '/', $APP_BASE_PATH);

// Load environment variables from env.php if it exists (Production/cPanel)
// Support both legacy root location and current includes/ location
$__envCandidates = [__DIR__ . '/../env.php', __DIR__ . '/env.php'];
foreach ($__envCandidates as $__envPath) {
    if (file_exists($__envPath)) {
        require_once $__envPath; // defines $DB_HOST etc (variables, not constants)
        break;
    }
}

// Promote variable-based credentials to constants if present
if (isset($DB_HOST) && !defined('DB_HOST')) define('DB_HOST', $DB_HOST);
if (isset($DB_NAME) && !defined('DB_NAME')) define('DB_NAME', $DB_NAME);
if (isset($DB_USER) && !defined('DB_USER')) define('DB_USER', $DB_USER);
if (isset($DB_PASS) && !defined('DB_PASS')) define('DB_PASS', $DB_PASS);
if (isset($APP_BASE_PATH) && !defined('APP_BASE_PATH')) define('APP_BASE_PATH', $APP_BASE_PATH);

// Default to local XAMPP settings if constants aren't defined yet
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'ultimate_trading_voucher');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

if (!defined('APP_BASE_PATH')) define('APP_BASE_PATH', $APP_BASE_PATH);

// Create database connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Log detailed error; surface generic message
    error_log("Database Connection Error (" . ($e->getCode()) . "): " . $e->getMessage());
    // Optional: write to local file log if writable
    try {
        $logDir = __DIR__ . '/../storage/logs';
        if (is_dir($logDir) && is_writable($logDir)) {
            file_put_contents($logDir . '/db_errors.log', date('c') . ' | ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    } catch (Throwable $logEx) { /* ignore */ }
    die("Service temporarily unavailable. Please try again later.");
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    // Set session cookie parameters before starting the session
    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '', // let PHP decide
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // Basic fallback for very old PHP
        @ini_set('session.cookie_httponly', '1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { @ini_set('session.cookie_secure', '1'); }
    }
    // Optional: custom session name to avoid clashes on shared hosting
    if (!defined('PVS_SESSION_NAMED')) {
        @session_name('PVSSESSID');
        define('PVS_SESSION_NAMED', true);
    }
    session_start();
}

// Enable verbose errors when debug parameter is set
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @error_reporting(E_ALL);
}

// Company information
if (!defined('COMPANY_NAME')) define('COMPANY_NAME', 'ULTIMATE GENERAL TRADING');
if (!defined('COMPANY_LOGO_PATH')) define('COMPANY_LOGO_PATH', 'assets/images/logo.png');

// Office geofence for attendance system
// These can be configured via Admin > Settings page
// Or manually edit includes/env.office.php
if (!defined('OFFICE_LAT')) {
    $officeConfigFile = __DIR__ . '/env.office.php';
    if (file_exists($officeConfigFile)) {
        require_once $officeConfigFile;
    } else {
        // Default values (0,0 = not configured, allows sign-in from anywhere)
        define('OFFICE_LAT', 0.0);
        define('OFFICE_LON', 0.0);
        define('OFFICE_RADIUS_M', 500);
    }
}

// User roles
if (!defined('ROLE_EMPLOYEE')) define('ROLE_EMPLOYEE', 'employee');
if (!defined('ROLE_ADMIN')) define('ROLE_ADMIN', 'admin');

// Voucher statuses
if (!defined('STATUS_PENDING')) define('STATUS_PENDING', 'pending');
if (!defined('STATUS_APPROVED')) define('STATUS_APPROVED', 'approved');
if (!defined('STATUS_REJECTED')) define('STATUS_REJECTED', 'rejected');
// Virtual/computed status used for display when voucher looks incomplete
if (!defined('STATUS_DRAFT')) define('STATUS_DRAFT', 'draft');
?>