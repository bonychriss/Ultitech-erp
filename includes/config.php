<?php
global $pdo, $control_pdo;
// Set timezone to East Africa Time (Tanzania)
date_default_timezone_set('Africa/Dar_es_Salaam');

if (PHP_VERSION_ID < 70100) {
    if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            http_response_code(503);
        }
        echo '<h1>PHP upgrade required</h1>';
        echo '<p>This application requires <strong>PHP 7.1 or newer</strong> (PHP 7.4+ recommended). ';
        echo 'Your server is running <strong>' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
        echo '<p>In StackCP: Websites &rarr; ultitech.io &rarr; PHP Version &rarr; select <strong>7.4</strong> or <strong>8.1</strong>, then save.</p>';
        exit;
    }
    $GLOBALS['ultitech_php_too_old'] = true;
}

// Simple environment awareness for dual setup (Local + InfinityFree Production)
// Order of precedence: includes/env.php (if present) > auto-detect > defaults

if (!function_exists('ultitech_is_local_dev_host')) {
    /**
     * True for localhost, *.local, and private LAN IPs (mobile testing on same Wi‑Fi).
     */
    function ultitech_is_local_dev_host(string $host): bool
    {
        $h = strtolower(trim($host));
        if ($h === '') {
            return false;
        }
        if (strpos($h, ':') !== false) {
            $parsedHost = parse_url('http://' . $h, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $h = $parsedHost;
            } else {
                $h = (string) preg_replace('/:\d+$/', '', $h);
            }
        }
        if (in_array($h, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        if (strlen($h) >= 6 && substr($h, -6) === '.local') {
            return true;
        }
        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (preg_match('/^127\./', $h)) {
                return true;
            }
            if (preg_match('/^10\./', $h)) {
                return true;
            }
            if (preg_match('/^192\.168\./', $h)) {
                return true;
            }
            if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $h)) {
                return true;
            }
        }
        return false;
    }
}

// Auto-detect environment and base path
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalHostEarly = ultitech_is_local_dev_host((string) $host);
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
// $APP_BASE_PATH = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

// Robust detection: Calculate path relative to document root based on THIS file's location
// This file is in /includes/, so we go up one level to find the app root.
$appRoot = str_replace('\\', '/', dirname(__DIR__)); 
$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');

if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $APP_BASE_PATH = rtrim(substr($appRoot, strlen($docRoot)), '/');
    // StackCP etc.: app files in public_html/ but site URLs are at domain root (not /public_html/...)
    if (!$isLocalHostEarly) {
        $appFolder = basename($appRoot);
        if (strcasecmp($appFolder, 'public_html') === 0 || strcasecmp($appFolder, 'public-html') === 0) {
            $APP_BASE_PATH = '';
        }
    }
} else {
    // Fallback for environments where DOCUMENT_ROOT is missing or unreliable (e.g. CLI, certain Proxies, XAMPP)
    if (strpos($appRoot, '/htdocs/') !== false) {
        $APP_BASE_PATH = '/' . ltrim(explode('/htdocs/', $appRoot)[1], '/');
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $APP_BASE_PATH = preg_replace('#/(admin|api|modules|assets|includes|stock|attendance|deliveries|dispatch|employee|erp|js|css|logs|storage|uploads|vouchers|todo|weekly_tasks).*$#i', '', $scriptDir);
    }
    $APP_BASE_PATH = rtrim((string)$APP_BASE_PATH, '/');
}

// Normalize slashes for Windows/Unix consistency
$APP_BASE_PATH = str_replace('\\', '/', $APP_BASE_PATH);

// Load environment variables with host-aware priority:
// - Production/non-local: prefer env.php
// - Localhost: prefer env.local.php overrides
$isLocalHost = ultitech_is_local_dev_host((string) $host);

if ($isLocalHost) {
    $__envCandidates = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/../env.php',
        __DIR__ . '/env.php',
    ];
} else {
    // Production: never load env.local.php (XAMPP paths like /public_html break live URLs)
    $__envCandidates = [
        __DIR__ . '/../env.php',
        __DIR__ . '/env.php',
    ];
}
// Load environment variables
$envDebugLog = ["__DIR__ is: " . __DIR__];

foreach ($__envCandidates as $__envPath) {
    $envDebugLog[] = "Checking: " . $__envPath;
    if (file_exists($__envPath)) {
        $envDebugLog[] = "FOUND: " . $__envPath;
        
        // Use a closure or include to load variables into the current scope
        // Sometimes require_once skips if already loaded in another scope
        include $__envPath; 
        
        if (isset($DB_USER)) {
            $envDebugLog[] = "SUCCESS: Variables populated. User is $DB_USER";
            break;
        } else {
            $envDebugLog[] = "WARNING: File found but DB_USER not found in scope.";
        }
    }
}

// Promote variable-based credentials to constants if present
if (isset($DB_HOST) && !defined('DB_HOST')) define('DB_HOST', $DB_HOST);
if (isset($DB_NAME) && !defined('DB_NAME')) define('DB_NAME', $DB_NAME);
if (isset($DB_USER) && !defined('DB_USER')) define('DB_USER', $DB_USER);
if (isset($DB_PASS) && !defined('DB_PASS')) define('DB_PASS', $DB_PASS);
if (isset($AI_ENCRYPTION_KEY) && !defined('AI_ENCRYPTION_KEY')) {
    define('AI_ENCRYPTION_KEY', (string) $AI_ENCRYPTION_KEY);
} elseif (!defined('AI_ENCRYPTION_KEY')) {
    define('AI_ENCRYPTION_KEY', '');
}
if (isset($SALES_DB_NAME) && trim((string) $SALES_DB_NAME) !== '' && !defined('SALES_DB_NAME')) {
    define('SALES_DB_NAME', trim((string) $SALES_DB_NAME));
}
if (isset($DATA_DB_NAME) && trim((string) $DATA_DB_NAME) !== '' && !defined('DATA_DB_NAME')) {
    define('DATA_DB_NAME', trim((string) $DATA_DB_NAME));
}
if (isset($ROADMASTER_DB_NAME) && trim((string) $ROADMASTER_DB_NAME) !== '') {
    $GLOBALS['ROADMASTER_DB_NAME'] = trim((string) $ROADMASTER_DB_NAME);
}
if (isset($ROADMASTER_DB_HOST) && trim((string) $ROADMASTER_DB_HOST) !== '') {
    $GLOBALS['ROADMASTER_DB_HOST'] = trim((string) $ROADMASTER_DB_HOST);
}

// Determine Application Environment
if (defined('APP_ENV')) {
    // Constant already defined
} elseif (isset($APP_ENV)) {
    define('APP_ENV', $APP_ENV);
} else {
    // Auto-detect based on host if not set
    if (ultitech_is_local_dev_host((string) $host)) {
        define('APP_ENV', 'development');
    } else {
        define('APP_ENV', 'production');
    }
}

// Configure Error Reporting based on Environment
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', 1);
}

// Base Path Configuration
if (isset($SITE_URL) && !empty($SITE_URL)) {
    $parsedUrl = parse_url($SITE_URL);
    $sitePath = rtrim((string) ($parsedUrl['path'] ?? ''), '/');
    if ($sitePath !== '' && $sitePath !== '/') {
        $APP_BASE_PATH = $sitePath;
    } elseif (!$isLocalHost) {
        $APP_BASE_PATH = '';
    }
}
// Explicit override from env.php (set $APP_BASE_PATH = '' on production)
if (isset($APP_BASE_PATH) && is_string($APP_BASE_PATH)) {
    $APP_BASE_PATH = rtrim(str_replace('\\', '/', $APP_BASE_PATH), '/');
}
if (!$isLocalHost) {
    $baseNorm = ltrim((string) $APP_BASE_PATH, '/');
    if (strcasecmp($baseNorm, 'public_html') === 0 || strcasecmp($baseNorm, 'public-html') === 0) {
        $APP_BASE_PATH = '';
    }
}
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', $APP_BASE_PATH);
}

// Create database connection (with host fallback for shared hosting variants)
try {
    $configuredHost = defined('DB_HOST') ? DB_HOST : 'localhost';
    $useName = defined('DB_NAME') ? DB_NAME : '';
    $useUser = defined('DB_USER') ? DB_USER : 'root';
    $usePass = defined('DB_PASS') ? DB_PASS : '';

    $hostCandidates = array_values(array_unique(array_filter([
        (string) $configuredHost,
        'localhost',
        '127.0.0.1',
    ], static function ($h) { return trim((string) $h) !== ''; })));

    $pdo = null;
    $connectErrors = [];
    $useHost = $configuredHost;

    foreach ($hostCandidates as $hostCandidate) {
        try {
            $dsn = "mysql:host=" . $hostCandidate . ";dbname=" . $useName . ";charset=utf8mb4";
            $tmpPdo = new PDO($dsn, $useUser, $usePass, [
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $tmpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tmpPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $tmpPdo->exec("SET time_zone = '+03:00'");
            $pdo = $tmpPdo;
            $useHost = $hostCandidate;
            break;
        } catch (PDOException $inner) {
            $connectErrors[] = $hostCandidate . ': ' . $inner->getMessage();
        }
    }

    if (!$pdo) {
        throw new PDOException(implode(' | ', $connectErrors));
    }

    // Store as control connection for global metadata (companies, users)
    $control_pdo = $pdo;
    $GLOBALS['control_pdo'] = $pdo;
} catch(PDOException $e) {
    $msg = "<strong>[V26-FIX]</strong> Service unavailable.<br>";
    $msg .= "Error: " . $e->getMessage() . "<br>";
    $msg .= "Attempted Hosts: " . htmlspecialchars(implode(', ', $hostCandidates ?? []), ENT_QUOTES, 'UTF-8') . " | User=" . (defined('DB_USER') ? DB_USER : 'fallback') . "<br>";
    $msg .= "<strong>Environment Trace:</strong><br>" . implode("<br>", $envDebugLog);
    die($msg);
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    // Set session cookie parameters before starting the session
    $sessionCookiePath = '/';
    if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '' && APP_BASE_PATH !== '/') {
        $sessionCookiePath = rtrim((string) APP_BASE_PATH, '/') . '/';
    }
    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $sessionCookiePath,
            'domain'   => '', // let PHP decide
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        @ini_set('session.cookie_httponly', '1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { @ini_set('session.cookie_secure', '1'); }
    }
    if (!defined('PVS_SESSION_NAMED')) {
        @session_name('PVSSESSID');
        define('PVS_SESSION_NAMED', true);
    }
    session_start();
}

/**
 * Multi-Database Tenant Switching
 * If a company is identified, re-connect $pdo to the tenant database.
 */
require_once __DIR__ . '/error_page_helpers.php';
require_once __DIR__ . '/functions.php';

if (isset($control_pdo)) {
    $cid = null;
    $slug = null;
    $slugWasRequested = false;
    $diagScriptNames = [
        'debug_system_full.php', 'debug_create_voucher.php', 'debug_voucher_applicant.php', 'hc.php', 'ping.php', 'debug_online.php',
        'debug_db_connections.php', 'debug_login.php', 'debug_todo_index.php',
    ];
    $currentScriptName = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $isDiagScript = in_array($currentScriptName, $diagScriptNames, true);

    if ($isDiagScript) {
        // debug_create_voucher.php / debug_voucher_applicant.php need company_slug to probe the tenant DB (not StackCP path noise).
        $dcvKeepSlug = in_array($currentScriptName, ['debug_create_voucher.php', 'debug_voucher_applicant.php'], true)
            && !empty($_GET['company_slug'])
            && isset($_GET['key']);
        if (!$dcvKeepSlug) {
            unset($_GET['company_slug']);
        }
    } else {
        $slug = $_GET['company_slug'] ?? null;

        // Detect company slug from URL path too (e.g. /roadmaster/admin/all-vouchers.php)
        if (!$slug) {
            $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $basePath = rtrim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
            if ($basePath !== '' && strpos($uriPath, $basePath) === 0) {
                $uriPath = (string) substr($uriPath, strlen($basePath));
            }
            $uriPath = trim($uriPath, '/');
            if (preg_match('#^home/sites/.+/public_html(?:/(.*))?$#i', $uriPath, $stackMatch)) {
                $uriPath = trim((string) ($stackMatch[1] ?? ''), '/');
            }
            if ($uriPath !== '') {
                $segments = explode('/', $uriPath);
                $candidateSlug = strtolower(trim((string) ($segments[0] ?? '')));
                if ($candidateSlug !== '' && strpos($candidateSlug, '.') === false && preg_match('/^[a-z0-9][a-z0-9-]*$/', $candidateSlug)) {
                    $reserved = [
                        'admin', 'api', 'assets', 'attendance', 'company', 'css', 'deliveries', 'dispatch',
                        'employee', 'erp', 'home', 'includes', 'js', 'logs', 'modules', 'public_html', 'public-html',
                        'sites', 'stock', 'storage', 'uploads', 'vouchers', 'logout.php', 'login.php', 'select-module.php',
                        'index.php', 'my-account.php', 'debug_login.php', 'debug_db_connections.php', 'debug_online.php',
                        'debug_system_full.php', 'debug_create_voucher.php', 'debug_todo_index.php', 'hc.php', 'ping.php',
                        'store-management-system', 'reports', 'logistics', 'crm', 'accounting', 'banking',
                        'petty-cash', 'replenishments', 'replenishment', 'categories', 'expenses', 'sales', 'finance',
                        'balances', 'letters', 'todo', 'view-voucher-ui',
                    ];
                    if (!in_array($candidateSlug, $reserved, true)) {
                        $slug = $candidateSlug;
                        $_GET['company_slug'] = $slug;
                    }
                }
            }
        }
        $slugWasRequested = !empty($slug);
    }

    if ($slug) {
        $st = $control_pdo->prepare("SELECT id FROM companies WHERE company_slug = ?");
        $st->execute([$slug]);
        $cid = (int)$st->fetchColumn();
    }
    if (!$cid && !$slugWasRequested && !empty($_SESSION['company_id'])) {
        $cid = (int)$_SESSION['company_id'];
    }
    if (!$cid && !$slugWasRequested && !empty($_SESSION['user_id'])) {
        try {
            $stUser = $control_pdo->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
            $stUser->execute([(int) $_SESSION['user_id']]);
            $cid = (int) ($stUser->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $cid = 0;
        }
    }

    // For slug routes, never fall back to another company context.
    if ($slugWasRequested && $cid <= 0) {
        renderCompanyNotFoundPage('Company not found.');
    }
    
    if ($cid) {
        $_SESSION['company_id'] = (int) $cid;
        try {
            $stCompany = $control_pdo->prepare("SELECT company_slug, company_name FROM companies WHERE id = ? LIMIT 1");
            $stCompany->execute([$cid]);
            $companyRow = $stCompany->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($companyRow) {
                $_SESSION['company_slug'] = (string) ($companyRow['company_slug'] ?? ($_SESSION['company_slug'] ?? ''));
                $_SESSION['company_name'] = (string) ($companyRow['company_name'] ?? ($_SESSION['company_name'] ?? ''));
            }
        } catch (Throwable $e) {
            // Keep tenant switch resilient; session slug/name sync is best-effort.
        }
        try {
            $tenantDbName = '';
            $tenantDbHost = '';
            $tenantDbUser = '';
            $tenantDbPass = null;
            if (function_exists('columnExists') && columnExists('companies', 'db_host', $control_pdo)) {
                $hasDbUser = function_exists('columnExists') && columnExists('companies', 'db_user', $control_pdo);
                $hasDbPass = function_exists('columnExists') && columnExists('companies', 'db_pass', $control_pdo);
                $selectCols = 'db_name, db_host' . ($hasDbUser ? ', db_user' : '') . ($hasDbPass ? ', db_pass' : '');
                $stmt = $control_pdo->prepare("SELECT " . $selectCols . " FROM companies WHERE id = ?");
                $stmt->execute([$cid]);
                $tenantRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
                $tenantDbName = trim((string) ($tenantRow['db_name'] ?? ''));
                $tenantDbHost = trim((string) ($tenantRow['db_host'] ?? ''));
                $tenantDbUser = trim((string) ($tenantRow['db_user'] ?? ''));
                if (array_key_exists('db_pass', $tenantRow)) {
                    $rawPass = (string) ($tenantRow['db_pass'] ?? '');
                    $tenantDbPass = trim($rawPass) !== '' ? $rawPass : null;
                }
                if (function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants()) {
                    $tenantDbUser = '';
                    $tenantDbPass = null;
                }
            } else {
                $stmt = $control_pdo->prepare("SELECT db_name FROM companies WHERE id = ?");
                $stmt->execute([$cid]);
                $tenantDbName = trim((string) ($stmt->fetchColumn() ?: ''));
            }

            // Production: control DB is empty; real data is in DATA_DB_NAME / SALES_DB_NAME (StackCP scan)
            if (function_exists('resolveEffectiveTenantDbConnection')) {
                $effectiveTenant = resolveEffectiveTenantDbConnection($tenantDbName, $tenantDbHost, $tenantDbUser, $tenantDbPass);
                $tenantDbName = $effectiveTenant['db_name'];
                $tenantDbHost = $effectiveTenant['host'];
                $tenantDbUser = $effectiveTenant['user'];
                $tenantDbPass = $effectiveTenant['pass'];
            } else {
                $dataDb = defined('DATA_DB_NAME') ? trim((string) DATA_DB_NAME) : '';
                if ($dataDb === '' && defined('SALES_DB_NAME')) {
                    $dataDb = trim((string) SALES_DB_NAME);
                }
                if ($dataDb !== '' && $dataDb !== $useName) {
                    $useDataDb = ($tenantDbName === '' || $tenantDbName === $useName);
                    if (!$useDataDb && $tenantDbName !== $dataDb && function_exists('connectToTenantDatabase')) {
                        $probePdo = connectToTenantDatabase($tenantDbName, $tenantDbHost, $tenantDbUser, $tenantDbPass);
                        if ($probePdo instanceof PDO) {
                            try {
                                $pv = $probePdo->query('SELECT COUNT(*) FROM payment_vouchers');
                                $useDataDb = ((int) ($pv ? $pv->fetchColumn() : 0)) === 0;
                            } catch (Throwable $e) {
                                $useDataDb = true;
                            }
                        } else {
                            $useDataDb = true;
                        }
                    }
                    if ($useDataDb) {
                        $tenantDbName = $dataDb;
                        $tenantDbHost = defined('DB_HOST') ? DB_HOST : $tenantDbHost;
                    }
                }
            }

            if ($tenantDbName !== '' && $tenantDbName !== $useName) {
                $tenantPdo = function_exists('connectToTenantDatabase')
                    ? connectToTenantDatabase($tenantDbName, $tenantDbHost, $tenantDbUser, $tenantDbPass)
                    : null;
                if ($tenantPdo instanceof PDO) {
                    $pdo = $tenantPdo;
                    $GLOBALS['pdo'] = $tenantPdo;
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    if (!defined('IS_TENANT_DB')) define('IS_TENANT_DB', true);
                    if (!defined('DATA_DB_NAME') && $tenantDbName !== '' && $tenantDbName !== $useName) {
                        define('DATA_DB_NAME', $tenantDbName);
                    }
                    error_log("SUCCESS: Switched to tenant DB: $tenantDbName for CID: $cid");

                    // Keep tenant PDO for vouchers; sales module uses sales_pdo() to find sales_orders DB
                    try {
                        $tchk = $pdo->query("SHOW TABLES LIKE 'sales_orders'");
                        if (!($tchk && $tchk->fetch(PDO::FETCH_NUM))) {
                            $GLOBALS['tenant_pdo'] = $pdo;
                        }
                    } catch (Throwable $e) {
                        $GLOBALS['tenant_pdo'] = $pdo;
                    }
                } else {
                    error_log("Tenant DB Connection Failed for Company ID $cid (db=$tenantDbName host=$tenantDbHost)");
                    $fallbackOk = false;
                    if ($dataDb !== '' && function_exists('connectToTenantDatabase')) {
                        $fallbackPdo = connectToTenantDatabase(
                            $dataDb,
                            defined('DB_HOST') ? DB_HOST : $tenantDbHost,
                            $tenantDbUser,
                            $tenantDbPass
                        );
                        if ($fallbackPdo instanceof PDO) {
                            $pdo = $fallbackPdo;
                            $GLOBALS['pdo'] = $fallbackPdo;
                            if (!defined('IS_TENANT_DB')) {
                                define('IS_TENANT_DB', true);
                            }
                            $fallbackOk = true;
                            error_log("INFO: Tenant connect failed; using DATA_DB_NAME $dataDb for CID: $cid");
                        }
                    }
                    if (!$fallbackOk) {
                        $pdo = $control_pdo;
                        $GLOBALS['pdo'] = $control_pdo;
                    }
                }
            } else {
                error_log("INFO: No tenant switch. CID: $cid, TenantDB: $tenantDbName, MainDB: $useName");
            }
        } catch(PDOException $e) {
            // Log error and fallback to control_pdo if tenant connection fails
            error_log("Tenant DB Connection Failed for Company ID $cid: " . $e->getMessage());
            $pdo = $control_pdo;
            $GLOBALS['pdo'] = $control_pdo;
            if (!empty($dataDb) && function_exists('connectToTenantDatabase')) {
                $fallbackPdo = connectToTenantDatabase(
                    $dataDb,
                    defined('DB_HOST') ? DB_HOST : '',
                    $tenantDbUser ?? '',
                    $tenantDbPass ?? null
                );
                if ($fallbackPdo instanceof PDO) {
                    $pdo = $fallbackPdo;
                    $GLOBALS['pdo'] = $fallbackPdo;
                }
            }
        }
    }
}

if (function_exists('erp_bootstrap_active_pdo')) {
    erp_bootstrap_active_pdo();
}

// Populate login index from tenant DBs when empty (no manual sync page visit required).
if (function_exists('maybeAutoSyncUserCompanyIndex')) {
    maybeAutoSyncUserCompanyIndex(false);
}

// Sales DB discovery runs in modules/sales/functions.php (sales_find_database_pdo).

// Enable verbose errors when debug parameter is set
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @error_reporting(E_ALL);
}

// Company information
if (!defined('COMPANY_NAME')) define('COMPANY_NAME', '');
if (!defined('COMPANY_LOGO_PATH')) define('COMPANY_LOGO_PATH', 'assets/images/logo.png');
if (!defined('COMPANY_ADDRESS')) define('COMPANY_ADDRESS', '');
if (!defined('COMPANY_PHONE')) define('COMPANY_PHONE', '');
if (!defined('COMPANY_EMAIL')) define('COMPANY_EMAIL', '');

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
if (!defined('ROLE_COMPANY_ADMIN')) define('ROLE_COMPANY_ADMIN', 'company_admin');

// Voucher statuses
if (!defined('STATUS_CONFIRMING')) define('STATUS_CONFIRMING', 'confirming');
if (!defined('STATUS_PENDING')) define('STATUS_PENDING', 'pending');
if (!defined('STATUS_APPROVED')) define('STATUS_APPROVED', 'approved');
if (!defined('STATUS_REJECTED')) define('STATUS_REJECTED', 'rejected');

// Work hours configuration
if (!defined('WORK_START_TIME')) define('WORK_START_TIME', '08:30:00');
if (!defined('WORK_END_TIME')) define('WORK_END_TIME', '16:00:00');
// Virtual/computed status used for display when voucher looks incomplete
if (!defined('STATUS_DRAFT')) define('STATUS_DRAFT', 'draft');

// Apply system font on all HTML responses (after tenant DB is active).
if (function_exists('erp_bootstrap_system_font_output_buffer')) {
    erp_bootstrap_system_font_output_buffer();
}