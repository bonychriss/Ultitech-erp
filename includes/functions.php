<?php
/**
 * Safe HTML escape helper
 */
if (!function_exists('h')) {
    function h($v): string {
        if ($v === null) return '';
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// PHP 7 compatibility helpers (native in PHP 8+)
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
if (!function_exists('pdoInstanceToken')) {
    /** Stable per-request cache key for a PDO instance (PHP 7.0: spl_object_id unavailable). */
    function pdoInstanceToken($object)
    {
        if (!is_object($object)) {
            return (string) $object;
        }
        if (function_exists('spl_object_id')) {
            return (string) spl_object_id($object);
        }
        return spl_object_hash($object);
    }
}

/**
 * Clean user input to prevent XSS (Common helper)
 */
if (!function_exists('clean_input')) {
    function clean_input($data) {
        $data = trim((string)$data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}
if (!function_exists('tableExists')) {
    function tableExists(string $tableName, $explicitPdo = null): bool
    {
        global $pdo, $control_pdo;
        $usePdo = $explicitPdo ?? ($pdo ?? $control_pdo);
        try {
            $stmt = $usePdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$tableName]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('columnExists')) {
    function columnExists(string $tableName, string $columnName, $explicitPdo = null): bool
    {
        global $pdo, $control_pdo;
        $usePdo = $explicitPdo ?? ($pdo ?? $control_pdo);
        try {
            $stmt = $usePdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$tableName, $columnName]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('useGlobalDbCredentialsForTenants')) {
    function useGlobalDbCredentialsForTenants()
    {
        if (defined('APP_ENV') && APP_ENV === 'development') {
            return true;
        }
        if (!defined('DB_HOST')) {
            return false;
        }
        $host = strtolower(trim((string) DB_HOST));
        return in_array($host, array('localhost', '127.0.0.1'), true);
    }
}

if (!function_exists('getTenantDbHostCandidates')) {
    function getTenantDbHostCandidates($companyHost = null)
    {
        $hosts = array();
        $companyHost = trim((string) $companyHost);
        if ($companyHost !== '') {
            $hosts[] = $companyHost;
        }
        if (defined('DB_HOST') && DB_HOST !== '') {
            $hosts[] = (string) DB_HOST;
        }
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        return array_values(array_unique(array_filter($hosts, function ($h) {
            return trim((string) $h) !== '';
        })));
    }
}

if (!function_exists('resolveEffectiveTenantDbConnection')) {
    /**
     * Resolve which tenant database to use (must match includes/config.php session switch).
     * On production, companies.db_name may be empty while real users live in DATA_DB_NAME.
     *
     * @return array{db_name:string,host:string,user:string,pass:?string}
     */
    function resolveEffectiveTenantDbConnection($tenantDbName, $tenantDbHost = '', $tenantDbUser = '', $tenantDbPass = null)
    {
        $tenantDbName = trim((string) $tenantDbName);
        $tenantDbHost = trim((string) $tenantDbHost);
        $mainDbName = defined('DB_NAME') ? trim((string) DB_NAME) : '';

        $dataDb = defined('DATA_DB_NAME') ? trim((string) DATA_DB_NAME) : '';
        if ($dataDb === '' && defined('SALES_DB_NAME')) {
            $dataDb = trim((string) SALES_DB_NAME);
        }

        $resolved = array(
            'db_name' => $tenantDbName,
            'host' => $tenantDbHost,
            'user' => trim((string) $tenantDbUser),
            'pass' => $tenantDbPass,
        );

        if ($dataDb === '' || $dataDb === $mainDbName) {
            return $resolved;
        }

        // Dedicated tenant DBs (e.g. Roadmaster) must never be remapped to DATA_DB_NAME
        // just because payment_vouchers is empty — that steals Ultimate's users/data.
        $roadmasterDb = isset($GLOBALS['ROADMASTER_DB_NAME']) ? trim((string) $GLOBALS['ROADMASTER_DB_NAME']) : '';
        $isRoadmasterTenant = ($tenantDbName !== ''
            && $tenantDbName !== $mainDbName
            && $tenantDbName !== $dataDb
            && (
                ($roadmasterDb !== '' && strcasecmp($tenantDbName, $roadmasterDb) === 0)
                || (bool) preg_match('/^roadmaster(_|-|$)/i', $tenantDbName)
            ));

        $useDataDb = ($tenantDbName === '' || $tenantDbName === $mainDbName);
        if (!$useDataDb && $tenantDbName !== $dataDb && function_exists('connectToTenantDatabase')) {
            $probePdo = connectToTenantDatabase($tenantDbName, $tenantDbHost, $tenantDbUser, $tenantDbPass);
            if ($probePdo instanceof PDO) {
                // Reachable distinct tenant DB: keep it (even with zero vouchers).
                $useDataDb = false;
            } elseif ($isRoadmasterTenant) {
                // Prefer local host override rather than falling through to another company DB.
                $localHost = '';
                if (isset($GLOBALS['ROADMASTER_DB_HOST']) && trim((string) $GLOBALS['ROADMASTER_DB_HOST']) !== '') {
                    $localHost = trim((string) $GLOBALS['ROADMASTER_DB_HOST']);
                } elseif (function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants()) {
                    $localHost = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
                }
                if ($localHost !== '' && strcasecmp($localHost, $tenantDbHost) !== 0) {
                    $probeLocal = connectToTenantDatabase($tenantDbName, $localHost, null, null);
                    if ($probeLocal instanceof PDO) {
                        $resolved['host'] = $localHost;
                        $resolved['user'] = '';
                        $resolved['pass'] = null;
                        $useDataDb = false;
                    } else {
                        $useDataDb = false;
                    }
                } else {
                    $useDataDb = false;
                }
            } else {
                $useDataDb = true;
            }
        }

        if ($useDataDb) {
            $resolved['db_name'] = $dataDb;
            $resolved['host'] = defined('DB_HOST') ? (string) DB_HOST : $tenantDbHost;
        } elseif (function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants()) {
            // Local XAMPP: skip remote StackCP hosts/credentials once the tenant DB name is known.
            if ($resolved['host'] === '' || !preg_match('/^(localhost|127\.0\.0\.1)$/i', $resolved['host'])) {
                $resolved['host'] = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
            }
            $resolved['user'] = '';
            $resolved['pass'] = null;
        }

        return $resolved;
    }
}

if (!function_exists('connectToTenantDatabase')) {
    /**
     * Connect to a tenant database, trying company-specific host then global DB_HOST.
     *
     * @return PDO|null
     */
    function connectToTenantDatabase($dbName, $companyHost = null, $companyUser = null, $companyPass = null)
    {
        $dbName = trim((string) $dbName);
        if ($dbName === '') {
            return null;
        }
        if (function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants()) {
            $companyUser = null;
            $companyPass = null;
        }
        $useUser = trim((string) $companyUser) !== '' ? trim((string) $companyUser) : (defined('DB_USER') ? DB_USER : 'root');
        $usePass = (trim((string) $companyPass) !== '') ? (string) $companyPass : (defined('DB_PASS') ? DB_PASS : '');
        foreach (getTenantDbHostCandidates($companyHost) as $host) {
            try {
                $dsn = 'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4';
                $tenantPdo = new PDO($dsn, $useUser, $usePass, array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ));
                $tenantPdo->exec("SET time_zone = '+03:00'");
                return $tenantPdo;
            } catch (Throwable $e) {
                error_log('connectToTenantDatabase(' . $dbName . '@' . $host . ' user=' . $useUser . '): ' . $e->getMessage());
            }
        }
        return null;
    }
}

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fatal fallback if neither exists
    die("<strong>[V26-FATAL]</strong> Critical dependency missing: config.php not found in root or includes/.");
}

// Load centralized multi-tenant upload helper class
require_once __DIR__ . '/UploadHelper.php';

/**
 * Redirect helper
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        } else {
            echo '<script>window.location.href="' . $url . '";</script>';
            exit;
        }
    }
}

/**
 * Session flash (set with message + type, or display once and clear).
 * Compatible with pages using: flash('success', 'text'); … flash('success');
 */
if (!function_exists('flash')) {
    function flash(string $name, string $text = '', string $type = 'success')
    {
        if ($text !== '') {
            if (func_num_args() < 3 && in_array($name, ['error', 'danger', 'warning', 'info', 'success'], true)) {
                $type = $name === 'error' ? 'danger' : $name;
            }
            $_SESSION[$name] = $text;
            $_SESSION[$name . '_type'] = $type;
            return;
        }
        if (isset($_SESSION[$name])) {
            $t = $_SESSION[$name . '_type'] ?? 'success';
            $class = ($t === 'error') ? 'danger' : $t;
            if (!in_array($class, ['success', 'danger', 'warning', 'info', 'primary', 'secondary'], true)) {
                $class = 'info';
            }
            echo '<div class="alert alert-' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' alert-dismissible fade show" role="alert">'
                . htmlspecialchars((string) $_SESSION[$name], ENT_QUOTES, 'UTF-8')
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            unset($_SESSION[$name], $_SESSION[$name . '_type']);
        }
    }
}

// Fallback for APP_BASE_PATH if not defined in config.php (legacy support)
if (!defined('APP_BASE_PATH')) {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $path = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
    define('APP_BASE_PATH', str_replace('\\', '/', $path));
}

// Build a full URL path using APP_BASE_PATH.
// Example: app_url('/employee/dashboard.php') â†’ '/payment-voucher-system/employee/dashboard.php' (local) or '/employee/dashboard.php' (prod)
if (!function_exists('app_url')) {
    function app_url($path = '/')
    {
        $base = '/' . trim((string)APP_BASE_PATH, '/');
        if ($base === '/') $base = '';
        $p = '/' . ltrim((string)$path, '/');
        return $base . $p;
    }
}

/**
 * Friendly wrong-route page when a company slug cannot be resolved.
 * Exits the request.
 */
if (!function_exists('renderCompanyNotFoundPage')) {
    require_once __DIR__ . '/error_page_helpers.php';
}

/**
 * Normalize a stored filesystem/URL path to a web-root-relative path (no leading slash).
 */
if (!function_exists('normalizeMediaPathForApp')) {
    function normalizeMediaPathForApp($rawPath): string
    {
        $raw = trim((string) $rawPath);
        if ($raw === '') {
            return '';
        }
        $raw = str_replace('\\', '/', $raw);
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        if (preg_match('#^[A-Za-z]:/#', $raw)) {
            $marker = '/public_html/';
            $pos = stripos($raw, $marker);
            if ($pos !== false) {
                $raw = substr($raw, $pos + strlen($marker));
            }
        }
        $raw = ltrim($raw, '/');
        if (stripos($raw, 'public_html/') === 0) {
            $raw = substr($raw, strlen('public_html/'));
        }
        if (strpos($raw, './') === 0) {
            $raw = substr($raw, 2);
        }
        return ltrim((string) $raw, '/');
    }
}

/**
 * Public URL for an uploaded asset; empty when file is missing (avoids broken &lt;img&gt; on tenant routes).
 */
if (!function_exists('mediaUrlFromPath')) {
    function mediaUrlFromPath($rawPath, $requireExistingFile = true): string
    {
        $normalized = normalizeMediaPathForApp($rawPath);
        if ($normalized === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $normalized)) {
            return $normalized;
        }
        if ($requireExistingFile) {
            $fsPath = dirname(__DIR__) . '/' . str_replace('\\', '/', $normalized);
            if (!is_file($fsPath)) {
                return '';
            }
        }
        return function_exists('app_url') ? app_url('/' . $normalized) : '/' . $normalized;
    }
}

/** Lowercase person name key for matching DB names to approval rows. */
if (!function_exists('normalizePersonNameKey')) {
    function normalizePersonNameKey($name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    }
}


function defaultCompanyId()
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    static $cached = null;
    if ($cached !== null) {
        return $cached > 0 ? $cached : null;
    }
    try {
        if (!tableExists('companies')) {
            $cached = 0;
            return null;
        }
        $stmt = $usePdo->query("SELECT id FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        $val = (int) ($stmt->fetchColumn() ?: 0);
        $cached = $val;
        return $val > 0 ? $val : null;
    } catch (Throwable $e) {
        $cached = 0;
        return null;
    }
}

function resolveUserCompanyId(int $userId)
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    if ($userId <= 0 || !tableExists('users') || !columnExists('users', 'company_id')) {
        return defaultCompanyId();
    }
    try {
        // Users might be in the control DB or tenant DB depending on architecture
        // For multi-DB we assume a global users table for cross-tenant logins
        $stmt = $usePdo->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $cid = (int) ($stmt->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    } catch (Throwable $e) {
    }
    return defaultCompanyId();
}

function currentCompanyId()
{
    if (!empty($_SESSION['company_id'])) {
        return (int) $_SESSION['company_id'];
    }

    // Fallback: Resolve company from requested URL slug if session is empty (multi-tenant routing)
    if (function_exists('getRequestedCompanySlug')) {
        $slug = getRequestedCompanySlug();
        if ($slug !== '') {
            $company = findCompanyBySlug($slug);
            if ($company && !empty($company['id'])) {
                return (int) $company['id'];
            }
        }
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $resolved = resolveUserCompanyId($uid);
    if (!empty($resolved)) {
        $_SESSION['company_id'] = (int) $resolved;
        return (int) $resolved;
    }
    return null;
}

/**
 * Detect if the current session or path is Roadmaster specific
 */
function isRoadmaster(): bool
{
    // 1. Path check (if in /roadmaster/ subdirectory)
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/roadmaster/') !== false) {
        return true;
    }

    // 2. Database/Company check
    $cid = currentCompanyId();
    if ($cid === 2) { // ROADMASTER SPARES (Slug: roadmaster)
        return true;
    }

    // 3. Fallback check for session flags if any
    if (!empty($_SESSION['company_slug']) && $_SESSION['company_slug'] === 'roadmaster') {
        return true;
    }

    return false;
}

/**
 * Detect if the current session or path is Ultimate General Trading.
 */
function isUltimate(): bool
{
    if (isRoadmaster()) {
        return false;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/ultimate/') !== false) {
        return true;
    }

    $cid = currentCompanyId();
    if ($cid === 1) {
        return true;
    }

    if (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate') {
        return true;
    }

    return false;
}

/**
 * Quotations list (create.php list mode) uses the Roadmaster-style shell: hero, KPI cards,
 * toolbar, footer pagination. Ultimate matches via /ultimate/ URL or company_slug ultimate.
 * Truck/spare Type column and Swal "create type" remain isRoadmaster() only.
 */
function quotationsListUsesRoadmasterShell(): bool
{
    if (isRoadmaster()) {
        return true;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/ultimate/') !== false) {
        return true;
    }
    if (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate') {
        return true;
    }

    return false;
}

function invoicesListUsesRoadmasterShell(): bool
{
    if (isRoadmaster()) {
        return true;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/ultimate/') !== false) {
        return true;
    }
    if (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate') {
        return true;
    }

    return false;
}

/**
 * Sales orders list (orders/index.php) uses the same premium shell as invoices/quotations for Roadmaster and Ultimate.
 */
function ordersListUsesRoadmasterShell(): bool
{
    if (isRoadmaster()) {
        return true;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/ultimate/') !== false) {
        return true;
    }
    if (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate') {
        return true;
    }

    return false;
}


function getCompanyInfo($companyId = null): array
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $id = $companyId ?? currentCompanyId();
    if (!$id) {
        return [
            'company_name' => '',
            'logo' => '',
            'theme_color' => '#714B67',
            'industry_type' => 'trading'
        ];
    }
    
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    
    try {
        $stmt = $usePdo->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$id]);
        $info = $stmt->fetch();
        if ($info) {
            $cache[$id] = $info;
            return $info;
        }
    } catch (Exception $e) {}
    
    return [
        'company_name' => '',
        'logo' => '',
        'theme_color' => '#714B67',
        'industry_type' => 'trading'
    ];
}

function getCompanyType($companyId = null): string
{
    $info = getCompanyInfo($companyId);
    return $info['industry_type'] ?? 'general';
}

function isIndustry(string $type): bool
{
    return getCompanyType() === $type;
}

function slugifyCompanyName(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'company';
    }
    return $slug;
}

/**
 * Legacy tenant DBs use a single-row company_settings table (company_name, company_logo, …).
 * Multi-company admin expects key/value rows (company_id, setting_key, setting_value).
 * Renames the legacy table to company_profile and creates the KV table when needed.
 */
function ensureCompanySettingsKeyValueSchema($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!$usePdo instanceof PDO) {
        return;
    }

    static $completed = [];
    $token = pdoInstanceToken($usePdo);
    if (isset($completed[$token])) {
        return;
    }
    $completed[$token] = true;

    if (!tableExists('company_settings', $usePdo)) {
        return;
    }
    if (columnExists('company_settings', 'setting_key', $usePdo)) {
        return;
    }

    $isLegacyWideTable = columnExists('company_settings', 'company_name', $usePdo)
        || columnExists('company_settings', 'company_logo', $usePdo);
    if (!$isLegacyWideTable) {
        return;
    }

    try {
        $legacyTable = 'company_profile';
        if (tableExists($legacyTable, $usePdo)) {
            $legacyTable = 'company_settings_legacy_' . date('YmdHis');
        }
        $usePdo->exec('RENAME TABLE company_settings TO `' . str_replace('`', '``', $legacyTable) . '`');

        $usePdo->exec("
            CREATE TABLE company_settings (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_settings_key (company_id, setting_key),
                KEY idx_company_settings_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (tableExists('companies', $usePdo)) {
            try {
                $usePdo->exec("
                    ALTER TABLE company_settings
                    ADD CONSTRAINT fk_company_settings_company
                    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
                ");
            } catch (Throwable $e) {
                // FK may already exist or companies engine mismatch.
            }
        }

        $legacyRow = $usePdo->query('SELECT * FROM `' . str_replace('`', '``', $legacyTable) . '` ORDER BY id ASC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (!$legacyRow) {
            return;
        }

        $addressParts = array_filter([
            trim((string) ($legacyRow['address'] ?? '')),
            trim((string) ($legacyRow['city'] ?? '')),
            trim((string) ($legacyRow['country'] ?? '')),
        ], static function ($part) { return $part !== ''; });
        $kvSeed = [
            'company_logo' => (string) ($legacyRow['company_logo'] ?? ''),
            'company_address' => implode(', ', $addressParts),
            'company_phone' => (string) ($legacyRow['phone'] ?? ''),
            'company_email' => (string) ($legacyRow['email'] ?? ''),
            'country' => (string) ($legacyRow['country'] ?? ''),
            'vat_rate' => (string) ($legacyRow['vat_number'] ?? ''),
        ];

        $companyIds = [];
        if (tableExists('companies', $usePdo)) {
            $companyIds = array_map('intval', $usePdo->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
        if ($companyIds === []) {
            $companyIds = [(int) ($legacyRow['id'] ?? 1)];
        }

        $insert = $usePdo->prepare(
            'INSERT INTO company_settings (company_id, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach ($companyIds as $companyId) {
            if ($companyId <= 0) {
                continue;
            }
            foreach ($kvSeed as $settingKey => $settingValue) {
                $settingValue = trim((string) $settingValue);
                if ($settingValue === '') {
                    continue;
                }
                $insert->execute([$companyId, $settingKey, $settingValue]);
            }
        }
    } catch (Throwable $e) {
        error_log('ensureCompanySettingsKeyValueSchema: ' . $e->getMessage());
    }
}

/** @return array<string, string> */
function fetchCompanySettingsMap(PDO $pdo, int $companyId): array
{
    ensureCompanySettingsKeyValueSchema($pdo);
    if ($companyId <= 0 || !tableExists('company_settings', $pdo) || !columnExists('company_settings', 'setting_key', $pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM company_settings WHERE company_id = ?');
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function saveCompanySettingValue(PDO $pdo, int $companyId, string $settingKey, string $settingValue): bool
{
    ensureCompanySettingsKeyValueSchema($pdo);
    if ($companyId <= 0 || $settingKey === '' || !columnExists('company_settings', 'setting_key', $pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO company_settings (company_id, setting_key, setting_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        return $stmt->execute([$companyId, $settingKey, $settingValue]);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, array<string, mixed>> */
function fetchDocumentSequencesMap(PDO $pdo, int $companyId): array
{
    ensureMultiCompanyControlSchema();
    $seqPdo = documentSequencesPdo($pdo);
    if ($companyId <= 0 || !($seqPdo instanceof PDO)) {
        return [];
    }
    try {
        $stmt = $seqPdo->prepare('SELECT * FROM document_sequences WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) ($row['document_type'] ?? '')] = $row;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * PDO that holds document_sequences (control DB first, then tenant).
 */
function documentSequencesPdo($fallbackPdo = null): ?PDO
{
    global $pdo, $control_pdo;
    $candidates = [];
    if ($control_pdo instanceof PDO) {
        $candidates[] = $control_pdo;
    }
    if ($fallbackPdo instanceof PDO) {
        $candidates[] = $fallbackPdo;
    } elseif ($pdo instanceof PDO) {
        $candidates[] = $pdo;
    }
    foreach ($candidates as $conn) {
        if (tableExists('document_sequences', $conn)) {
            return $conn;
        }
    }
    return null;
}

/**
 * Extract PV/XXX/YYYY/ from a voucher number (e.g. PV/UGT/2026/011 → PV/UGT/2026/).
 */
function parsePaymentVoucherNumberPrefix($voucherNo): string
{
    $voucherNo = trim((string) $voucherNo);
    if ($voucherNo === '') {
        return '';
    }
    $parts = explode('/', $voucherNo);
    $p0 = strtoupper(trim((string) ($parts[0] ?? '')));
    if (count($parts) >= 4 && preg_match('/^[A-Z]{2,3}$/', $p0)) {
        return $parts[0] . '/' . $parts[1] . '/' . $parts[2] . '/';
    }
    return '';
}

/**
 * Parse a document sequence prefix into its prefix (before year) and suffix (after year) parts.
 *
 * @param string $fullPrefix
 * @return array{prefix: string, suffix: string}
 */
function parsePrefixParts($fullPrefix): array
{
    $fullPrefix = trim((string) $fullPrefix);
    $prefixPart = '';
    $suffixPart = '';

    if ($fullPrefix === '') {
        return ['prefix' => '', 'suffix' => ''];
    }

    if (strpos($fullPrefix, '{YEAR}') !== false) {
        $parts = explode('{YEAR}', $fullPrefix);
        $prefixPart = $parts[0];
        $suffixPart = isset($parts[1]) ? $parts[1] : '';
    } else {
        if (preg_match('/^(.*?)\/?(\b\d{4}\b)\/?(.*?)$/', $fullPrefix, $matches)) {
            $prefixPart = $matches[1];
            $suffixPart = $matches[3];
        } else {
            $prefixPart = $fullPrefix;
            $suffixPart = '';
        }
    }

    $prefixPart = trim($prefixPart, '/');
    $suffixPart = trim($suffixPart, '/');

    return [
        'prefix' => $prefixPart,
        'suffix' => $suffixPart
    ];
}

/**
 * ORDER BY clause for payment voucher list pages.
 * "newest" uses primary key so voucher #301 always appears above #300 when just created.
 *
 * @param string $sort newest|asc|voucher_no
 */
function buildPaymentVoucherListOrderBySql(string $sort, string $alias = 'pv'): string
{
    $sort = strtolower(trim($sort));
    if (!in_array($sort, ['newest', 'asc', 'desc', 'voucher_no'], true)) {
        $sort = 'newest';
    }
    $a = preg_replace('/[^a-z_]/', '', $alias) ?: 'pv';
    if ($sort === 'asc') {
        return "ORDER BY {$a}.id ASC";
    }
    if ($sort === 'voucher_no') {
        return "ORDER BY
            CASE 
                WHEN SUBSTRING_INDEX(SUBSTRING_INDEX({$a}.voucher_no, '/', 2), '/', -1) REGEXP '^[0-9]{4}$' 
                    THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX({$a}.voucher_no, '/', 2), '/', -1) AS UNSIGNED)
                WHEN SUBSTRING_INDEX(SUBSTRING_INDEX({$a}.voucher_no, '/', 3), '/', -1) REGEXP '^[0-9]{4}$' 
                    THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX({$a}.voucher_no, '/', 3), '/', -1) AS UNSIGNED)
                ELSE 0
            END DESC,
            CAST(SUBSTRING_INDEX({$a}.voucher_no, '/', -1) AS UNSIGNED) DESC,
            {$a}.id DESC";
    }
    // newest / desc — creation order (auto-increment id)
    return "ORDER BY {$a}.id DESC";
}

/**
 * Absolute URL for toggling payment voucher reference star.
 */
function paymentVoucherReferenceToggleUrl(): string
{
    return function_exists('app_url')
        ? app_url('/toggle-voucher-reference.php')
        : '/toggle-voucher-reference.php';
}

/**
 * Clickable reference star for payment voucher list actions column.
 */
function paymentVoucherReferenceStarHtml(int $voucherId, $isReference = false): string
{
    if ($voucherId <= 0) {
        return '';
    }

    $marked = (int) $isReference === 1;
    $title = $marked ? 'Reference voucher (click to unmark)' : 'Mark as reference';
    $iconClass = $marked ? 'fas fa-star' : 'far fa-star';
    $toggleUrl = htmlspecialchars(paymentVoucherReferenceToggleUrl(), ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="pv-reference-star-btn' . ($marked ? ' is-marked' : '') . '"'
        . ' data-voucher-id="' . (int) $voucherId . '"'
        . ' data-is-reference="' . ($marked ? '1' : '0') . '"'
        . ' data-toggle-url="' . $toggleUrl . '"'
        . ' onclick="event.stopPropagation();"'
        . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-pressed="' . ($marked ? 'true' : 'false') . '">'
        . '<i class="' . $iconClass . '" aria-hidden="true"></i></button>';
}

/**
 * HTML for payment voucher number in list tables.
 */
function paymentVoucherListNumberHtml(?string $voucherNo): string
{
    $no = htmlspecialchars(trim((string) $voucherNo), ENT_QUOTES, 'UTF-8');

    return $no !== '' ? $no : '—';
}

/**
 * Toggle payment voucher reference flag (star bookmark).
 *
 * @return array{ok:bool,is_reference?:int,error?:string}
 */
function togglePaymentVoucherReference(int $voucherId, int $userId): array
{
    global $pdo;

    if ($voucherId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    ensureVoucherReferenceColumn();

    $sql = 'SELECT pv.id, pv.created_by, IFNULL(pv.is_reference, 0) AS is_reference';
    if (function_exists('columnExists') && columnExists('payment_vouchers', 'is_restricted', $pdo)) {
        $sql .= ', IFNULL(pv.is_restricted, 0) AS is_restricted';
    } else {
        $sql .= ', 0 AS is_restricted';
    }
    $sql .= ' FROM payment_vouchers pv WHERE pv.id = ?';
    $params = [$voucherId];
    if (function_exists('getCompanySql')) {
        $companySql = getCompanySql('pv');
        if ($companySql !== '') {
            $sql .= $companySql;
            $params = array_merge($params, getCompanyParam());
        }
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Voucher not found.'];
    }

    if (!empty($row['is_restricted']) && (int) $row['is_restricted'] === 1) {
        $canView = function_exists('isAdmin') && (isAdmin() || isFinance())
            || (int) ($row['created_by'] ?? 0) === $userId;
        if (!$canView) {
            return ['ok' => false, 'error' => 'You cannot update this restricted voucher.'];
        }
    }

    $newValue = ((int) ($row['is_reference'] ?? 0) === 1) ? 0 : 1;

    try {
        $sets = ['is_reference = ?'];
        $vals = [$newValue];
        if (function_exists('columnExists') && columnExists('payment_vouchers', 'updated_at', $pdo)) {
            $sets[] = 'updated_at = NOW()';
        }
        $upd = $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $vals[] = $voucherId;
        $upd->execute($vals);
    } catch (Throwable $e) {
        error_log('togglePaymentVoucherReference: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save reference mark.'];
    }

    return ['ok' => true, 'is_reference' => $newValue];
}

/**
 * Configured payment-voucher prefix for a company/year (document_sequences).
 */
function getCurrentPaymentVoucherSequencePrefix(PDO $pdo, $companyId = null, $year = null): string
{
    $companyId = (int) ($companyId ?? currentCompanyId() ?? 0);
    $year = (int) ($year ?? date('Y'));
    $seqPdo = documentSequencesPdo($pdo);
    if ($companyId <= 0 || !($seqPdo instanceof PDO)) {
        return '';
    }
    try {
        $stmt = $seqPdo->prepare(
            'SELECT prefix FROM document_sequences
             WHERE company_id = ? AND document_type = ? AND year = ?
             LIMIT 1'
        );
        $stmt->execute([$companyId, 'payment_voucher', $year]);
        $rawPrefix = trim((string) ($stmt->fetchColumn() ?: ''));

        // Fallback to most recent year if not configured for this year
        if ($rawPrefix === '') {
            $stmt = $seqPdo->prepare(
                'SELECT prefix FROM document_sequences
                 WHERE company_id = ? AND document_type = ? AND prefix LIKE \'%{YEAR}%\'
                 ORDER BY year DESC LIMIT 1'
            );
            $stmt->execute([$companyId, 'payment_voucher']);
            $rawPrefix = trim((string) ($stmt->fetchColumn() ?: ''));

            // If still empty, try any recent year prefix and try to convert its year to {YEAR}
            if ($rawPrefix === '') {
                $stmt = $seqPdo->prepare(
                    'SELECT prefix, year FROM document_sequences
                     WHERE company_id = ? AND document_type = ?
                     ORDER BY year DESC LIMIT 1'
                );
                $stmt->execute([$companyId, 'payment_voucher']);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $raw = trim((string) ($row['prefix'] ?? ''));
                    $yr = (int) $row['year'];
                    $rawPrefix = str_replace((string)$yr, '{YEAR}', $raw);
                }
            }
        }

        if ($rawPrefix !== '') {
            return str_replace('{YEAR}', (string)$year, $rawPrefix);
        }
        return '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Prefix options for voucher list filters (company settings + legacy voucher numbers).
 *
 * @return list<array{value: string, label: string, is_configured: bool, is_current: bool}>
 */
function fetchPaymentVoucherPrefixFilterOptions(PDO $pdo, $companyId = null): array
{
    $companyId = (int) ($companyId ?? currentCompanyId() ?? 0);
    $currentYear = (int) date('Y');
    $options = [];
    $seen = [];
    $seqPdo = documentSequencesPdo($pdo);

    if ($companyId > 0 && ($seqPdo instanceof PDO)) {
        try {
            $stmt = $seqPdo->prepare(
                'SELECT prefix, year FROM document_sequences
                 WHERE company_id = ? AND document_type = ?
                   AND prefix IS NOT NULL AND TRIM(prefix) <> \'\'
                 ORDER BY year DESC, prefix ASC'
            );
            $stmt->execute([$companyId, 'payment_voucher']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rawPfx = trim((string) ($row['prefix'] ?? ''));
                if ($rawPfx === '') {
                    continue;
                }
                $yr = (int) ($row['year'] ?? 0);
                $pfx = str_replace('{YEAR}', (string)$yr, $rawPfx);
                if (isset($seen[$pfx])) {
                    continue;
                }
                $seen[$pfx] = true;
                $isCurrent = ($yr === $currentYear);
                $options[] = [
                    'value' => $pfx,
                    'label' => $pfx . ($isCurrent ? ' (current)' : ''),
                    'is_configured' => true,
                    'is_current' => $isCurrent,
                ];
            }
        } catch (Throwable $e) {
            error_log('fetchPaymentVoucherPrefixFilterOptions sequences: ' . $e->getMessage());
        }
    }

    try {
        $where = '';
        $params = [];
        if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pdo)) {
            $where = ' WHERE company_id = ?';
            $params[] = $companyId;
        }
        $sql = 'SELECT voucher_no FROM payment_vouchers' . $where . ' ORDER BY id DESC LIMIT 2000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $voucherNo) {
            $pfx = parsePaymentVoucherNumberPrefix($voucherNo);
            if ($pfx === '' || isset($seen[$pfx])) {
                continue;
            }
            $seen[$pfx] = true;
            $options[] = [
                'value' => $pfx,
                'label' => $pfx . ' (legacy)',
                'is_configured' => false,
                'is_current' => false,
            ];
        }
    } catch (Throwable $e) {
        error_log('fetchPaymentVoucherPrefixFilterOptions vouchers: ' . $e->getMessage());
    }

    return $options;
}

/**
 * Normalize voucher prefix to trailing slash form: PV/UGT/2026/
 */
function normalizePaymentVoucherPrefix($prefix): string
{
    $prefix = trim((string) $prefix);
    if ($prefix === '') {
        return '';
    }
    $prefix = rtrim($prefix, '/') . '/';
    return $prefix;
}

/**
 * Sequence integer from voucher number (last path segment).
 */
function parsePaymentVoucherSequenceNumber($voucherNo): int
{
    $parts = explode('/', trim((string) $voucherNo));
    if (count($parts) < 4) {
        return 0;
    }
    return (int) ($parts[count($parts) - 1] ?? 0);
}

/**
 * PDO for payment_vouchers reads/writes (tenant DB when split from control).
 */
function paymentVouchersPdo($fallbackPdo = null): ?PDO
{
    global $pdo;
    if (function_exists('voucher_operational_pdo')) {
        $op = voucher_operational_pdo();
        if ($op instanceof PDO && erp_connection_has_table($op, 'payment_vouchers')) {
            return $op;
        }
    }
    $use = ($fallbackPdo instanceof PDO) ? $fallbackPdo : $pdo;
    return ($use instanceof PDO && erp_connection_has_table($use, 'payment_vouchers')) ? $use : null;
}

/**
 * @return array{padding: int, year: int}
 */
function getPaymentVoucherSequenceMeta(PDO $pdo, int $companyId, string $prefix): array
{
    $year = (int) date('Y');
    $parts = explode('/', trim(normalizePaymentVoucherPrefix($prefix), '/'));
    if (count($parts) >= 3 && ctype_digit((string) $parts[2])) {
        $year = (int) $parts[2];
    }
    $padding = 3;
    $seqPdo = documentSequencesPdo($pdo);
    if ($seqPdo instanceof PDO && $companyId > 0) {
        try {
            $stmt = $seqPdo->prepare(
                'SELECT padding FROM document_sequences
                 WHERE company_id = ? AND document_type = ? AND year = ? LIMIT 1'
            );
            $stmt->execute([$companyId, 'payment_voucher', $year]);
            $padding = max(1, (int) ($stmt->fetchColumn() ?: 3));
        } catch (Throwable $e) {
        }
    }
    return ['padding' => $padding, 'year' => $year];
}

function paymentVoucherNumberExists(PDO $pdo, string $voucherNo, int $companyId = 0): bool
{
    $voucherNo = trim($voucherNo);
    if ($voucherNo === '') {
        return false;
    }
    try {
        if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pdo)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_vouchers WHERE voucher_no = ? AND company_id = ?');
            $stmt->execute([$voucherNo, $companyId]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_vouchers WHERE voucher_no = ?');
            $stmt->execute([$voucherNo]);
        }
        return ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return true;
    }
}

function getMaxPaymentVoucherSequenceForPrefix(PDO $pdo, string $prefix, int $companyId = 0): int
{
    $prefix = normalizePaymentVoucherPrefix($prefix);
    if ($prefix === '') {
        return 0;
    }
    try {
        $sql = 'SELECT MAX(CAST(SUBSTRING_INDEX(voucher_no, \'/\', -1) AS UNSIGNED)) FROM payment_vouchers WHERE voucher_no LIKE ?';
        $params = [$prefix . '%'];
        if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pdo)) {
            $sql .= ' AND company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Count vouchers whose prefix differs from the configured current prefix.
 */
function countLegacyPaymentVoucherPrefixes(PDO $pdo, int $companyId, string $targetPrefix): int
{
    $pvPdo = paymentVouchersPdo($pdo);
    $targetPrefix = normalizePaymentVoucherPrefix($targetPrefix);
    if (!($pvPdo instanceof PDO) || $targetPrefix === '') {
        return 0;
    }
    try {
        $sql = 'SELECT COUNT(*) FROM payment_vouchers WHERE voucher_no NOT LIKE ?';
        $params = [$targetPrefix . '%'];
        if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pvPdo)) {
            $sql .= ' AND company_id = ?';
            $params[] = $companyId;
        }
        $sql .= " AND (voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%')";
        $stmt = $pvPdo->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Renumber vouchers from one prefix to another (assigns new sequence numbers after current max).
 *
 * @return array{ok: bool, migrated: int, changes: list<array{id:int,old:string,new:string}>, error?: string}
 */
function migratePaymentVoucherPrefix(PDO $pdo, int $companyId, string $fromPrefix, string $toPrefix, bool $dryRun = false): array
{
    $fromPrefix = normalizePaymentVoucherPrefix($fromPrefix);
    $toPrefix = normalizePaymentVoucherPrefix($toPrefix);
    if ($fromPrefix === '' || $toPrefix === '' || $fromPrefix === $toPrefix) {
        return ['ok' => true, 'migrated' => 0, 'changes' => []];
    }

    $pvPdo = paymentVouchersPdo($pdo);
    if (!($pvPdo instanceof PDO)) {
        return ['ok' => false, 'migrated' => 0, 'changes' => [], 'error' => 'payment_vouchers table not available'];
    }

    $meta = getPaymentVoucherSequenceMeta($pdo, $companyId, $toPrefix);
    $padding = (int) $meta['padding'];

    $where = 'voucher_no LIKE ?';
    $params = [$fromPrefix . '%'];
    if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pvPdo)) {
        $where .= ' AND company_id = ?';
        $params[] = $companyId;
    }

    try {
        $stmt = $pvPdo->prepare("SELECT id, voucher_no FROM payment_vouchers WHERE {$where} ORDER BY id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return ['ok' => true, 'migrated' => 0, 'changes' => []];
        }

        $nextSeq = getMaxPaymentVoucherSequenceForPrefix($pvPdo, $toPrefix, $companyId) + 1;
        $changes = [];
        $txStarted = false;

        if (!$dryRun) {
            $pvPdo->beginTransaction();
            $txStarted = true;
        }

        $update = $pvPdo->prepare('UPDATE payment_vouchers SET voucher_no = ? WHERE id = ?');
        foreach ($rows as $row) {
            $newNo = $toPrefix . str_pad((string) $nextSeq, $padding, '0', STR_PAD_LEFT);
            while (paymentVoucherNumberExists($pvPdo, $newNo, $companyId)) {
                $nextSeq++;
                $newNo = $toPrefix . str_pad((string) $nextSeq, $padding, '0', STR_PAD_LEFT);
            }
            $changes[] = [
                'id' => (int) $row['id'],
                'old' => (string) $row['voucher_no'],
                'new' => $newNo,
            ];
            if (!$dryRun) {
                $update->execute([$newNo, (int) $row['id']]);
            }
            $nextSeq++;
        }

        if (!$dryRun) {
            $seqPdo = documentSequencesPdo($pdo);
            if ($seqPdo instanceof PDO && $companyId > 0) {
                $year = (int) $meta['year'];
                $seqPdo->prepare(
                    'UPDATE document_sequences SET next_number = GREATEST(next_number, ?), prefix = ?, updated_at = NOW()
                     WHERE company_id = ? AND document_type = ? AND year = ?'
                )->execute([$nextSeq, $toPrefix, $companyId, 'payment_voucher', $year]);
            }
            if ($txStarted && $pvPdo->inTransaction()) {
                $pvPdo->commit();
            }
        }

        return ['ok' => true, 'migrated' => count($changes), 'changes' => $changes];
    } catch (Throwable $e) {
        if (!$dryRun && isset($pvPdo) && $pvPdo->inTransaction()) {
            $pvPdo->rollBack();
        }
        error_log('migratePaymentVoucherPrefix: ' . $e->getMessage());
        return ['ok' => false, 'migrated' => 0, 'changes' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Migrate every non-current prefix to the configured payment-voucher prefix.
 *
 * @return array{ok: bool, target: string, total: int, runs: list<array>, error?: string}
 */
function migrateAllLegacyPaymentVoucherPrefixes(PDO $pdo, int $companyId): array
{
    if (function_exists('voucher_bootstrap_operational_pdo')) {
        voucher_bootstrap_operational_pdo();
    }
    $target = getCurrentPaymentVoucherSequencePrefix($pdo, $companyId);
    if ($target === '') {
        return ['ok' => false, 'target' => '', 'total' => 0, 'runs' => [], 'error' => 'No payment voucher prefix configured in company settings.'];
    }
    $target = normalizePaymentVoucherPrefix($target);

    $legacyPrefixes = [];
    foreach (fetchPaymentVoucherPrefixFilterOptions($pdo, $companyId) as $opt) {
        $pfx = normalizePaymentVoucherPrefix($opt['value'] ?? '');
        if ($pfx === '' || $pfx === $target) {
            continue;
        }
        $legacyPrefixes[$pfx] = $pfx;
    }

    // Also pick up any PV/* prefix not matching target (even if not in options list)
    $pvPdo = paymentVouchersPdo($pdo);
    if ($pvPdo instanceof PDO) {
        try {
            $where = "(voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%') AND voucher_no NOT LIKE ?";
            $params = [$target . '%'];
            if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pvPdo)) {
                $where .= ' AND company_id = ?';
                $params[] = $companyId;
            }
            $stmt = $pvPdo->prepare("SELECT DISTINCT voucher_no FROM payment_vouchers WHERE {$where}");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $vno) {
                $pfx = parsePaymentVoucherNumberPrefix($vno);
                if ($pfx !== '' && $pfx !== $target) {
                    $legacyPrefixes[$pfx] = $pfx;
                }
            }
        } catch (Throwable $e) {
        }
    }

    $runs = [];
    $total = 0;
    foreach ($legacyPrefixes as $fromPrefix) {
        $result = migratePaymentVoucherPrefix($pdo, $companyId, $fromPrefix, $target, false);
        $runs[] = array_merge(['from' => $fromPrefix, 'to' => $target], $result);
        if (!empty($result['ok'])) {
            $total += (int) ($result['migrated'] ?? 0);
        }
    }

    return ['ok' => true, 'target' => $target, 'total' => $total, 'runs' => $runs];
}

function saveDocumentSequence(
    PDO $pdo,
    int $companyId,
    string $documentType,
    string $prefix,
    int $nextNumber,
    int $padding,
    int $year
): bool {
    ensureMultiCompanyControlSchema();
    $seqPdo = documentSequencesPdo($pdo);
    if ($companyId <= 0 || $documentType === '' || !($seqPdo instanceof PDO)) {
        return false;
    }
    try {
        $stmt = $seqPdo->prepare(
            'INSERT INTO document_sequences (company_id, document_type, prefix, next_number, padding, year)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), next_number = VALUES(next_number),
             padding = VALUES(padding), updated_at = NOW()'
        );
        return $stmt->execute([$companyId, $documentType, $prefix, $nextNumber, $padding, $year]);
    } catch (Throwable $e) {
        return false;
    }
}

function ensureCompaniesTableColumns($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!$usePdo instanceof PDO || !tableExists('companies', $usePdo)) {
        return;
    }

    static $completed = [];
    $token = pdoInstanceToken($usePdo);
    if (isset($completed[$token])) {
        return;
    }
    $completed[$token] = true;

    $columns = [
        'legal_name' => 'VARCHAR(200) NULL',
        'logo' => 'VARCHAR(255) NULL',
        'domain' => 'VARCHAR(150) NULL',
        'subdomain' => 'VARCHAR(100) NULL',
        'company_slug' => 'VARCHAR(160) NULL',
        'db_name' => 'VARCHAR(100) NULL',
        'db_host' => 'VARCHAR(150) NULL',
        'db_user' => 'VARCHAR(100) NULL',
        'db_pass' => 'VARCHAR(255) NULL',
        'setup_status' => "VARCHAR(30) NOT NULL DEFAULT 'active'",
        'invite_code' => 'VARCHAR(40) NULL',
        'employee_registration_mode' => "VARCHAR(30) NOT NULL DEFAULT 'admin_only'",
        'allow_employee_self_registration' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'require_admin_approval_for_new_users' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'timezone' => "VARCHAR(100) NOT NULL DEFAULT 'Africa/Dar_es_Salaam'",
        'base_currency' => "VARCHAR(10) NOT NULL DEFAULT 'TZS'",
        'email' => 'VARCHAR(255) NULL',
        'phone' => 'VARCHAR(50) NULL',
        'address' => 'TEXT NULL',
        'country' => "VARCHAR(100) NULL DEFAULT 'Tanzania'",
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $columnName => $definition) {
        if (columnExists('companies', $columnName, $usePdo)) {
            continue;
        }
        try {
            $usePdo->exec('ALTER TABLE companies ADD COLUMN `' . str_replace('`', '``', $columnName) . '` ' . $definition);
        } catch (Throwable $e) {
            error_log('ensureCompaniesTableColumns(' . $columnName . '): ' . $e->getMessage());
        }
    }
}

function ensureUsersControlTable($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!($usePdo instanceof PDO) || tableExists('users', $usePdo)) {
        return true;
    }
    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'employee',
                department VARCHAR(50) NULL,
                company_id INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                approval_status VARCHAR(20) NOT NULL DEFAULT 'approved',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                signature_path VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_username (username),
                UNIQUE KEY uq_users_email (email),
                KEY idx_users_company_id (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        error_log('ensureUsersControlTable: ' . $e->getMessage());
        return false;
    }
}



/**
 * After a password change in the tenant DB, mirror the hash to control-plane users (same email).
 */
function syncLoginPasswordToControlPlane(array $emails, $passwordHash, $companyId = 0)
{
    global $control_pdo;
    if (!($control_pdo instanceof PDO) || $passwordHash === '' || $emails === array()) {
        return;
    }
    if (!function_exists('columnExists') || !columnExists('users', 'email', $control_pdo)) {
        return;
    }

    $normalized = array();
    foreach ($emails as $em) {
        $n = normalizeLoginEmail($em);
        if ($n !== '') {
            $normalized[$n] = $n;
        }
    }
    if ($normalized === array()) {
        return;
    }

    $companyId = (int) $companyId;
    try {
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = 'UPDATE users SET password = ? WHERE LOWER(TRIM(email)) IN (' . $placeholders . ')';
        $params = array_merge(array($passwordHash), array_values($normalized));
        if ($companyId > 0 && columnExists('users', 'company_id', $control_pdo)) {
            $sql .= ' AND company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $control_pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('syncLoginPasswordToControlPlane: ' . $e->getMessage());
    }
}



/**
 * Whether a PDO connection has a given table.
 */
function erp_connection_has_table($conn, $table)
{
    if (!($conn instanceof PDO)) {
        return false;
    }
    static $cache = array();
    $table = (string) $table;
    $key = pdoInstanceToken($conn) . "\0" . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = $conn->query('SHOW TABLES LIKE ' . $conn->quote($table));
        $cache[$key] = ($st && $st->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * PDO for ERP operational data (products, stock, vouchers) when control/tenant DB is empty.
 */
function erp_data_pdo()
{
    static $resolved = null;
    if ($resolved instanceof PDO) {
        return $resolved;
    }

    global $pdo, $control_pdo;

    foreach (array($pdo, $control_pdo) as $conn) {
        if ($conn instanceof PDO && erp_connection_has_table($conn, 'payees')) {
            $resolved = $conn;
            try {
                $GLOBALS['erp_data_database_name'] = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable $e) {
            }
            return $resolved;
        }
    }

    foreach (array($pdo, $control_pdo) as $conn) {
        if ($conn instanceof PDO && erp_connection_has_table($conn, 'products')) {
            $resolved = $conn;
            try {
                $GLOBALS['erp_data_database_name'] = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable $e) {
            }
            return $resolved;
        }
    }

    $dbCandidates = array();
    if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
        $dbCandidates[] = trim((string) DATA_DB_NAME);
    }
    if (defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '') {
        $dbCandidates[] = trim((string) SALES_DB_NAME);
    }

    $metaPdo = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
    if ($metaPdo instanceof PDO) {
        try {
            $cid = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
            if ($cid <= 0 && !empty($_SESSION['company_id'])) {
                $cid = (int) $_SESSION['company_id'];
            }
            if ($cid > 0 && function_exists('tableExists') && tableExists('companies', $metaPdo)) {
                $st = $metaPdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
                $st->execute(array($cid));
                $rowDb = trim((string) ($st->fetchColumn() ?: ''));
                if ($rowDb !== '') {
                    $dbCandidates[] = $rowDb;
                }
            }
        } catch (Throwable $e) {
        }
        $allowFullDbScan = defined('ERP_ALLOW_DATABASE_SCAN') && ERP_ALLOW_DATABASE_SCAN;
        if ($allowFullDbScan || (int) ($_SESSION['company_id'] ?? 0) <= 0) {
            try {
                foreach ($metaPdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $dbName) {
                    $dbName = (string) $dbName;
                    if ($dbName === '' || in_array($dbName, array('information_schema', 'performance_schema', 'mysql', 'sys'), true)) {
                        continue;
                    }
                    if (preg_match('/ultimate|trading|voucher|roadmaster/i', $dbName)) {
                        $dbCandidates[] = $dbName;
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }

    $dbCandidates[] = 'new_trading_voucher_db';
    $dbCandidates[] = 'ultimate_trading_voucher';

    $dbCandidates = array_values(array_unique(array_filter($dbCandidates)));
    foreach ($dbCandidates as $dbName) {
        if ($dbName === '' || !function_exists('connectToTenantDatabase')) {
            continue;
        }
        $tenantPdo = connectToTenantDatabase($dbName);
        if (!($tenantPdo instanceof PDO)) {
            continue;
        }
        if (erp_connection_has_table($tenantPdo, 'payees') || erp_connection_has_table($tenantPdo, 'products')) {
            $resolved = $tenantPdo;
            $GLOBALS['erp_data_database_name'] = $dbName;
            error_log('erp_data_pdo: using database ' . $dbName);
            return $resolved;
        }
    }

    $resolved = ($pdo instanceof PDO) ? $pdo : $control_pdo;
    return $resolved;
}

/**
 * PDO for voucher payees, sales orders, and payment_vouchers when split from control DB.
 */
function voucher_operational_pdo()
{
    static $resolved = null;
    if ($resolved instanceof PDO) {
        return $resolved;
    }

    global $pdo, $control_pdo;
    $candidates = array();
    if ($pdo instanceof PDO) {
        $candidates[] = $pdo;
    }
    if (function_exists('erp_data_pdo')) {
        $erp = erp_data_pdo();
        if ($erp instanceof PDO) {
            $candidates[] = $erp;
        }
    }
    if ($control_pdo instanceof PDO) {
        $candidates[] = $control_pdo;
    }
    $candidates = array_values(array_unique($candidates, SORT_REGULAR));

    foreach ($candidates as $conn) {
        if ($conn instanceof PDO && erp_connection_has_table($conn, 'payees')) {
            $resolved = $conn;
            return $resolved;
        }
    }

    $resolved = ($pdo instanceof PDO) ? $pdo : $control_pdo;
    return $resolved;
}

/**
 * Point active $pdo at the database that contains products/stock or voucher tables.
 */
function erp_bootstrap_active_pdo()
{
    global $pdo;
    if (!function_exists('erp_data_pdo')) {
        return;
    }
    $dataConn = erp_data_pdo();
    if (!($dataConn instanceof PDO) || $dataConn === $pdo) {
        return;
    }

    $pdoHasPayees = erp_connection_has_table($pdo, 'payees');
    $dataHasPayees = erp_connection_has_table($dataConn, 'payees');
    if (!$pdoHasPayees && $dataHasPayees) {
        if (!isset($GLOBALS['tenant_pdo']) || !($GLOBALS['tenant_pdo'] instanceof PDO)) {
            $GLOBALS['tenant_pdo'] = $pdo;
        }
        $pdo = $dataConn;
        $GLOBALS['pdo'] = $dataConn;
        return;
    }

    if (erp_connection_has_table($pdo, 'products')) {
        return;
    }
    if (!erp_connection_has_table($dataConn, 'products')) {
        return;
    }
    if (!isset($GLOBALS['tenant_pdo']) || !($GLOBALS['tenant_pdo'] instanceof PDO)) {
        $GLOBALS['tenant_pdo'] = $pdo;
    }
    $pdo = $dataConn;
    $GLOBALS['pdo'] = $dataConn;
}

/**
 * Ensure voucher pages use the DB that has payees (tenant/data), not control-only schema.
 */
function voucher_bootstrap_operational_pdo()
{
    global $pdo;
    if (!function_exists('voucher_operational_pdo')) {
        return;
    }
    $op = voucher_operational_pdo();
    if (!($op instanceof PDO) || $op === $pdo) {
        return;
    }
    if (erp_connection_has_table($pdo, 'payees')) {
        return;
    }
    if (!erp_connection_has_table($op, 'payees')) {
        return;
    }
    if (!isset($GLOBALS['tenant_pdo']) || !($GLOBALS['tenant_pdo'] instanceof PDO)) {
        $GLOBALS['tenant_pdo'] = $pdo;
    }
    $pdo = $op;
    $GLOBALS['pdo'] = $op;
}

/**
 * Active users for voucher approval dropdowns (tenant DB; supports name + full_name).
 *
 * @return array{all: list<array>, finance: list<array>}
 */
function fetchVoucherApprovalUsers(PDO $pdo)
{
    $empty = array('all' => array(), 'finance' => array());
    if (!($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'users')) {
        return $empty;
    }
    try {
        static $usersColsCache = array();
        $colsKey = pdoInstanceToken($pdo);
        if (!isset($usersColsCache[$colsKey])) {
            $usersColsCache[$colsKey] = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        }
        $cols = $usersColsCache[$colsKey];
        $hasName = in_array('name', $cols, true);
        $hasDept = in_array('department', $cols, true);
        $hasRole = in_array('role', $cols, true);
        $displayExpr = $hasName
            ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
            : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
        $sql = 'SELECT id, ' . $displayExpr . ' AS full_name';
        if ($hasDept) {
            $sql .= ', department';
        }
        if ($hasRole) {
            $sql .= ', role';
        }
        $hasActive = in_array('is_active', $cols, true);
        $sql .= ' FROM users';
        if ($hasActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' HAVING full_name <> \'\' ORDER BY full_name ASC';
        $allUsers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
        $roleAdmin = defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin';
        $financeUsers = array_values(array_filter($allUsers, static function ($u) use ($roleAdmin) {
            $isFinance = isset($u['department']) && strcasecmp(trim((string) $u['department']), 'Finance') === 0;
            $isAdmin = isset($u['role']) && strtolower(trim((string) $u['role'])) === strtolower((string) $roleAdmin);
            return $isFinance || $isAdmin;
        }));
        return array('all' => $allUsers, 'finance' => $financeUsers);
    } catch (Throwable $e) {
        error_log('fetchVoucherApprovalUsers: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Resolve tenant user id from a display name saved on payment_vouchers / approvals.
 */
function resolveVoucherUserIdByDisplayName(PDO $pdo, $displayName)
{
    $displayName = trim((string) $displayName);
    if ($displayName === '' || !($pdo instanceof PDO)) {
        return 0;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        $hasName = in_array('name', $cols, true);
        $displayExpr = $hasName
            ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
            : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
        $st = $pdo->prepare('SELECT id FROM users WHERE ' . $displayExpr . ' = ? LIMIT 1');
        $st->execute(array($displayName));
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        if ($hasName) {
            $st = $pdo->prepare(
                'SELECT id FROM users WHERE TRIM(full_name) = ? OR TRIM(name) = ? OR TRIM(username) = ? LIMIT 1'
            );
            $st->execute(array($displayName, $displayName, $displayName));
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM users WHERE TRIM(full_name) = ? OR TRIM(username) = ? LIMIT 1'
            );
            $st->execute(array($displayName, $displayName));
        }
        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolve the tenant user id for voucher created_by (session id may not exist in tenant DB).
 */
function resolveVoucherSessionUserId(PDO $pdo): int
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && erp_connection_has_table($pdo, 'users')) {
        try {
            $st = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            if ((int) $st->fetchColumn() > 0) {
                return $uid;
            }
        } catch (Throwable $e) {
        }
    }
    foreach (array('full_name', 'username') as $sessionKey) {
        $label = trim((string) ($_SESSION[$sessionKey] ?? ''));
        if ($label === '') {
            continue;
        }
        $resolved = resolveVoucherUserIdByDisplayName($pdo, $label);
        if ($resolved > 0) {
            return $resolved;
        }
    }
    return $uid;
}

/**
 * Best display name for the logged-in user when matching voucher assignees.
 */
function resolveVoucherSessionDisplayName(PDO $pdo): string
{
    foreach (array('full_name', 'username') as $sessionKey) {
        $label = trim((string) ($_SESSION[$sessionKey] ?? ''));
        if ($label !== '') {
            return $label;
        }
    }
    $uid = function_exists('resolveVoucherSessionUserId') ? resolveVoucherSessionUserId($pdo) : (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && erp_connection_has_table($pdo, 'users')) {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: array();
            $hasName = in_array('name', $cols, true);
            $displayExpr = $hasName
                ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
                : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
            $st = $pdo->prepare('SELECT ' . $displayExpr . ' AS display_name FROM users WHERE id = ? LIMIT 1');
            $st->execute(array($uid));
            $name = trim((string) ($st->fetchColumn() ?: ''));
            if ($name !== '') {
                return $name;
            }
        } catch (Throwable $e) {
        }
    }
    return '';
}

/**
 * Resolve a pending approvals row id (some tenants have broken id=0 rows).
 */
function resolveVoucherApprovalRowId(PDO $pdo, $voucherId, array $row): int
{
    $rowId = (int) ($row['id'] ?? 0);
    if ($rowId > 0) {
        return $rowId;
    }
    $voucherId = (int) $voucherId;
    $roleKey = normalizeVoucherApprovalRoleKey($row['role'] ?? '');
    if ($voucherId <= 0 || $roleKey === '' || !($pdo instanceof PDO)) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id FROM approvals WHERE voucher_id = ? AND LOWER(TRIM(role)) = ? AND status = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute(array($voucherId, $roleKey, 'pending'));
        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Keep approvals rows aligned with payment_vouchers assignee names after create/edit.
 *
 * @param array<string, string> $roleNames e.g. ['Applicant' => 'Jane Doe', ...]
 */
function syncVoucherApprovalAssignees(PDO $pdo, $voucherId, array $roleNames)
{
    $voucherId = (int) $voucherId;
    if ($voucherId <= 0 || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return;
    }
    try {
        $apprCols = $pdo->query('SHOW COLUMNS FROM approvals')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        $hasCompanyId = in_array('company_id', $apprCols, true);
        $cId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;

        $find = $pdo->prepare(
            'SELECT id, status FROM approvals WHERE voucher_id = ? AND LOWER(TRIM(role)) = ? ORDER BY id ASC'
        );
        $update = $pdo->prepare(
            'UPDATE approvals SET approver_id = ?, approver_name = ? WHERE id = ?'
        );

        $colsAppr = array('voucher_id', 'approver_id', 'approver_name', 'role', 'status', 'created_at');
        $placeholdersAppr = array('?', '?', '?', '?', "'pending'", 'NOW()');
        if ($hasCompanyId) {
            $colsAppr[] = 'company_id';
            $placeholdersAppr[] = '?';
        }
        $insert = $pdo->prepare(
            'INSERT INTO approvals (' . implode(', ', $colsAppr) . ') VALUES (' . implode(', ', $placeholdersAppr) . ')'
        );

        foreach ($roleNames as $role => $name) {
            $role = trim((string) $role);
            $name = trim((string) $name);
            if ($role === '' || $name === '') {
                continue;
            }
            $roleKey = strtolower($role);
            $approverId = resolveVoucherUserIdByDisplayName($pdo, $name);
            if ($approverId <= 0) {
                $approverId = null;
            }

            $find->execute(array($voucherId, $roleKey));
            $existingRows = $find->fetchAll(PDO::FETCH_ASSOC) ?: array();
            if ($existingRows) {
                $updated = false;
                foreach ($existingRows as $existing) {
                    $existingId = (int) ($existing['id'] ?? 0);
                    if ($existingId <= 0) {
                        continue;
                    }
                    $update->execute(array($approverId, $name, $existingId));
                    $updated = true;
                }
                if ($updated) {
                    continue;
                }
            }

            $vals = array($voucherId, $approverId, $name, $role);
            if ($hasCompanyId && $cId > 0) {
                $vals[] = $cId;
            }
            $insert->execute($vals);
        }
    } catch (Throwable $e) {
        error_log('syncVoucherApprovalAssignees voucher ' . $voucherId . ': ' . $e->getMessage());
    }
}

/**
 * Normalize approval role keys for voucher workflow matching.
 */
function normalizeVoucherApprovalRoleKey($role): string
{
    $r = strtolower(trim((string) $role));
    $aliases = array(
        'gm' => 'general manager',
        'general manager' => 'general manager',
        'dept manager' => 'department manager',
        'department manager' => 'department manager',
        'checker' => 'checked by',
        'check' => 'checked by',
        'checked by' => 'checked by',
        'applicant' => 'applicant',
    );

    return $aliases[$r] ?? $r;
}

/**
 * Whether the user is the named assignee for a voucher approval role.
 */
function userIsVoucherApprovalRoleAssignee(array $voucher, $roleKey, $userName, $userId = 0, $pdo = null): bool
{
    $roleKey = normalizeVoucherApprovalRoleKey($roleKey);
    $roleFieldMap = array(
        'applicant' => 'applicant',
        'department manager' => 'department_manager',
        'checked by' => 'checked_by',
    );
    if (!isset($roleFieldMap[$roleKey])) {
        return false;
    }
    $assignee = trim((string) ($voucher[$roleFieldMap[$roleKey]] ?? ''));
    $userName = trim((string) $userName);
    $userId = (int) $userId;

    if ($assignee === '') {
        return false;
    }
    if ($userName !== '' && strcasecmp($assignee, $userName) === 0) {
        return true;
    }
    if ($userName !== '' && function_exists('normalizePersonNameKey')) {
        if (normalizePersonNameKey($userName) === normalizePersonNameKey($assignee)) {
            return true;
        }
    }
    if ($userId > 0 && $pdo instanceof PDO) {
        $assigneeUserId = resolveVoucherUserIdByDisplayName($pdo, $assignee);
        if ($assigneeUserId > 0 && $assigneeUserId === $userId) {
            return true;
        }
    }

    return false;
}

/**
 * Employee approval roles that must be completed before GM final approval.
 *
 * @return list<string>
 */
function voucherEmployeeApprovalRoleKeys(): array
{
    return array('applicant', 'department manager', 'checked by');
}

/**
 * True when Applicant, Department Manager, and Checked By are all approved.
 */
function voucherCoreApprovalRolesComplete(PDO $pdo, $voucherId, array $voucher = array()): bool
{
    $voucherId = (int) $voucherId;
    if ($voucherId <= 0 || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return false;
    }

    $required = array(
        'applicant' => trim((string) ($voucher['applicant'] ?? '')),
        'department manager' => trim((string) ($voucher['department_manager'] ?? '')),
        'checked by' => trim((string) ($voucher['checked_by'] ?? '')),
    );

    try {
        $st = $pdo->prepare('SELECT role, status FROM approvals WHERE voucher_id = ?');
        $st->execute(array($voucherId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return false;
    }

    $approvedByRole = array();
    foreach ($rows as $row) {
        $roleKey = normalizeVoucherApprovalRoleKey($row['role'] ?? '');
        if (strtolower((string) ($row['status'] ?? '')) === 'approved') {
            $approvedByRole[$roleKey] = true;
        }
    }

    foreach ($required as $roleKey => $assigneeName) {
        if ($assigneeName === '') {
            continue;
        }
        if (empty($approvedByRole[$roleKey])) {
            return false;
        }
    }

    return true;
}

/**
 * Pending approvals excluding General Manager (GM is finalized separately).
 */
function countPendingEmployeeApprovalRoles(PDO $pdo, $voucherId): int
{
    $voucherId = (int) $voucherId;
    if ($voucherId <= 0 || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM approvals WHERE voucher_id = ? AND status <> 'approved'
             AND LOWER(TRIM(role)) NOT IN ('general manager', 'gm')"
        );
        $st->execute(array($voucherId));
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Display name recorded as General Manager when an admin finalizes a voucher.
 */
function resolveVoucherApproverGeneralManagerName(PDO $pdo, int $userId): string
{
    $userId = (int) $userId;
    if ($userId <= 0 || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'users')) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT username, email, full_name FROM users WHERE id = ? LIMIT 1');
        $st->execute(array($userId));
        $approver = $st->fetch(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return '';
    }

    $approverEmail = strtolower(trim((string) ($approver['email'] ?? '')));
    $approverUsername = trim((string) ($approver['username'] ?? ''));
    $approverFullName = trim((string) ($approver['full_name'] ?? ''));

    if ($approverEmail === 'rajabmwanyika@gmail.com') {
        return 'RAJABU MWANYIKA';
    }
    if ($approverEmail === 'rajabmsomali@gmail.com') {
        return $approverFullName !== '' ? $approverFullName : $approverUsername;
    }

    return $approverFullName !== '' ? $approverFullName : $approverUsername;
}

/**
 * Whether the logged-in admin may complete the General Manager approval step.
 */
function userCanVoucherGeneralManagerApprove(PDO $pdo, array $voucher, int $userId): bool
{
    $userId = (int) $userId;
    if ($userId <= 0 || !function_exists('isAdmin') || !isAdmin()) {
        return false;
    }

    $status = strtolower(trim((string) ($voucher['status'] ?? '')));
    if (in_array($status, array('approved', 'rejected', 'posted', 'paid'), true)) {
        return false;
    }

    $voucherId = (int) ($voucher['id'] ?? 0);
    if ($voucherId <= 0) {
        return false;
    }

    try {
        $st = $pdo->prepare(
            "SELECT status FROM approvals WHERE voucher_id = ? AND LOWER(TRIM(role)) IN ('general manager', 'gm') ORDER BY id DESC LIMIT 1"
        );
        $st->execute(array($voucherId));
        $gmStatus = strtolower(trim((string) ($st->fetchColumn() ?: '')));
        if ($gmStatus === 'approved') {
            return false;
        }
    } catch (Throwable $e) {
    }

    return voucherCoreApprovalRolesComplete($pdo, $voucherId, $voucher);
}

/**
 * Ensure a pending General Manager approvals row exists for final sign-off.
 */
function ensureVoucherGeneralManagerPendingApproval(PDO $pdo, $voucherId, string $gmName, int $userId = 0): int
{
    $voucherId = (int) $voucherId;
    $gmName = trim($gmName);
    $userId = (int) $userId;
    if ($voucherId <= 0 || $gmName === '' || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return 0;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, status FROM approvals WHERE voucher_id = ? AND LOWER(TRIM(role)) IN ('general manager', 'gm') ORDER BY id DESC LIMIT 1"
        );
        $st->execute(array($voucherId));
        $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $existingId = (int) ($existing['id'] ?? 0);
            if ($existingId > 0 && strtolower((string) ($existing['status'] ?? '')) === 'pending') {
                $approverId = $userId > 0 ? $userId : null;
                $pdo->prepare('UPDATE approvals SET approver_id = ?, approver_name = ? WHERE id = ?')
                    ->execute(array($approverId, $gmName, $existingId));
                return $existingId;
            }
            if (strtolower((string) ($existing['status'] ?? '')) === 'approved') {
                return 0;
            }
        }

        $apprCols = $pdo->query('SHOW COLUMNS FROM approvals')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        $hasCompanyId = in_array('company_id', $apprCols, true);
        $approverId = $userId > 0 ? $userId : null;
        if ($approverId === null && function_exists('resolveVoucherUserIdByDisplayName')) {
            $resolved = (int) resolveVoucherUserIdByDisplayName($pdo, $gmName);
            $approverId = $resolved > 0 ? $resolved : null;
        }

        $cols = array('voucher_id', 'approver_id', 'approver_name', 'role', 'status', 'created_at');
        $places = array('?', '?', '?', '?', "'pending'", 'NOW()');
        $vals = array($voucherId, $approverId, $gmName, 'General Manager');
        if ($hasCompanyId) {
            $cId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
            if ($cId > 0) {
                $cols[] = 'company_id';
                $places[] = '?';
                $vals[] = $cId;
            }
        }
        $pdo->prepare('INSERT INTO approvals (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $places) . ')')
            ->execute($vals);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('ensureVoucherGeneralManagerPendingApproval voucher ' . $voucherId . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Pending approval rows the current user may action on a voucher.
 *
 * Uses payment_vouchers assignee names as source of truth (applicant / dept mgr / checked by).
 * Syncs approvals rows first, then returns only roles the user is actually assigned to.
 *
 * @return list<array{id: int, role: string, approver_name: string}>
 */
function getUserPendingVoucherApprovals(PDO $pdo, $voucherId, $userId, $userName, array $voucher = array())
{
    $voucherId = (int) $voucherId;
    $userId = (int) $userId;
    $userName = trim((string) $userName);
    if ($userName === '' && function_exists('resolveVoucherSessionDisplayName')) {
        $userName = resolveVoucherSessionDisplayName($pdo);
    }
    if ($voucherId <= 0 || ($userName === '' && $userId <= 0) || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return array();
    }

    if (!empty($voucher) && function_exists('syncVoucherApprovalAssignees')) {
        syncVoucherApprovalAssignees($pdo, $voucherId, array(
            'Applicant' => trim((string) ($voucher['applicant'] ?? '')),
            'Department Manager' => trim((string) ($voucher['department_manager'] ?? '')),
            'Checked By' => trim((string) ($voucher['checked_by'] ?? '')),
        ));
    }

    $roleFieldMap = array(
        'applicant' => 'applicant',
        'department manager' => 'department_manager',
        'checked by' => 'checked_by',
    );

    try {
        $st = $pdo->prepare(
            'SELECT id, role, approver_name, approver_id FROM approvals WHERE voucher_id = ? AND status = ? ORDER BY id ASC'
        );
        $st->execute(array($voucherId, 'pending'));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return array();
    }

    $out = array();
    foreach ($rows as $row) {
        $roleKey = normalizeVoucherApprovalRoleKey($row['role'] ?? '');
        if (!isset($roleFieldMap[$roleKey])) {
            continue;
        }
        $isAssignee = userIsVoucherApprovalRoleAssignee($voucher, $roleKey, $userName, $userId, $pdo);
        if (!$isAssignee && $userId > 0 && (int) ($row['approver_id'] ?? 0) === $userId) {
            $isAssignee = true;
        }
        if (!$isAssignee) {
            continue;
        }

        $rowId = resolveVoucherApprovalRowId($pdo, $voucherId, $row);
        $approverId = (int) ($row['approver_id'] ?? 0);
        $approverName = trim((string) ($row['approver_name'] ?? ''));
        $syncName = $userName !== '' ? $userName : $approverName;
        if ($rowId > 0 && ($approverId !== $userId || ($syncName !== '' && strcasecmp($approverName, $syncName) !== 0))) {
            try {
                $pdo->prepare('UPDATE approvals SET approver_id = ?, approver_name = ? WHERE id = ?')
                    ->execute(array($userId > 0 ? $userId : null, $syncName, $rowId));
            } catch (Throwable $e) {
                /* non-fatal */
            }
        }

        $out[] = array(
            'id' => $rowId,
            'role' => (string) ($row['role'] ?? ''),
            'role_key' => $roleKey,
            'approver_name' => $syncName,
        );
    }

    if (!empty($voucher) && userCanVoucherGeneralManagerApprove($pdo, $voucher, $userId)) {
        $gmName = resolveVoucherApproverGeneralManagerName($pdo, $userId);
        if ($gmName === '' && $userName !== '') {
            $gmName = $userName;
        }
        if ($gmName !== '') {
            $gmRowId = ensureVoucherGeneralManagerPendingApproval($pdo, $voucherId, $gmName, $userId);
            $out[] = array(
                'id' => $gmRowId,
                'role' => 'General Manager',
                'role_key' => 'general manager',
                'approver_name' => $gmName,
                'is_final_approval' => true,
            );
        }
    }

    return $out;
}

/**
 * Record General Manager approval when voucher is finalized (GM is not in create-time approvals).
 */
function erp_upsert_general_manager_approval(PDO $pdo, $voucherId, $gmName, $approverUserId = null)
{
    $voucherId = (int) $voucherId;
    $gmName = trim((string) $gmName);
    $approverUserId = (int) $approverUserId;
    if ($voucherId <= 0 || $gmName === '' || !($pdo instanceof PDO) || !erp_connection_has_table($pdo, 'approvals')) {
        return;
    }
    try {
        $apprCols = $pdo->query('SHOW COLUMNS FROM approvals')->fetchAll(PDO::FETCH_COLUMN) ?: array();
        $hasCompanyId = in_array('company_id', $apprCols, true);
        $hasApprovedAt = in_array('approved_at', $apprCols, true);
        $cId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;

        $findSql = "SELECT id, status FROM approvals WHERE voucher_id = ? AND LOWER(TRIM(role)) IN ('general manager', 'gm')";
        $findParams = array($voucherId);
        if ($hasCompanyId && $cId > 0) {
            $findSql .= ' AND company_id = ?';
            $findParams[] = $cId;
        }
        $findSql .= ' ORDER BY id ASC LIMIT 1';
        $find = $pdo->prepare($findSql);
        $find->execute($findParams);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        $approverId = $approverUserId > 0 ? $approverUserId : null;
        if ($approverId === null && function_exists('resolveVoucherUserIdByDisplayName')) {
            $resolved = (int) resolveVoucherUserIdByDisplayName($pdo, $gmName);
            $approverId = $resolved > 0 ? $resolved : null;
        }

        if ($existing) {
            if (strtolower((string) ($existing['status'] ?? '')) !== 'approved') {
                $updSql = "UPDATE approvals SET status = 'approved', approver_name = ?, approver_id = COALESCE(?, approver_id)";
                $updParams = array($gmName, $approverId);
                if ($hasApprovedAt) {
                    $updSql .= ', approved_at = NOW()';
                }
                $updSql .= ' WHERE id = ?';
                $updParams[] = (int) $existing['id'];
                $pdo->prepare($updSql)->execute($updParams);
            }
            return;
        }

        $cols = array('voucher_id', 'approver_id', 'approver_name', 'role', 'status', 'created_at');
        $places = array('?', '?', '?', '?', "'approved'", 'NOW()');
        $vals = array($voucherId, $approverId, $gmName, 'General Manager');
        if ($hasApprovedAt) {
            $cols[] = 'approved_at';
            $places[] = 'NOW()';
        }
        if ($hasCompanyId && $cId > 0) {
            $cols[] = 'company_id';
            $places[] = '?';
            $vals[] = $cId;
        }
        $pdo->prepare('INSERT INTO approvals (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $places) . ')')->execute($vals);
    } catch (Throwable $e) {
        error_log('erp_upsert_general_manager_approval voucher ' . $voucherId . ': ' . $e->getMessage());
    }
}

function applyDefaultTenantDbHosts($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo) || !columnExists('companies', 'db_host', $usePdo)) {
        return;
    }
    try {
        $roadmasterHost = '';
        if (isset($GLOBALS['ROADMASTER_DB_HOST']) && trim((string) $GLOBALS['ROADMASTER_DB_HOST']) !== '') {
            $roadmasterHost = trim((string) $GLOBALS['ROADMASTER_DB_HOST']);
        } else {
            $roadmasterHost = 'sdb-83.hosting.stackcp.net';
        }
        // Local/dev: force Roadmaster onto the imported local DB host (ignore StackCP remote host).
        $forceLocalRoadmaster = function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants();
        if ($forceLocalRoadmaster) {
            $localRoadmasterHost = (isset($GLOBALS['ROADMASTER_DB_HOST']) && trim((string) $GLOBALS['ROADMASTER_DB_HOST']) !== '')
                ? trim((string) $GLOBALS['ROADMASTER_DB_HOST'])
                : (defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1');
            $st = $usePdo->prepare(
                "UPDATE companies SET db_host = ? WHERE company_slug = 'roadmaster'"
            );
            $st->execute(array($localRoadmasterHost));
        } else {
            $st = $usePdo->prepare(
                "UPDATE companies SET db_host = ? WHERE company_slug = 'roadmaster' AND (db_host IS NULL OR TRIM(db_host) = '')"
            );
            $st->execute(array($roadmasterHost));
        }

        if (isset($GLOBALS['ROADMASTER_DB_NAME']) && trim((string) $GLOBALS['ROADMASTER_DB_NAME']) !== '') {
            if ($forceLocalRoadmaster) {
                $stDb = $usePdo->prepare(
                    "UPDATE companies SET db_name = ? WHERE company_slug = 'roadmaster'"
                );
                $stDb->execute(array(trim((string) $GLOBALS['ROADMASTER_DB_NAME'])));
            } else {
                $stDb = $usePdo->prepare(
                    "UPDATE companies SET db_name = ? WHERE company_slug = 'roadmaster' AND (db_name IS NULL OR TRIM(db_name) = '')"
                );
                $stDb->execute(array(trim((string) $GLOBALS['ROADMASTER_DB_NAME'])));
            }
        }
    } catch (Throwable $e) {
        error_log('applyDefaultTenantDbHosts: ' . $e->getMessage());
    }
}

function seedDefaultCompaniesIfEmpty($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo)) {
        return;
    }
    try {
        $count = (int) $usePdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        if ($count > 0) {
            applyDefaultTenantDbHosts($usePdo);
            return;
        }
        $controlDb = defined('DB_NAME') ? (string) DB_NAME : '';
        $hasDbHost = columnExists('companies', 'db_host', $usePdo);
        $defaults = array(
            array('Ultimate General Trading', 'ultimate', $controlDb !== '' ? $controlDb : null, null),
            array('Roadmaster', 'roadmaster', 'roadmaster_db-3530393454a2', 'sdb-83.hosting.stackcp.net'),
        );
        if ($hasDbHost) {
            $stmt = $usePdo->prepare(
                'INSERT INTO companies (company_name, company_slug, db_name, db_host, subdomain, status, timezone, base_currency)
                 VALUES (?, ?, ?, ?, ?, \'active\', \'Africa/Dar_es_Salaam\', \'TZS\')'
            );
            foreach ($defaults as $row) {
                $stmt->execute(array($row[0], $row[1], $row[2], $row[3], $row[1]));
            }
        } else {
            $stmt = $usePdo->prepare(
                'INSERT INTO companies (company_name, company_slug, db_name, subdomain, status, timezone, base_currency)
                 VALUES (?, ?, ?, ?, \'active\', \'Africa/Dar_es_Salaam\', \'TZS\')'
            );
            foreach ($defaults as $row) {
                $stmt->execute(array($row[0], $row[1], $row[2], $row[1]));
            }
        }
        applyDefaultTenantDbHosts($usePdo);
    } catch (Throwable $e) {
        error_log('seedDefaultCompaniesIfEmpty: ' . $e->getMessage());
    }
}

function repairLegacyAdminPasswordHash($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('users', $usePdo)) {
        return;
    }
    $legacyBadHashes = array(
        '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyqlVpmoPciYBk7H.Umh4GXY6vWQa',
        '$2y$10$TKh8H1.PfQx37YgCzwiKb.rYJayLcbd5sR4vzjV91TsG3J6mYj7UG',
    );
    try {
        $st = $usePdo->prepare('SELECT id, password, company_id FROM users WHERE email = ? OR username = ? ORDER BY id ASC LIMIT 1');
        $st->execute(array('admin@ultimatetrading.com', 'admin'));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $storedHash = (string) ($row['password'] ?? '');
        if (!in_array($storedHash, $legacyBadHashes, true)) {
            return;
        }
        $newHash = password_hash('admin123', PASSWORD_DEFAULT);
        $usePdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute(array($newHash, (int) $row['id']));

        if (tableExists('companies', $usePdo) && columnExists('users', 'company_id', $usePdo)) {
            $co = $usePdo->query("SELECT id FROM companies WHERE company_slug = 'ultimate' ORDER BY id ASC LIMIT 1");
            $ultimateId = (int) ($co->fetchColumn() ?: 0);
            if ($ultimateId > 0 && (int) ($row['company_id'] ?? 0) !== $ultimateId) {
                $usePdo->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute(array($ultimateId, (int) $row['id']));
            }
        }
    } catch (Throwable $e) {
        error_log('repairLegacyAdminPasswordHash: ' . $e->getMessage());
    }
}

function seedDefaultAdminUserIfEmpty($explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('users', $usePdo)) {
        return;
    }
    try {
        $count = (int) $usePdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            repairLegacyAdminPasswordHash($usePdo);
            return;
        }
        $companyId = 1;
        if (tableExists('companies', $usePdo)) {
            $st = $usePdo->query("SELECT id FROM companies WHERE company_slug = 'ultimate' ORDER BY id ASC LIMIT 1");
            $companyId = (int) ($st->fetchColumn() ?: 1);
            if ($companyId <= 0) {
                $companyId = 1;
            }
        }
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $sql = 'INSERT INTO users (username, password, full_name, email, role, department, company_id, is_active, status, approval_status)
                VALUES (?, ?, ?, ?, \'admin\', \'Management\', ?, 1, \'active\', \'approved\')';
        $usePdo->prepare($sql)->execute(array('admin', $hash, 'System Administrator', 'admin@ultimatetrading.com', $companyId));
    } catch (Throwable $e) {
        error_log('seedDefaultAdminUserIfEmpty: ' . $e->getMessage());
    }
}

function ensureMultiCompanyControlSchema()
{
    global $pdo, $control_pdo;
    $GLOBALS['ultitech_last_schema_error'] = '';
    $usePdo = $control_pdo ?? $pdo;
    if (!($usePdo instanceof PDO)) {
        $GLOBALS['ultitech_last_schema_error'] = 'No control PDO connection';
        return false;
    }

    static $ranThisRequest = false;
    if ($ranThisRequest && tableExists('companies', $usePdo) && tableExists('users', $usePdo)) {
        return true;
    }

    $ok = true;
    $markFailure = function ($step, $e) use (&$ok) {
        $ok = false;
        $msg = $step . ': ' . $e->getMessage();
        $GLOBALS['ultitech_last_schema_error'] = $msg;
        error_log('ensureMultiCompanyControlSchema ' . $msg);
    };
    try {
        ensureCompanySettingsKeyValueSchema($usePdo);
    } catch (Throwable $e) {
        $markFailure('ensureCompanySettingsKeyValueSchema', $e);
    }

    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(200) NULL,
                logo VARCHAR(255) NULL,
                domain VARCHAR(150) NULL,
                subdomain VARCHAR(100) NULL,
                company_slug VARCHAR(160) NULL,
                db_name VARCHAR(100) NULL,
                db_host VARCHAR(150) NULL,
                db_user VARCHAR(100) NULL,
                db_pass VARCHAR(255) NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                setup_status VARCHAR(30) NOT NULL DEFAULT 'active',
                invite_code VARCHAR(40) NULL,
                employee_registration_mode VARCHAR(30) NOT NULL DEFAULT 'admin_only',
                allow_employee_self_registration TINYINT(1) NOT NULL DEFAULT 0,
                require_admin_approval_for_new_users TINYINT(1) NOT NULL DEFAULT 1,
                timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Dar_es_Salaam',
                base_currency VARCHAR(10) NOT NULL DEFAULT 'TZS',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_companies_domain (domain),
                UNIQUE KEY uq_companies_subdomain (subdomain),
                UNIQUE KEY uq_companies_slug (company_slug),
                KEY idx_companies_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        $markFailure('CREATE companies', $e);
    }

    try {
        ensureCompaniesTableColumns($usePdo);
    } catch (Throwable $e) {
        $markFailure('ensureCompaniesTableColumns', $e);
    }

    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS company_settings (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_settings_key (company_id, setting_key),
                KEY idx_company_settings_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        if (tableExists('companies', $usePdo)) {
            try {
                $usePdo->exec("
                    ALTER TABLE company_settings
                    ADD CONSTRAINT fk_company_settings_company
                    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
                ");
            } catch (Throwable $eFk) {
                // FK optional on restricted hosts.
            }
        }
    } catch (Throwable $e) {
        $markFailure('CREATE company_settings', $e);
    }

    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS company_modules (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                module_key VARCHAR(80) NOT NULL,
                module_name VARCHAR(120) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                custom_label VARCHAR(120) NULL,
                settings_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_modules_key (company_id, module_key),
                KEY idx_company_modules_enabled (company_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        $markFailure('CREATE company_modules', $e);
    }

    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS document_sequences (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                document_type VARCHAR(60) NOT NULL,
                prefix VARCHAR(50) NOT NULL,
                next_number BIGINT NOT NULL DEFAULT 1,
                padding INT NOT NULL DEFAULT 3,
                year INT NOT NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_sequences_type_year (company_id, document_type, year),
                KEY idx_document_sequences_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        $markFailure('CREATE document_sequences', $e);
    }

    if (!ensureUsersControlTable($usePdo)) {
        $ok = false;
        if ($GLOBALS['ultitech_last_schema_error'] === '') {
            $GLOBALS['ultitech_last_schema_error'] = 'CREATE users failed (see error_log)';
        }
    }

    try {
        if (tableExists('users', $usePdo)) {
            if (!columnExists('users', 'company_id', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN company_id INT NULL AFTER id");
            }
            if (!columnExists('users', 'status', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER role");
            }
            if (!columnExists('users', 'approval_status', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER status");
            }
            if (!columnExists('users', 'created_by', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN created_by INT NULL AFTER company_id");
            }
            if (!columnExists('users', 'phone', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
            }
            if (!columnExists('users', 'is_active', $usePdo)) {
                $usePdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
            }

            try {
                $stmt = $usePdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' LIMIT 1");
                if (strtolower((string) $stmt->fetchColumn()) === 'enum') {
                    $usePdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'employee'");
                }
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        $markFailure('ALTER users', $e);
    }

    try {
        seedDefaultCompaniesIfEmpty($usePdo);
        applyDefaultTenantDbHosts($usePdo);
        seedDefaultAdminUserIfEmpty($usePdo);
        repairLegacyAdminPasswordHash($usePdo);
    } catch (Throwable $e) {
        $markFailure('seed defaults', $e);
    }

    try {
        ensureUserCompanyIndexSchema($usePdo);
    } catch (Throwable $e) {
        $markFailure('ensureUserCompanyIndexSchema', $e);
    }

    $ranThisRequest = true;
    if ($ok && !tableExists('companies', $usePdo)) {
        $ok = false;
        $GLOBALS['ultitech_last_schema_error'] = 'companies table still missing after migration';
    }

    if ($ok && function_exists('maybeAutoSyncUserCompanyIndex')) {
        maybeAutoSyncUserCompanyIndex(false);
    }

    return $ok;
}

function getLastControlSchemaError()
{
    return (string) ($GLOBALS['ultitech_last_schema_error'] ?? '');
}

function findCompanyBySlug(string $slug)
{
    global $pdo, $control_pdo;
    ensureMultiCompanyControlSchema();
    $usePdo = $control_pdo ?? $pdo;
    try {
        $stmt = $usePdo->prepare("SELECT * FROM companies WHERE company_slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve an active company's public slug from its control-plane id.
 */
function resolveCompanySlugById(int $companyId): string
{
    if ($companyId <= 0) {
        return '';
    }
    global $control_pdo, $pdo;
    $usePdo = $control_pdo ?? $pdo;
    if (!tableExists('companies', $usePdo) || !columnExists('companies', 'company_slug', $usePdo)) {
        return '';
    }
    try {
        $stmt = $usePdo->prepare("SELECT company_slug FROM companies WHERE id = ? AND LOWER(TRIM(status)) = 'active' LIMIT 1");
        $stmt->execute([$companyId]);
        return strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Normalize email for index storage and lookup (lowercase, trimmed).
 */
function normalizeLoginEmail($email): string
{
    return strtolower(trim((string) $email));
}

function ensureUserCompanyIndexSchema($explicitPdo = null): bool
{
    global $control_pdo, $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    if (!($usePdo instanceof PDO)) {
        return false;
    }
    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS user_company_index (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                company_slug VARCHAR(100) NOT NULL,
                tenant_db_name VARCHAR(150) NOT NULL,
                tenant_db_host VARCHAR(150) DEFAULT 'localhost',
                tenant_user_id INT NULL,
                email VARCHAR(190) NULL,
                username VARCHAR(100) NULL,
                role VARCHAR(50) NULL,
                status ENUM('active','inactive','pending','blocked') NOT NULL DEFAULT 'active',
                source ENUM('control','tenant') NOT NULL DEFAULT 'tenant',
                last_synced_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_company_index_email (email),
                KEY idx_user_company_index_company_id (company_id),
                KEY idx_user_company_index_company_slug (company_slug),
                KEY idx_user_company_index_username (username),
                KEY idx_user_company_index_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        error_log('ensureUserCompanyIndexSchema: ' . $e->getMessage());
        return false;
    }
}

function userCompanyIndexTableReady($explicitPdo = null): bool
{
    global $control_pdo, $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    return ($usePdo instanceof PDO) && tableExists('user_company_index', $usePdo);
}

function userCompanyIndexIsEmpty($explicitPdo = null): bool
{
    if (!userCompanyIndexTableReady($explicitPdo)) {
        return true;
    }
    global $control_pdo, $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    try {
        return ((int) $usePdo->query('SELECT COUNT(*) FROM user_company_index')->fetchColumn()) === 0;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * True only when index is empty (bootstrap) — avoids scanning all tenant DBs on every login.
 */
function shouldUseLoginIndexTenantFallback($explicitPdo = null): bool
{
    return userCompanyIndexIsEmpty($explicitPdo);
}

/**
 * Run full tenant → index sync automatically when the index is empty (once per request).
 * Pass $force = true to rebuild even when rows already exist (manual repair).
 *
 * @return array|null sync summary from syncAllTenantUsersToIndex()
 */
function maybeAutoSyncUserCompanyIndex($force = false)
{
    static $autoAttempted = false;
    if ($autoAttempted && !$force) {
        return null;
    }

    if (!function_exists('syncAllTenantUsersToIndex')) {
        return null;
    }

    ensureUserCompanyIndexSchema();
    if (!userCompanyIndexTableReady()) {
        return null;
    }

    if (!$force && !userCompanyIndexIsEmpty()) {
        return null;
    }

    if (!$force) {
        $autoAttempted = true;
    }

    try {
        $summary = syncAllTenantUsersToIndex();
        error_log(
            'maybeAutoSyncUserCompanyIndex: synced='
            . (int) ($summary['total_synced'] ?? 0)
            . ' errors='
            . (int) ($summary['total_errors'] ?? 0)
        );
        return $summary;
    } catch (Throwable $e) {
        error_log('maybeAutoSyncUserCompanyIndex: ' . $e->getMessage());
        return null;
    }
}

function getCompanyById(int $companyId, $explicitPdo = null)
{
    if ($companyId <= 0) {
        return null;
    }
    global $control_pdo, $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo)) {
        return null;
    }
    try {
        $stmt = $usePdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute(array($companyId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function validateNewUserEmailForIndex(string $email)
{
    $email = normalizeLoginEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (emailExistsInUserCompanyIndex($email)) {
        return 'This email is already registered. Please use another email.';
    }
    return null;
}

function emailExistsInUserCompanyIndex(string $email, int $excludeIndexId = 0): bool
{
    $email = normalizeLoginEmail($email);
    if ($email === '' || !userCompanyIndexTableReady()) {
        return false;
    }
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    try {
        $sql = 'SELECT id FROM user_company_index WHERE email = ?';
        $params = array($email);
        if ($excludeIndexId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeIndexId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $usePdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mapTenantUserRowToIndexStatus(array $userRow, $pdo = null): string
{
    if ($pdo instanceof PDO && columnExists('users', 'is_active', $pdo) && isset($userRow['is_active']) && (int) $userRow['is_active'] === 0) {
        return 'inactive';
    }
    if (!($pdo instanceof PDO) && isset($userRow['is_active']) && (int) $userRow['is_active'] === 0) {
        return 'inactive';
    }
    $approval = strtolower(trim((string) ($userRow['approval_status'] ?? '')));
    if ($approval === 'pending') {
        return 'pending';
    }
    if ($approval === 'rejected' || $approval === 'blocked') {
        return 'blocked';
    }
    $status = strtolower(trim((string) ($userRow['status'] ?? '')));
    if ($status === 'pending') {
        return 'pending';
    }
    if (in_array($status, array('inactive', 'blocked', 'disabled'), true)) {
        return 'inactive';
    }
    return 'active';
}

function fetchCompanyLogoPathForIndex(int $companyId, $explicitPdo = null): string
{
    global $control_pdo, $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    if (!($usePdo instanceof PDO) || $companyId <= 0 || !tableExists('company_settings', $usePdo)) {
        return '';
    }
    try {
        $stmt = $usePdo->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'company_logo' LIMIT 1");
        $stmt->execute(array($companyId));
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Lookup login routing row by email (preferred) or username (fallback). Does not verify password.
 */
function findLoginCompanyFromIndex(string $identifier, bool $activeOnly = true)
{
    $identifier = trim($identifier);
    if ($identifier === '' || !userCompanyIndexTableReady()) {
        return null;
    }

    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;

    $row = null;
    try {
        if (strpos($identifier, '@') !== false) {
            $email = normalizeLoginEmail($identifier);
            $sql = 'SELECT i.*, c.company_name FROM user_company_index i
                    LEFT JOIN companies c ON c.id = i.company_id
                    WHERE i.email = ?';
            $params = array($email);
            if ($activeOnly) {
                $sql .= " AND i.status = 'active'";
            }
            $sql .= ' LIMIT 1';
            $stmt = $usePdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$row) {
            $sql = 'SELECT i.*, c.company_name FROM user_company_index i
                    LEFT JOIN companies c ON c.id = i.company_id
                    WHERE LOWER(TRIM(i.username)) = LOWER(TRIM(?))';
            $params = array($identifier);
            if ($activeOnly) {
                $sql .= " AND i.status = 'active'";
            }
            $sql .= ' LIMIT 1';
            $stmt = $usePdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        error_log('findLoginCompanyFromIndex: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }

    $row['logo_path'] = fetchCompanyLogoPathForIndex((int) ($row['company_id'] ?? 0), $usePdo);
    return $row;
}

function upsertUserCompanyIndexRow(array $data): bool
{
    if (!userCompanyIndexTableReady()) {
        ensureUserCompanyIndexSchema();
    }
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;

    $email = normalizeLoginEmail($data['email'] ?? '');
    if ($email === '') {
        return false;
    }

    $companyId = (int) ($data['company_id'] ?? 0);
    $companySlug = strtolower(trim((string) ($data['company_slug'] ?? '')));
    if ($companyId <= 0 || $companySlug === '') {
        return false;
    }

    try {
        $stmt = $usePdo->prepare("
            INSERT INTO user_company_index (
                company_id, company_slug, tenant_db_name, tenant_db_host, tenant_user_id,
                email, username, role, status, source, last_synced_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                company_slug = VALUES(company_slug),
                tenant_db_name = VALUES(tenant_db_name),
                tenant_db_host = VALUES(tenant_db_host),
                tenant_user_id = VALUES(tenant_user_id),
                username = VALUES(username),
                role = VALUES(role),
                status = VALUES(status),
                source = VALUES(source),
                last_synced_at = NOW()
        ");
        return $stmt->execute(array(
            $companyId,
            $companySlug,
            trim((string) ($data['tenant_db_name'] ?? '')),
            trim((string) ($data['tenant_db_host'] ?? 'localhost')) !== '' ? trim((string) $data['tenant_db_host']) : 'localhost',
            ($data['tenant_user_id'] ?? null) !== null ? (int) $data['tenant_user_id'] : null,
            $email,
            trim((string) ($data['username'] ?? '')) !== '' ? trim((string) $data['username']) : null,
            trim((string) ($data['role'] ?? '')) !== '' ? trim((string) $data['role']) : null,
            trim((string) ($data['status'] ?? 'active')),
            trim((string) ($data['source'] ?? 'tenant')),
        ));
    } catch (Throwable $e) {
        error_log('upsertUserCompanyIndexRow: ' . $e->getMessage());
        return false;
    }
}

function syncUserCompanyIndex(int $companyId, int $tenantUserId): bool
{
    if ($companyId <= 0 || $tenantUserId <= 0) {
        return false;
    }
    ensureUserCompanyIndexSchema();

    $company = getCompanyById($companyId);
    if (!$company) {
        return false;
    }

    $controlDbName = defined('DB_NAME') ? trim((string) DB_NAME) : '';
    $tenantDb = trim((string) ($company['db_name'] ?? ''));
    $tenantHost = trim((string) ($company['db_host'] ?? ''));
    $tenantUser = trim((string) ($company['db_user'] ?? ''));
    $tenantPass = null;
    if (array_key_exists('db_pass', $company)) {
        $raw = (string) ($company['db_pass'] ?? '');
        $tenantPass = trim($raw) !== '' ? $raw : null;
    }

    $userRow = null;
    $source = 'tenant';
    $userSourcePdo = null;

    if ($tenantDb !== '' && ($controlDbName === '' || $tenantDb !== $controlDbName)) {
        $effectiveTenant = resolveEffectiveTenantDbConnection($tenantDb, $tenantHost, $tenantUser, $tenantPass);
        $tenantDb = $effectiveTenant['db_name'];
        $tenantHost = $effectiveTenant['host'];
        $tenantUser = $effectiveTenant['user'];
        $tenantPass = $effectiveTenant['pass'];
        $tenantPdo = connectToTenantDatabase($tenantDb, $tenantHost, $tenantUser, $tenantPass);
        if ($tenantPdo instanceof PDO && tableExists('users', $tenantPdo)) {
            $stmt = $tenantPdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute(array($tenantUserId));
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $userSourcePdo = $tenantPdo;
        }
    }

    if (!$userRow) {
        global $control_pdo, $pdo;
        $control = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
        if ($control instanceof PDO && tableExists('users', $control)) {
            $stmt = $control->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute(array($tenantUserId));
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $source = 'control';
            $userSourcePdo = $control;
        }
    }

    if (!$userRow) {
        return false;
    }

    $email = normalizeLoginEmail($userRow['email'] ?? '');
    if ($email === '') {
        return false;
    }

    return upsertUserCompanyIndexRow(array(
        'company_id' => $companyId,
        'company_slug' => strtolower(trim((string) ($company['company_slug'] ?? ''))),
        'tenant_db_name' => $tenantDb !== '' ? $tenantDb : $controlDbName,
        'tenant_db_host' => $tenantHost !== '' ? $tenantHost : (defined('DB_HOST') ? DB_HOST : 'localhost'),
        'tenant_user_id' => $tenantUserId,
        'email' => $email,
        'username' => $userRow['username'] ?? '',
        'role' => $userRow['role'] ?? 'employee',
        'status' => mapTenantUserRowToIndexStatus($userRow, $userSourcePdo),
        'source' => $source,
    ));
}

function removeUserCompanyIndex(int $companyId, int $tenantUserId, string $status = 'inactive'): bool
{
    if (!userCompanyIndexTableReady() || $companyId <= 0 || $tenantUserId <= 0) {
        return false;
    }
    $status = in_array($status, array('active', 'inactive', 'pending', 'blocked'), true) ? $status : 'inactive';
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    try {
        $stmt = $usePdo->prepare('UPDATE user_company_index SET status = ?, last_synced_at = NOW() WHERE company_id = ? AND tenant_user_id = ?');
        return $stmt->execute(array($status, $companyId, $tenantUserId));
    } catch (Throwable $e) {
        error_log('removeUserCompanyIndex: ' . $e->getMessage());
        return false;
    }
}

function removeUserCompanyIndexByEmail(string $email, string $status = 'inactive'): bool
{
    $email = normalizeLoginEmail($email);
    if ($email === '' || !userCompanyIndexTableReady()) {
        return false;
    }
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    try {
        $stmt = $usePdo->prepare('UPDATE user_company_index SET status = ?, last_synced_at = NOW() WHERE email = ?');
        return $stmt->execute(array($status, $email));
    } catch (Throwable $e) {
        return false;
    }
}

function syncAllTenantUsersToIndex(): array
{
    ensureUserCompanyIndexSchema();
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;

    $summary = array(
        'companies' => array(),
        'errors' => array(),
        'total_synced' => 0,
        'total_errors' => 0,
    );

    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo)) {
        $summary['errors'][] = 'Companies table not available.';
        return $summary;
    }

    $controlDbName = defined('DB_NAME') ? trim((string) DB_NAME) : '';
    $selectCols = 'id, company_name, company_slug, db_name';
    if (columnExists('companies', 'db_host', $usePdo)) {
        $selectCols .= ', db_host';
    }
    if (columnExists('companies', 'db_user', $usePdo)) {
        $selectCols .= ', db_user';
    }
    if (columnExists('companies', 'db_pass', $usePdo)) {
        $selectCols .= ', db_pass';
    }
    if (columnExists('companies', 'status', $usePdo)) {
        $selectCols .= ', status';
    }
    $sql = 'SELECT ' . $selectCols . " FROM companies WHERE TRIM(company_slug) <> ''";
    if (columnExists('companies', 'status', $usePdo)) {
        $sql .= " AND LOWER(TRIM(status)) = 'active'";
    }
    $companies = $usePdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();

    foreach ($companies as $company) {
        $companyId = (int) ($company['id'] ?? 0);
        $slug = strtolower(trim((string) ($company['company_slug'] ?? '')));
        $label = (string) ($company['company_name'] ?? $slug);
        $synced = 0;
        $errors = 0;

        $tenantDb = trim((string) ($company['db_name'] ?? ''));
        if ($tenantDb === '' || ($controlDbName !== '' && $tenantDb === $controlDbName)) {
            if (tableExists('users', $usePdo)) {
                $rows = $usePdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: array();
                foreach ($rows as $uid) {
                    if (syncUserCompanyIndex($companyId, (int) $uid)) {
                        $synced++;
                    } else {
                        $errors++;
                    }
                }
            }
        } else {
            $tenantHost = trim((string) ($company['db_host'] ?? ''));
            $tenantUser = trim((string) ($company['db_user'] ?? ''));
            $tenantPass = null;
            if (array_key_exists('db_pass', $company)) {
                $raw = (string) ($company['db_pass'] ?? '');
                $tenantPass = trim($raw) !== '' ? $raw : null;
            }
            $tenantPdo = connectToTenantDatabase($tenantDb, $tenantHost, $tenantUser, $tenantPass);
            if (!($tenantPdo instanceof PDO) || !tableExists('users', $tenantPdo)) {
                $summary['errors'][] = $label . ': tenant DB unreachable.';
                $errors++;
            } else {
                $users = $tenantPdo->query('SELECT id, email FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: array();
                foreach ($users as $u) {
                    $uid = (int) ($u['id'] ?? 0);
                    if ($uid <= 0 || normalizeLoginEmail($u['email'] ?? '') === '') {
                        $errors++;
                        continue;
                    }
                    if (syncUserCompanyIndex($companyId, $uid)) {
                        $synced++;
                    } else {
                        $errors++;
                        $summary['errors'][] = $label . ': failed user #' . $uid . ' (' . ($u['email'] ?? '') . ')';
                    }
                }
            }
        }

        $summary['companies'][$slug] = array('label' => $label, 'synced' => $synced, 'errors' => $errors);
        $summary['total_synced'] += $synced;
        $summary['total_errors'] += $errors;
    }

    return $summary;
}

function loginReconnectControlPdo(): bool
{
    global $pdo, $control_pdo;
    if (!defined('DB_NAME') || DB_NAME === '') {
        return false;
    }
    $useName = DB_NAME;
    $useUser = defined('DB_USER') ? DB_USER : 'root';
    $usePass = defined('DB_PASS') ? DB_PASS : '';
    $hosts = array();
    if (defined('DB_HOST') && DB_HOST !== '') {
        $hosts[] = DB_HOST;
    }
    $hosts[] = 'localhost';
    $hosts[] = '127.0.0.1';
    $hosts = array_values(array_unique($hosts));
    foreach ($hosts as $host) {
        try {
            $dsn = 'mysql:host=' . $host . ';dbname=' . $useName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $useUser, $usePass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("SET time_zone = '+03:00'");
            $control_pdo = $pdo;
            $GLOBALS['control_pdo'] = $pdo;
            $GLOBALS['pdo'] = $pdo;
            return true;
        } catch (Throwable $e) {
        }
    }
    return false;
}

function applyWinningCompanySession(string $slug, $controlPdo = null)
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return;
    }
    $usePdo = $controlPdo instanceof PDO ? $controlPdo : (($GLOBALS['control_pdo'] ?? null) instanceof PDO ? $GLOBALS['control_pdo'] : null);
    if (!($usePdo instanceof PDO)) {
        return;
    }
    try {
        $st = $usePdo->prepare('SELECT id, company_name, company_slug FROM companies WHERE company_slug = ? LIMIT 1');
        $st->execute(array($slug));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['company_id'] = (int) $row['id'];
            $_SESSION['company_name'] = (string) $row['company_name'];
            $_SESSION['company_slug'] = (string) $row['company_slug'];
        }
    } catch (Throwable $e) {
    }
}

/**
 * Indexed login: email-first lookup in user_company_index, single-tenant auth.
 */
function performIndexedLogin(string $identifier, string $password, string $submittedCompanySlug = ''): bool
{
    $identifier = trim($identifier);
    $submittedCompanySlug = strtolower(trim($submittedCompanySlug));
    if ($identifier === '' || $password === '') {
        return false;
    }

    ensureMultiCompanyControlSchema();
    if (function_exists('maybeAutoSyncUserCompanyIndex')) {
        maybeAutoSyncUserCompanyIndex(false);
    }
    global $control_pdo;
    $authSourcePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : null;

    if ($submittedCompanySlug !== '') {
        loginReconnectControlPdo();
        clearAuthSession();
        if (authenticate($identifier, $password, $submittedCompanySlug)) {
            applyWinningCompanySession($submittedCompanySlug, $authSourcePdo);
            return true;
        }
        return false;
    }

    $indexEntry = userCompanyIndexTableReady() ? findLoginCompanyFromIndex($identifier, true) : null;

    if ($indexEntry) {
        loginReconnectControlPdo();
        clearAuthSession();
        $slug = strtolower(trim((string) ($indexEntry['company_slug'] ?? '')));
        $source = strtolower(trim((string) ($indexEntry['source'] ?? 'tenant')));
        $ok = false;
        if ($source === 'control') {
            $ok = authenticate($identifier, $password, null);
        } elseif ($slug !== '') {
            $ok = authenticate($identifier, $password, $slug);
        }
        if ($ok) {
            if ($slug !== '') {
                applyWinningCompanySession($slug, $authSourcePdo);
            }
            if (!empty($indexEntry['email'])) {
                $_SESSION['email'] = normalizeLoginEmail($indexEntry['email']);
            }
            return true;
        }
        return false;
    }

    loginReconnectControlPdo();
    clearAuthSession();
    if (authenticate($identifier, $password, null)) {
        $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
        $privileged = array('admin', 'superadmin', 'super_admin', 'owner', 'system_admin', 'platform_admin');
        if (in_array($role, $privileged, true)) {
            $cid = (int) ($_SESSION['company_id'] ?? 0);
            if ($cid > 0) {
                $slug = resolveCompanySlugById($cid);
                if ($slug !== '') {
                    applyWinningCompanySession($slug, $authSourcePdo);
                }
            }
            return true;
        }
        clearAuthSession();
    }

    if (shouldUseLoginIndexTenantFallback()) {
        $preferredSlug = resolveLoginSlugFromIdentifierLegacy($identifier, $authSourcePdo);
        foreach (buildLoginAttemptSlugs($preferredSlug, $authSourcePdo) as $trySlug) {
            loginReconnectControlPdo();
            clearAuthSession();
            if (authenticate($identifier, $password, $trySlug)) {
                applyWinningCompanySession($trySlug, $authSourcePdo);
                return true;
            }
        }
    }

    return false;
}

/**
 * SQL WHERE fragment for active user lookup by username or email.
 */
function buildActiveUserIdentifierWhere(PDO $pdo, string $identifier, array &$params): string
{
    $identifier = trim($identifier);
    $parts = array('(username = ? OR email = ?)');
    $params = array($identifier, $identifier);
    if (columnExists('users', 'is_active', $pdo)) {
        $parts[] = 'is_active = 1';
    }
    if (columnExists('users', 'status', $pdo)) {
        $parts[] = "(status = 'active' OR status = '')";
    }
    if (columnExists('users', 'approval_status', $pdo)) {
        $parts[] = "(approval_status = 'approved' OR approval_status = 'active' OR approval_status = '')";
    }
    return implode(' AND ', $parts);
}

/**
 * Resolve company slug for login (index first; legacy tenant scan only when index is empty).
 */
function resolveLoginSlugFromIdentifier(string $identifier, $sourcePdo = null): string
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return '';
    }

    if (userCompanyIndexTableReady($sourcePdo) && !shouldUseLoginIndexTenantFallback($sourcePdo)) {
        $entry = findLoginCompanyFromIndex($identifier, true);
        if ($entry && !empty($entry['company_slug'])) {
            return strtolower(trim((string) $entry['company_slug']));
        }
    }

    return resolveLoginSlugFromIdentifierLegacy($identifier, $sourcePdo);
}

/**
 * Legacy resolver: control DB then tenant scan (repair / empty-index bootstrap only).
 */
function resolveLoginSlugFromIdentifierLegacy(string $identifier, $sourcePdo = null): string
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return '';
    }

    global $control_pdo, $pdo;
    $usePdo = $sourcePdo instanceof PDO
        ? $sourcePdo
        : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);

    if ($usePdo instanceof PDO && tableExists('users', $usePdo) && tableExists('companies', $usePdo)) {
        try {
            if (columnExists('users', 'company_id', $usePdo) && columnExists('companies', 'company_slug', $usePdo)) {
                $params = array();
                $where = buildActiveUserIdentifierWhere($usePdo, $identifier, $params);
                $sql = 'SELECT company_id FROM users WHERE ' . $where . ' ORDER BY id DESC LIMIT 1';
                $stmt = $usePdo->prepare($sql);
                $stmt->execute($params);
                $companyId = (int) ($stmt->fetchColumn() ?: 0);
                if ($companyId > 0) {
                    $slug = resolveCompanySlugById($companyId);
                    if ($slug !== '') {
                        return $slug;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('resolveLoginSlugFromIdentifierLegacy(control): ' . $e->getMessage());
        }
    }

    return resolveLoginSlugFromTenantDatabases($identifier, $usePdo);
}

/**
 * Scan each active company's tenant database for a matching user.
 */
function resolveLoginSlugFromTenantDatabases(string $identifier, $controlPdo = null): string
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return '';
    }

    global $control_pdo, $pdo;
    $usePdo = $controlPdo instanceof PDO
        ? $controlPdo
        : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);

    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo) || !columnExists('companies', 'company_slug', $usePdo)) {
        return '';
    }

    $controlDbName = defined('DB_NAME') ? trim((string) DB_NAME) : '';
    $selectCols = 'company_slug, db_name';
    if (columnExists('companies', 'db_host', $usePdo)) {
        $selectCols .= ', db_host';
    }
    if (columnExists('companies', 'db_user', $usePdo)) {
        $selectCols .= ', db_user';
    }
    if (columnExists('companies', 'db_pass', $usePdo)) {
        $selectCols .= ', db_pass';
    }

    $sql = 'SELECT ' . $selectCols . ' FROM companies WHERE TRIM(company_slug) <> \'\'';
    if (columnExists('companies', 'status', $usePdo)) {
        $sql .= " AND LOWER(TRIM(status)) = 'active'";
    }
    $sql .= ' ORDER BY id ASC';

    try {
        $companies = $usePdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        error_log('resolveLoginSlugFromTenantDatabases: ' . $e->getMessage());
        return '';
    }

    foreach ($companies as $company) {
        $slug = strtolower(trim((string) ($company['company_slug'] ?? '')));
        $tenantDb = trim((string) ($company['db_name'] ?? ''));
        if ($slug === '' || $tenantDb === '' || ($controlDbName !== '' && $tenantDb === $controlDbName)) {
            continue;
        }

        $tenantHost = trim((string) ($company['db_host'] ?? ''));
        $tenantUser = trim((string) ($company['db_user'] ?? ''));
        $tenantPass = null;
        if (array_key_exists('db_pass', $company)) {
            $rawPass = (string) ($company['db_pass'] ?? '');
            $tenantPass = trim($rawPass) !== '' ? $rawPass : null;
        }

        $tenantPdo = connectToTenantDatabase($tenantDb, $tenantHost, $tenantUser, $tenantPass);
        if (!($tenantPdo instanceof PDO) || !tableExists('users', $tenantPdo)) {
            continue;
        }

        try {
            $params = array();
            $where = buildActiveUserIdentifierWhere($tenantPdo, $identifier, $params);
            $stmt = $tenantPdo->prepare('SELECT 1 FROM users WHERE ' . $where . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                return $slug;
            }
        } catch (Throwable $e) {
            error_log('resolveLoginSlugFromTenantDatabases(' . $slug . '): ' . $e->getMessage());
        }
    }

    return '';
}

function listActiveCompanySlugsForFallback($sourcePdo = null): array
{
    global $control_pdo, $pdo;
    $usePdo = $sourcePdo instanceof PDO
        ? $sourcePdo
        : (($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo);
    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo) || !columnExists('companies', 'company_slug', $usePdo)) {
        return array();
    }
    try {
        $sql = "SELECT company_slug FROM companies WHERE TRIM(company_slug) <> ''";
        if (columnExists('companies', 'status', $usePdo)) {
            $sql .= " AND LOWER(TRIM(status)) = 'active'";
        }
        $sql .= ' ORDER BY id ASC LIMIT 50';
        $rows = $usePdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: array();
        return array_values(array_filter(array_map(static function ($v) {
            return strtolower(trim((string) $v));
        }, $rows)));
    } catch (Throwable $e) {
        return array();
    }
}

function buildLoginAttemptSlugs(string $preferredSlug, $controlPdo = null): array
{
    $attempts = array();
    $preferredSlug = strtolower(trim($preferredSlug));
    foreach (listActiveCompanySlugsForFallback($controlPdo) as $slug) {
        if ($slug !== '' && !in_array($slug, $attempts, true)) {
            $attempts[] = $slug;
        }
    }
    if ($preferredSlug !== '' && !in_array($preferredSlug, $attempts, true)) {
        array_unshift($attempts, $preferredSlug);
    } elseif ($preferredSlug !== '') {
        $attempts = array_values(array_diff($attempts, array($preferredSlug)));
        array_unshift($attempts, $preferredSlug);
    }
    return $attempts;
}

/**
 * Safe post-login redirect: optional ?next= path, else company module picker.
 */
function resolvePostLoginRedirectUrl(string $companySlug = '', string $next = ''): string
{
    $next = trim($next);
    if ($next !== '') {
        if (preg_match('#^https?://#i', $next) || strpos($next, '//') === 0) {
            $next = '';
        } else {
            $next = ltrim(str_replace('\\', '/', $next), '/');
            if ($next !== '' && strpos($next, '..') === false) {
                return app_url('/' . $next);
            }
        }
    }

    $slug = strtolower(trim($companySlug));
    if ($slug !== '') {
        return company_url('select-module', $slug);
    }
    return app_url('/select-module.php');
}

/**
 * Company slug for post-login redirect: explicit slug, then session, then user's company.
 */
function resolvePostLoginCompanySlug($submittedSlug = null): string
{
    $slug = strtolower(trim((string) $submittedSlug));
    if ($slug !== '') {
        return $slug;
    }

    $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
    if ($slug !== '') {
        return $slug;
    }

    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    if ($companyId <= 0 && !empty($_SESSION['user_id'])) {
        $companyId = (int) (resolveUserCompanyId((int) $_SESSION['user_id']) ?? 0);
        if ($companyId > 0) {
            $_SESSION['company_id'] = $companyId;
        }
    }

    if ($companyId > 0) {
        $slug = resolveCompanySlugById($companyId);
        if ($slug !== '') {
            $_SESSION['company_slug'] = $slug;
            if (empty($_SESSION['company_name'])) {
                $company = findCompanyBySlug($slug);
                if ($company) {
                    $_SESSION['company_name'] = (string) ($company['company_name'] ?? '');
                }
            }
        }
    }

    return $slug;
}

function clearAuthSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        $_SESSION['department'],
        $_SESSION['company_id'],
        $_SESSION['company_name'],
        $_SESSION['company_slug']
    );
    @session_regenerate_id(true);
}

if (!function_exists('isDiagnosticScriptRequest')) {
    function isDiagnosticScriptRequest(): bool
    {
        $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? '')));
        static $names = [
            'debug_system_full.php', 'debug_create_voucher.php', 'debug_voucher_applicant.php', 'hc.php', 'ping.php', 'debug_online.php',
            'debug_db_connections.php', 'debug_login.php', 'debug_todo_index.php',
        ];
        return in_array(strtolower($script), $names, true);
    }
}

if (!function_exists('normalizeAppWebPath')) {
    /** Strip StackCP /home/sites/.../public_html/ prefix from URL paths. */
    function normalizeAppWebPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^home/sites/.+/public_html(?:/(.*))?$#i', $path, $m)) {
            return trim((string) ($m[1] ?? ''), '/');
        }
        return $path;
    }
}

if (!function_exists('ultitechReservedPathSegments')) {
    function ultitechReservedPathSegments(): array
    {
        return [
            'admin', 'api', 'assets', 'attendance', 'company', 'css', 'deliveries', 'dispatch',
            'employee', 'erp', 'home', 'includes', 'js', 'logs', 'modules', 'client-apps', 'public_html', 'public-html',
            'sites', 'stock', 'storage', 'uploads', 'vouchers', 'store-management-system', 'logout.php', 'login.php', 'select-module.php',
            'index.php', 'my-account.php', 'debug_login.php', 'debug_db_connections.php', 'debug_online.php',
            'debug_system_full.php', 'debug_create_voucher.php', 'debug_voucher_applicant.php', 'debug_todo_index.php', 'hc.php', 'ping.php',
        ];
    }
}

function detectCompanyFromPath()
{
    if (function_exists('isDiagnosticScriptRequest') && isDiagnosticScriptRequest()) {
        return null;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    if ($path === '') {
        return null;
    }
    $base = rtrim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = function_exists('normalizeAppWebPath') ? normalizeAppWebPath($path) : trim($path, '/');
    if ($path === '') {
        return null;
    }
    $segments = explode('/', $path);

    // Localhost installs often run under `/public_html/<company>/...`.
    // In that case the first segment (`public_html`) is a base folder, not a company slug.
    $seg0 = strtolower(trim((string) ($segments[0] ?? '')));
    if (in_array($seg0, ['public_html', 'public-html'], true)) {
        array_shift($segments);
    }

    $slug = strtolower(trim((string) ($segments[0] ?? '')));
    if ($slug === '' || strpos($slug, '.') !== false || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
        return null;
    }

    $reserved = ultitechReservedPathSegments();
    if (in_array($slug, $reserved, true)) {
        return null;
    }

    return [
        'company_slug' => $slug,
        'segments' => $segments,
        'path' => $path,
    ];
}

function getRequestedCompanySlug(): string
{
    if (function_exists('isDiagnosticScriptRequest') && isDiagnosticScriptRequest()) {
        return '';
    }
    $fromGet = strtolower(trim((string) ($_GET['company_slug'] ?? $_GET['company'] ?? '')));
    if ($fromGet !== '' && function_exists('ultitechReservedPathSegments') && in_array($fromGet, ultitechReservedPathSegments(), true)) {
        $fromGet = '';
    }
    if ($fromGet !== '') {
        return $fromGet;
    }
    $detected = detectCompanyFromPath();
    return strtolower(trim((string) ($detected['company_slug'] ?? '')));
}

function getRequestedCompany()
{
    $slug = getRequestedCompanySlug();
    if ($slug === '') {
        return null;
    }
    $company = findCompanyBySlug($slug);
    if (!$company) {
        return null;
    }
    if (strtolower((string) ($company['status'] ?? 'inactive')) !== 'active') {
        return null;
    }
    return $company;
}

function company_url(string $path = 'select-module', $slug = null): string
{
    $resolvedSlug = strtolower(trim((string) ($slug ?? ($_SESSION['company_slug'] ?? getRequestedCompanySlug()))));
    if ($resolvedSlug === '') {
        return app_url('/' . ltrim($path, '/'));
    }
    return app_url('/' . $resolvedSlug . '/' . ltrim($path, '/'));
}

/**
 * Logged-in user profile settings (photo, email, username, WhatsApp, password).
 */
function user_profile_settings_path(): string
{
    return 'employee/account.php';
}

function user_profile_settings_url($prefix = null, $module = null): string
{
    if ($prefix !== null && $prefix !== '') {
        $url = rtrim((string) $prefix, '/') . '/' . user_profile_settings_path();
    } else {
        $url = company_url(user_profile_settings_path());
    }
    $mod = $module ?? (isset($_GET['module']) ? (string) $_GET['module'] : '');
    if ($mod !== '') {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'module=' . rawurlencode($mod);
    }
    return $url;
}

/**
 * Score password strength for profile / reset forms.
 *
 * @return array{score: int, max: int, label: string, percent: int, checks: array<string, bool>, acceptable: bool}
 */
function evaluatePasswordStrength($password)
{
    $password = (string) $password;
    $checks = array(
        'length' => strlen($password) >= 8,
        'mixed_case' => preg_match('/[a-z]/', $password) === 1 && preg_match('/[A-Z]/', $password) === 1,
        'digit' => preg_match('/\d/', $password) === 1,
        'special' => preg_match('/[^a-zA-Z0-9]/', $password) === 1,
    );

    $score = 0;
    if ($checks['length']) {
        $score++;
    }
    if (strlen($password) >= 12) {
        $score++;
    }
    if ($checks['mixed_case']) {
        $score++;
    }
    if ($checks['digit']) {
        $score++;
    }
    if ($checks['special']) {
        $score++;
    }

    $labels = array('Very weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong');
    $level = max(0, min(5, $score));
    $percent = (int) round(($level / 5) * 100);

    return array(
        'score' => $level,
        'max' => 5,
        'label' => $labels[$level],
        'percent' => $percent,
        'checks' => $checks,
        'acceptable' => $checks['length'] && ($checks['mixed_case'] || $checks['digit']),
    );
}

function company_login_url($slug = null): string
{
    return company_url('login', $slug);
}

function company_dashboard_url($slug = null): string
{
    return company_url('select-module', $slug);
}

function requireCompanyAccess($recordCompanyId)
{
    $sessionCompanyId = (int) (currentCompanyId() ?? 0);
    $targetCompanyId = (int) $recordCompanyId;
    if ($sessionCompanyId <= 0 || $targetCompanyId <= 0 || $sessionCompanyId !== $targetCompanyId) {
        http_response_code(403);
        die('Access denied: invalid company scope.');
    }
}

function getCompanySql($alias = null): string
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return "";
    }
    global $pdo;
    if (!columnExists('payment_vouchers', 'company_id', $pdo)) {
        return "";
    }
    return " AND " . ($alias ? "$alias." : "") . "company_id = ?";
}

function getCompanyParam($cid = null): array
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return [];
    }
    global $pdo;
    if (!columnExists('payment_vouchers', 'company_id', $pdo)) {
        return [];
    }
    return [$cid ?? (int) currentCompanyId()];
}

function scopeCompanyQuery(string $sql, $alias = null): string
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return $sql;
    }
    $subject = $alias ? ($alias . '.company_id') : 'company_id';
    if (stripos($sql, ' where ') !== false) {
        return $sql . " AND {$subject} = ?";
    }
    return $sql . " WHERE {$subject} = ?";
}

function isSuperAdmin(): bool
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if (in_array($role, ['super_admin', 'superadmin', 'platform_admin'], true)) {
        return true;
    }
    // Bootstrap compatibility: allow legacy root admin to access super-admin pages.
    // This keeps existing deployments functional until explicit super_admin roles are assigned.
    $username = strtolower(trim((string) ($_SESSION['username'] ?? '')));
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    return $role === 'admin' && ($username === 'admin' || $userId === 1);
}

/**
 * Platform-level operator (control DB admin, super admin, or management unlock).
 * Use for cross-company tools (upload migration, company management).
 */
function isPlatformOperator(): bool
{
    if (isSuperAdmin()) {
        return true;
    }

    $privileged = array('admin', 'administrator', 'superadmin', 'super_admin', 'owner', 'system_admin', 'platform_admin');
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if (in_array($role, $privileged, true)) {
        return true;
    }

    if (!empty($_SESSION['management_unlocked']) && $_SESSION['management_unlocked'] === true) {
        return true;
    }

    $email = function_exists('normalizeLoginEmail')
        ? normalizeLoginEmail((string) ($_SESSION['email'] ?? ''))
        : strtolower(trim((string) ($_SESSION['email'] ?? '')));
    if ($email === '') {
        $ident = trim((string) ($_SESSION['username'] ?? ''));
        if (strpos($ident, '@') !== false) {
            $email = strtolower($ident);
        }
    }

    if ($email !== '') {
        global $control_pdo, $pdo;
        $meta = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
        if ($meta instanceof PDO && tableExists('users', $meta)) {
            try {
                $parts = array("LOWER(TRIM(email)) = ?");
                $params = array($email);
                if (columnExists('users', 'is_active', $meta)) {
                    $parts[] = 'is_active = 1';
                }
                $sql = 'SELECT role FROM users WHERE ' . implode(' AND ', $parts) . ' LIMIT 1';
                $st = $meta->prepare($sql);
                $st->execute($params);
                $dbRole = strtolower(trim((string) $st->fetchColumn()));
                if ($dbRole !== '' && in_array($dbRole, $privileged, true)) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }

        if (function_exists('userCompanyIndexTableReady') && userCompanyIndexTableReady()) {
            try {
                $st = $meta->prepare(
                    "SELECT role, source FROM user_company_index WHERE email = ? AND status = 'active' LIMIT 1"
                );
                $st->execute(array($email));
                $idx = $st->fetch(PDO::FETCH_ASSOC);
                if ($idx) {
                    $idxRole = strtolower(trim((string) ($idx['role'] ?? '')));
                    $idxSource = strtolower(trim((string) ($idx['source'] ?? '')));
                    if (in_array($idxRole, $privileged, true)) {
                        return true;
                    }
                    if ($idxSource === 'control' && $idxRole !== '' && $idxRole !== 'employee') {
                        return true;
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }

    return false;
}

/**
 * Returns true if multi-company scoping/isolation is enabled.
 */
function isCompanyScopingEnabled(): bool
{
    return true;
}

/**
 * True when the active PDO connection is the shared control database (not a dedicated tenant DB).
 */
function isSharedControlDatabaseMode($explicitPdo = null)
{
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return false;
    }
    global $pdo;
    $usePdo = $explicitPdo ?? $pdo;
    if (!($usePdo instanceof PDO)) {
        return false;
    }
    $configured = defined('DB_NAME') ? (string) DB_NAME : '';
    if ($configured === '') {
        return true;
    }
    try {
        return (string) $usePdo->query('SELECT DATABASE()')->fetchColumn() === $configured;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Build SQL fragment for company_id filtering on shared legacy databases.
 *
 * @param string $table Table name
 * @param string $alias Optional SQL alias (e.g. "pv")
 * @return array{0:string,1:array<int,mixed>} [sql fragment starting with " AND ...", params]
 */
function companyScopeSql($table, $alias = '', $explicitPdo = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? $pdo;
    if (!isCompanyScopingEnabled() || !($usePdo instanceof PDO) || !tableExists($table, $usePdo) || !columnExists($table, 'company_id', $usePdo)) {
        return array('', array());
    }
    if (defined('IS_TENANT_DB') && IS_TENANT_DB && $explicitPdo === null) {
        return array('', array());
    }
    $cid = (int) (currentCompanyId() ?: 0);
    if ($cid <= 0) {
        return array('', array());
    }
    $safeAlias = $alias !== '' ? preg_replace('/[^a-z0-9_]/i', '', $alias) : '';
    $col = ($safeAlias !== '' ? $safeAlias . '.' : '') . 'company_id';
    if (isSharedControlDatabaseMode($usePdo)) {
        $primaryId = 1;
        $metaPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : $usePdo;
        if (tableExists('companies', $metaPdo)) {
            try {
                $st = $metaPdo->query("SELECT id FROM companies WHERE company_slug = 'ultimate' ORDER BY id ASC LIMIT 1");
                $primaryId = (int) ($st->fetchColumn() ?: 1);
                if ($primaryId <= 0) {
                    $primaryId = 1;
                }
            } catch (Throwable $e) {
                $primaryId = 1;
            }
        }
        if ($cid === $primaryId) {
            return array(' AND (' . $col . ' = ? OR ' . $col . ' IS NULL OR ' . $col . ' = 0)', array($cid));
        }
    }
    return array(' AND ' . $col . ' = ?', array($cid));
}

/**
 * Assign legacy rows (NULL/0 company_id) to Ultimate on shared DB installs.
 *
 * @return array<string, int> table => rows updated
 */
function backfillLegacyCompanyIdsForUltimate($explicitPdo = null, $companyId = null)
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    $results = array();
    if (!($usePdo instanceof PDO) || !tableExists('companies', $usePdo)) {
        return $results;
    }
    if ($companyId === null) {
        try {
            $st = $usePdo->query("SELECT id FROM companies WHERE company_slug = 'ultimate' ORDER BY id ASC LIMIT 1");
            $companyId = (int) ($st->fetchColumn() ?: 1);
        } catch (Throwable $e) {
            $companyId = 1;
        }
    }
    $companyId = (int) $companyId;
    if ($companyId <= 0) {
        return $results;
    }
    $tables = array(
        'payment_vouchers', 'voucher_items', 'approval_logs', 'users', 'tasks',
        'payment_vouchers', 'financial_accounts', 'account_transactions', 'attendance_records',
        'user_tasks', 'notifications', 'document_sequences', 'company_modules',
    );
    $tables = array_values(array_unique($tables));
    foreach ($tables as $table) {
        if (!tableExists($table, $usePdo) || !columnExists($table, 'company_id', $usePdo)) {
            continue;
        }
        try {
            $sql = 'UPDATE `' . str_replace('`', '``', $table) . '` SET company_id = ? WHERE company_id IS NULL OR company_id = 0';
            $stmt = $usePdo->prepare($sql);
            $stmt->execute(array($companyId));
            $results[$table] = (int) $stmt->rowCount();
        } catch (Throwable $e) {
            $results[$table] = -1;
            error_log('backfillLegacyCompanyIdsForUltimate(' . $table . '): ' . $e->getMessage());
        }
    }
    return $results;
}

function getCurrentCompany()
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0 || !tableExists('companies')) {
        return null;
    }
    try {
        $stmt = $usePdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getCompanySetting(string $settingKey, $default = null)
{
    global $pdo, $control_pdo;
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0) {
        return $default;
    }
    // Prefer tenant/data PDO (where company_settings are saved); fall back to control DB.
    $candidates = [];
    if ($pdo instanceof PDO) {
        $candidates[] = $pdo;
    }
    if ($control_pdo instanceof PDO && !in_array($control_pdo, $candidates, true)) {
        $candidates[] = $control_pdo;
    }
    foreach ($candidates as $usePdo) {
        $settings = fetchCompanySettingsMap($usePdo, $cid);
        if (array_key_exists($settingKey, $settings)) {
            return $settings[$settingKey];
        }
    }
    return $default;
}

/**
 * True for shared template filenames that must not mask a real per-company logo.
 */
function isGenericCompanyLogoPlaceholder(string $path): bool
{
    $base = strtolower(basename(str_replace('\\', '/', trim($path))));
    if ($base === '') {
        return false;
    }
    static $placeholders = [
        'untitled.jpg', 'untitled.jpeg', 'untitled.png', 'untitled.webp',
        'logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg', 'logo.webp',
        'company-logo.png', 'company-logo.jpg', 'company-logo.jpeg',
        'default.png', 'default.jpg', 'placeholder.png', 'login_hero.png',
    ];
    return in_array($base, $placeholders, true);
}

/**
 * Logo URL from Admin → Company Settings → Branding (same source as company-settings.php preview).
 */
function resolveCompanyBrandingLogoUrl($companyId = null): string
{
    global $pdo, $control_pdo;
    $cid = (int) ($companyId ?? currentCompanyId() ?? 0);
    if ($cid <= 0) {
        return '';
    }

    $rootDir = dirname(__DIR__);
    $pdbs = [];
    foreach ([$pdo ?? null, $control_pdo ?? null] as $db) {
        if ($db instanceof PDO && !in_array($db, $pdbs, true)) {
            $pdbs[] = $db;
        }
    }

    foreach ($pdbs as $db) {
        $settings = fetchCompanySettingsMap($db, $cid);
        $raw = trim((string) ($settings['company_logo'] ?? ''));
        if ($raw === '' || isGenericCompanyLogoPlaceholder($raw)) {
            continue;
        }
        if (preg_match('#^https?://#i', $raw) || str_starts_with($raw, 'data:')) {
            return $raw;
        }
        if (str_starts_with($raw, '/')) {
            $disk = $rootDir . str_replace('/', DIRECTORY_SEPARATOR, $raw);
        } else {
            $disk = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw);
        }
        if (!is_file($disk)) {
            continue;
        }
        $webPath = ltrim(str_replace('\\', '/', $raw), '/');
        return function_exists('app_url') ? app_url('/' . $webPath) : '/' . $webPath;
    }

    return '';
}

/**
 * Resolve the public URL for a company's logo (upload, settings, or slug default).
 */
function getCompanyLogoUrl($companyId = null): string
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $cid = (int) ($companyId ?? currentCompanyId() ?? 0);
    $slug = '';

    if ($cid > 0 && tableExists('companies', $usePdo)) {
        try {
            $stmt = $usePdo->prepare('SELECT company_slug FROM companies WHERE id = ? LIMIT 1');
            $stmt->execute([$cid]);
            $slug = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
        } catch (Throwable $e) {
        }
    }
    if ($slug === '' && function_exists('getRequestedCompanySlug')) {
        $slug = strtolower(trim(getRequestedCompanySlug()));
    }
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower((string) $_SESSION['company_slug']);
    }

    $rootDir = dirname(__DIR__);
    $toUrl = static function (string $relativePath) use ($rootDir): string {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($rel === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $rel) || str_starts_with($rel, 'data:')) {
            return $rel;
        }
        $disk = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($disk)) {
            return '';
        }
        return function_exists('app_url') ? app_url('/' . $rel) : '/' . $rel;
    };

    $brandingLogoUrl = resolveCompanyBrandingLogoUrl($cid);
    if ($brandingLogoUrl !== '') {
        return $brandingLogoUrl;
    }

    if ($cid > 0) {
        $uploadDir = $rootDir . '/assets/images/company_logos/' . $cid;
        if (is_dir($uploadDir)) {
            $matches = glob($uploadDir . '/*.{png,jpg,jpeg,webp,gif,svg}', GLOB_BRACE) ?: [];
            if ($matches !== []) {
                usort($matches, static function ($a, $b) {
                    return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
                });
                $url = $toUrl('assets/images/company_logos/' . $cid . '/' . basename($matches[0]));
                if ($url !== '') {
                    return $url;
                }
            }
        }
    }

    $resolveLogoPath = static function (string $rawLogo, bool $allowPlaceholder = false) use ($toUrl, $cid, $slug): string {
        $rawLogo = trim($rawLogo);
        if ($rawLogo === '') {
            return '';
        }
        if (!$allowPlaceholder && ($cid > 0 || $slug !== '') && isGenericCompanyLogoPlaceholder($rawLogo)) {
            return '';
        }
        if (preg_match('#^https?://#i', $rawLogo) || str_starts_with($rawLogo, 'data:')) {
            return $rawLogo;
        }
        $url = $toUrl($rawLogo);
        if ($url !== '') {
            return $url;
        }
        if (!str_starts_with($rawLogo, 'assets/')) {
            $url = $toUrl('assets/images/' . ltrim($rawLogo, '/'));
            if ($url !== '') {
                return $url;
            }
        }
        return function_exists('mediaUrlFromPath') ? mediaUrlFromPath($rawLogo) : '';
    };

    // Tenant + control DB settings (legacy wide table, sales_settings)
    $settingsPdbs = [];
    foreach ([$pdo ?? null, $control_pdo ?? null, $usePdo] as $db) {
        if ($db instanceof PDO && !in_array($db, $settingsPdbs, true)) {
            $settingsPdbs[] = $db;
        }
    }
    if ($cid > 0) {
        foreach ($settingsPdbs as $settingsPdo) {
            if (!tableExists('company_settings', $settingsPdo)) {
                continue;
            }
            if (columnExists('company_settings', 'company_logo', $settingsPdo)
                && !columnExists('company_settings', 'setting_key', $settingsPdo)
            ) {
                // Legacy single-row table: only safe when this DB is not shared by multiple companies.
                if ($cid > 0 && tableExists('companies', $settingsPdo)) {
                    try {
                        $companyCount = (int) $settingsPdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
                        if ($companyCount > 1) {
                            continue;
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                }
                try {
                    $rawLogo = trim((string) $settingsPdo->query('SELECT company_logo FROM company_settings ORDER BY id ASC LIMIT 1')->fetchColumn());
                    $url = $resolveLogoPath($rawLogo);
                    if ($url !== '') {
                        return $url;
                    }
                } catch (Throwable $e) {
                }
            }
        }
    }

    if ($slug !== '') {
        foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.webp', 'logo.svg', 'company-logo.png', 'company-logo.jpg'] as $file) {
            $url = $toUrl('assets/images/' . $slug . '/' . $file);
            if ($url !== '') {
                return $url;
            }
        }
        foreach (['.png', '.jpg', '.jpeg', '.webp', '.svg'] as $ext) {
            $url = $toUrl('assets/images/companies/' . $slug . $ext);
            if ($url !== '') {
                return $url;
            }
        }
        $slugLogoDir = $rootDir . '/assets/images/' . $slug;
        if (is_dir($slugLogoDir)) {
            $matches = glob($slugLogoDir . '/*.{png,jpg,jpeg,webp,gif,svg}', GLOB_BRACE) ?: [];
            if ($matches !== []) {
                usort($matches, static function ($a, $b) {
                    return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
                });
                $url = $toUrl('assets/images/' . $slug . '/' . basename($matches[0]));
                if ($url !== '') {
                    return $url;
                }
            }
        }
    }

    foreach ($settingsPdbs as $settingsPdo) {
        if (!tableExists('sales_settings', $settingsPdo) || !columnExists('sales_settings', 'company_logo', $settingsPdo)) {
            continue;
        }
        try {
            $rawLogo = '';
            if ($cid > 0 && columnExists('sales_settings', 'company_id', $settingsPdo)) {
                $stmt = $settingsPdo->prepare('SELECT company_logo FROM sales_settings WHERE company_id = ? ORDER BY id ASC LIMIT 1');
                $stmt->execute([$cid]);
                $rawLogo = trim((string) ($stmt->fetchColumn() ?: ''));
            } elseif ($cid <= 0) {
                $rawLogo = trim((string) $settingsPdo->query('SELECT company_logo FROM sales_settings ORDER BY id ASC LIMIT 1')->fetchColumn());
            }
            if ($rawLogo !== '') {
                $rel = str_starts_with($rawLogo, 'assets/') ? $rawLogo : 'assets/images/' . ltrim($rawLogo, '/');
                $url = $resolveLogoPath($rel);
                if ($url !== '') {
                    return $url;
                }
            }
        } catch (Throwable $e) {
        }
    }

    if ($cid <= 0 && $slug === '') {
        if (defined('COMPANY_LOGO_PATH')) {
            $url = $toUrl((string) COMPANY_LOGO_PATH);
            if ($url !== '') {
                return $url;
            }
        }

        foreach (['company-logo.png', 'company-logo.jpg', 'company-logo.jpeg', 'company-logo.webp', 'logo.png', 'logo.svg'] as $file) {
            $url = $toUrl('assets/images/' . $file);
            if ($url !== '') {
                return $url;
            }
        }
    }

    return '';
}

function setCompanySetting(string $settingKey, $settingValue): bool
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $cid = (int) (currentCompanyId() ?? 0);
    return saveCompanySettingValue($usePdo, $cid, $settingKey, (string) $settingValue);
}

function getCompanyModules(bool $enabledOnly = false): array
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0 || !tableExists('company_modules')) {
        return [];
    }
    try {
        $sql = "SELECT * FROM company_modules WHERE company_id = ?";
        if ($enabledOnly) {
            $sql .= " AND enabled = 1";
        }
        $sql .= " ORDER BY module_name ASC";
        $stmt = $usePdo->prepare($sql);
        $stmt->execute([$cid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function isCompanyModuleEnabled(string $moduleKey): bool
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0 || !tableExists('company_modules')) {
        return true;
    }
    try {
        $stmt = $usePdo->prepare("SELECT enabled FROM company_modules WHERE company_id = ? AND module_key = ? LIMIT 1");
        $stmt->execute([$cid, $moduleKey]);
        $val = $stmt->fetchColumn();
        return $val === false ? true : ((int) $val === 1);
    } catch (Throwable $e) {
        return true;
    }
}

function nextDocumentNumber(string $documentType, string $fallbackPrefix = 'DOC', $companyId = null, $year = null): string
{
    global $pdo;
    $cid = (int) ($companyId ?: (currentCompanyId() ?? 0));
    $yr = (int) ($year ?: date('Y'));
    $prefix = strtoupper($fallbackPrefix) . '/' . $yr . '/';
    $seqPdo = documentSequencesPdo($pdo);
    if ($cid <= 0 || !($seqPdo instanceof PDO)) {
        return $prefix . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    try {
        $seqPdo->beginTransaction();
        $stmt = $seqPdo->prepare("SELECT id, prefix, next_number, padding FROM document_sequences WHERE company_id = ? AND document_type = ? AND year = ? FOR UPDATE");
        $stmt->execute([$cid, $documentType, $yr]);
        $seq = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$seq) {
            $rawPrefix = '';
            $stmtPrev = $seqPdo->prepare("SELECT prefix, year FROM document_sequences WHERE company_id = ? AND document_type = ? ORDER BY year DESC LIMIT 1");
            $stmtPrev->execute([$cid, $documentType]);
            $prevRow = $stmtPrev->fetch(PDO::FETCH_ASSOC);
            if ($prevRow) {
                $prevPfx = trim((string)$prevRow['prefix'] ?? '');
                $prevYr = (int)$prevRow['year'] ?? 0;
                if (strpos($prevPfx, '{YEAR}') !== false) {
                    $rawPrefix = $prevPfx;
                } elseif ($prevYr > 0) {
                    $rawPrefix = str_replace((string)$prevYr, '{YEAR}', $prevPfx);
                } else {
                    $rawPrefix = $prevPfx;
                }
            }
            if ($rawPrefix === '') {
                $rawPrefix = strtoupper($fallbackPrefix) . '/{YEAR}/';
            }

            $stmtIns = $seqPdo->prepare("INSERT INTO document_sequences (company_id, document_type, prefix, next_number, padding, year) VALUES (?, ?, ?, 1, 3, ?)");
            $stmtIns->execute([$cid, $documentType, $rawPrefix, $yr]);
            $seq = [
                'id' => (int) $seqPdo->lastInsertId(),
                'prefix' => $rawPrefix,
                'next_number' => 1,
                'padding' => 3,
            ];
        }
        $number = (int) ($seq['next_number'] ?? 1);
        $padding = max(1, (int) ($seq['padding'] ?? 3));
        $resolvedPrefix = str_replace('{YEAR}', (string)$yr, $seq['prefix'] ?? $prefix);
        $docNo = (string) $resolvedPrefix . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        $stmtUp = $seqPdo->prepare('UPDATE document_sequences SET next_number = next_number + 1, updated_at = NOW() WHERE id = ?');
        $stmtUp->execute([(int) $seq['id']]);
        $seqPdo->commit();
        return $docNo;
    } catch (Throwable $e) {
        if ($seqPdo->inTransaction()) {
            $seqPdo->rollBack();
        }
        return $prefix . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
}

function switchActiveCompany(int $companyId): bool
{
    global $pdo, $control_pdo;
    $usePdo = $control_pdo ?? $pdo;
    if (!isSuperAdmin() || $companyId <= 0 || !tableExists('companies')) {
        return false;
    }
    try {
        $stmt = $usePdo->prepare("SELECT id, company_name FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            return false;
        }
        $_SESSION['company_id'] = (int) $company['id'];
        $_SESSION['company_name'] = (string) ($company['company_name'] ?? '');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

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

function authenticate($userOrEmail, $password, $companySlug = null)
{
    global $pdo, $control_pdo;

    try {
        $userOrEmail = trim((string) $userOrEmail);
        if ($userOrEmail === '' || $password === '') {
            return false;
        }
        ensureMultiCompanyControlSchema();
        $tenantDb = null;
        $selectedCompany = null;
        if ($companySlug !== null && trim($companySlug) !== '') {
            $selectedCompany = findCompanyBySlug(trim((string) $companySlug));
            if (!$selectedCompany || strtolower((string) ($selectedCompany['status'] ?? 'inactive')) !== 'active') {
                return false;
            }
            
            // For multi-database support: If the company has a dedicated DB, switch $pdo now
            // so we authenticate against the tenant's user table.
            $tenantDb = trim((string) ($selectedCompany['db_name'] ?? ''));
            $tenantHost = trim((string) ($selectedCompany['db_host'] ?? ''));
            $tenantUser = trim((string) ($selectedCompany['db_user'] ?? ''));
            $tenantPass = null;
            if (array_key_exists('db_pass', (array) $selectedCompany)) {
                $rawTenantPass = (string) ($selectedCompany['db_pass'] ?? '');
                $tenantPass = trim($rawTenantPass) !== '' ? $rawTenantPass : null;
            }
            $tenantReachable = false;
            if ($tenantDb !== '') {
                $effectiveTenant = resolveEffectiveTenantDbConnection($tenantDb, $tenantHost, $tenantUser, $tenantPass);
                $tenantDb = $effectiveTenant['db_name'];
                $tenantHost = $effectiveTenant['host'];
                $tenantUser = $effectiveTenant['user'];
                $tenantPass = $effectiveTenant['pass'];
                $tenantPdo = connectToTenantDatabase($tenantDb, $tenantHost, $tenantUser, $tenantPass);
                if ($tenantPdo instanceof PDO) {
                    $pdo = $tenantPdo;
                    $tenantReachable = true;
                } elseif ($control_pdo instanceof PDO) {
                    $pdo = $control_pdo;
                }
            }
        }
        $tenantReachable = isset($tenantReachable) ? $tenantReachable : false;
        if (!isset($tenantDb)) {
            $tenantDb = null;
        }
        $hasCompanyColumn = columnExists('users', 'company_id', $pdo);
        $companySelect = $hasCompanyColumn ? ', company_id' : '';
        $hasStatusColumn = columnExists('users', 'status', $pdo);
        $statusSelect = $hasStatusColumn ? ', status' : '';
        $hasApprovalColumn = columnExists('users', 'approval_status', $pdo);
        $approvalSelect = $hasApprovalColumn ? ', approval_status' : '';
        $hasIsActiveColumn = columnExists('users', 'is_active', $pdo);
        $whereParts = [];
        if ($hasIsActiveColumn) {
            $whereParts[] = "is_active = 1";
        }
        if ($hasStatusColumn) {
            $whereParts[] = "(status = 'active' OR status = '')";
        }
        if ($hasApprovalColumn) {
            $whereParts[] = "(approval_status = 'approved' OR approval_status = 'active' OR approval_status = '')";
        }
        $extraWhere = '';
        if (!empty($whereParts)) {
            $extraWhere = ' AND ' . implode(' AND ', $whereParts);
        }
        // Allow login by username OR email for a simpler UX
        $sql = "SELECT id, username, password, full_name, role, department{$companySelect}{$statusSelect}{$approvalSelect} FROM users WHERE (username = ? OR email = ?){$extraWhere}";
        $params = [$userOrEmail, $userOrEmail];
        // Single-DB: scope user to control-plane company_id. Tenant DB: users often use
        // a local company_id (e.g. 1) — the database itself is already scoped to the tenant.
        if ($selectedCompany && $hasCompanyColumn && (!$tenantDb || !$tenantReachable)) {
            $sql .= " AND company_id = ?";
            $params[] = (int) ($selectedCompany['id'] ?? 0);
        } elseif ($selectedCompany && !$hasCompanyColumn) {
            // In Multi-DB architecture, the tenant database users table 
            // might not have a company_id column because it's isolated.
            // If we have a tenant DB defined, we allow this.
            if (!$tenantDb || !$tenantReachable) {
                return false;
            }
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $user = $stmt->fetch();

        // Strict tenant authentication: when a tenant DB is reachable, do not sync/fallback
        // credentials from control users or other companies.
        $strictTenantAuth = ($tenantDb && $tenantReachable && $selectedCompany);
        if (!$strictTenantAuth && $tenantDb && $tenantReachable && $control_pdo && $selectedCompany) {
            $controlCompanyId = (int) ($selectedCompany['id'] ?? 0);
            if ($controlCompanyId > 0 && columnExists('users', 'company_id', $control_pdo)) {
                $controlSql = "SELECT id, username, password, full_name, role, department FROM users WHERE (username = ? OR email = ?) AND company_id = ?{$extraWhere} LIMIT 1";
                $controlStmt = $control_pdo->prepare($controlSql);
                $controlStmt->execute([$userOrEmail, $userOrEmail, $controlCompanyId]);
                $controlUser = $controlStmt->fetch(PDO::FETCH_ASSOC);

                if ($controlUser && password_verify($password, (string) ($controlUser['password'] ?? ''))) {
                    $controlHash = (string) $controlUser['password'];
                    if (!$user) {
                        $retry = $pdo->prepare($sql);
                        $retry->execute($params);
                        $user = $retry->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($user) {
                        try {
                            $syncPw = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                            $syncPw->execute([$controlHash, (int) $user['id']]);
                        } catch (Throwable $eSync) {
                            // Non-fatal; session can still proceed with verified password.
                        }
                        $user['password'] = $controlHash;
                    }
                }
            }

            // Privileged control users may belong to another company_id but still access this tenant.
            $tenantPwOk = $user && password_verify($password, (string) ($user['password'] ?? ''));
            if (!$tenantPwOk && columnExists('users', 'company_id', $control_pdo)) {
                $globalSql = "SELECT id, username, password, full_name, role, department FROM users WHERE (username = ? OR email = ?){$extraWhere} LIMIT 1";
                $globalStmt = $control_pdo->prepare($globalSql);
                $globalStmt->execute([$userOrEmail, $userOrEmail]);
                $globalUser = $globalStmt->fetch(PDO::FETCH_ASSOC);
                $privilegedRoles = ['admin', 'superadmin', 'owner', 'system_admin'];
                $globalRole = strtolower((string) ($globalUser['role'] ?? ''));
                if (
                    $globalUser
                    && in_array($globalRole, $privilegedRoles, true)
                    && password_verify($password, (string) ($globalUser['password'] ?? ''))
                ) {
                    $globalHash = (string) $globalUser['password'];
                    if (!$user) {
                        $retry = $pdo->prepare($sql);
                        $retry->execute($params);
                        $user = $retry->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($user) {
                        try {
                            $syncPw = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                            $syncPw->execute([$globalHash, (int) $user['id']]);
                        } catch (Throwable $eSyncGlobal) {
                        }
                        $user['password'] = $globalHash;
                    }
                }
            }
        }

        // Control-plane password can drift from the tenant DB; accept tenant hash and sync back.
        if (
            $user
            && !password_verify($password, (string) ($user['password'] ?? ''))
            && !$tenantDb
            && ($control_pdo ?? null) instanceof PDO
        ) {
            $controlUserId = (int) ($user['id'] ?? 0);
            $userCompanyId = $hasCompanyColumn ? (int) ($user['company_id'] ?? 0) : 0;
            if ($controlUserId > 0 && $userCompanyId > 0) {
                try {
                    $coStmt = $control_pdo->prepare("SELECT db_name FROM companies WHERE id = ? AND LOWER(TRIM(status)) = 'active' LIMIT 1");
                    $coStmt->execute([$userCompanyId]);
                    $fallbackDb = trim((string) ($coStmt->fetchColumn() ?: ''));
                    $mainDb = defined('DB_NAME') ? (string) DB_NAME : '';
                    if ($fallbackDb !== '' && $fallbackDb !== $mainDb) {
                        $useHost = defined('DB_HOST') ? DB_HOST : 'localhost';
                        $useUser = defined('DB_USER') ? DB_USER : 'root';
                        $usePass = defined('DB_PASS') ? DB_PASS : '';
                        $dsnFb = 'mysql:host=' . $useHost . ';dbname=' . $fallbackDb . ';charset=utf8mb4';
                        $fbPdo = new PDO($dsnFb, $useUser, $usePass);
                        $fbPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $fbPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                        $fbParts = ['(username = ? OR email = ?)'];
                        $fbParams = [$userOrEmail, $userOrEmail];
                        if (columnExists('users', 'is_active', $fbPdo)) {
                            $fbParts[] = 'is_active = 1';
                        }
                        if (columnExists('users', 'status', $fbPdo)) {
                            $fbParts[] = "(status = 'active' OR status = '')";
                        }
                        if (columnExists('users', 'approval_status', $fbPdo)) {
                            $fbParts[] = "(approval_status = 'approved' OR approval_status = 'active' OR approval_status = '')";
                        }
                        $fbSql = 'SELECT id, username, password, full_name, role, department FROM users WHERE ' . implode(' AND ', $fbParts) . ' LIMIT 1';
                        $fbStmt = $fbPdo->prepare($fbSql);
                        $fbStmt->execute($fbParams);
                        $fbUser = $fbStmt->fetch(PDO::FETCH_ASSOC);
                        if ($fbUser && password_verify($password, (string) ($fbUser['password'] ?? ''))) {
                            $tenantHash = (string) $fbUser['password'];
                            foreach (['username', 'full_name', 'role', 'department'] as $field) {
                                if (!empty($fbUser[$field])) {
                                    $user[$field] = $fbUser[$field];
                                }
                            }
                            $user['password'] = $tenantHash;
                            try {
                                $syncCtrl = $control_pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                                $syncCtrl->execute([$tenantHash, $controlUserId]);
                            } catch (Throwable $eFbSync) {
                            }
                        }
                    }
                } catch (Throwable $eFb) {
                }
            }
        }

        if ($user && password_verify($password, (string) ($user['password'] ?? ''))) {
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
            if (!empty($user['email'])) {
                $_SESSION['email'] = normalizeLoginEmail($user['email']);
            }
            $resolvedCompanyId = 0;
            if ($tenantDb && $selectedCompany) {
                // Tenant rows typically store a local company_id; session must use control-plane id.
                $resolvedCompanyId = (int) ($selectedCompany['id'] ?? 0);
            } elseif ($hasCompanyColumn) {
                $resolvedCompanyId = (int) ($user['company_id'] ?? 0);
            }
            if ($resolvedCompanyId <= 0 && !$tenantDb) {
                $resolvedCompanyId = (int) (resolveUserCompanyId((int) $user['id']) ?? 0);
            }
            if ($resolvedCompanyId > 0) {
                $_SESSION['company_id'] = $resolvedCompanyId;
            }
            if ($selectedCompany) {
                $selectedCompanyId = (int) ($selectedCompany['id'] ?? 0);
                $controlDbName = defined('DB_NAME') ? (string) DB_NAME : '';
                $tenantDbName = trim((string) ($selectedCompany['db_name'] ?? ''));
                $sharedTenantDb = ($tenantDbName !== '' && $controlDbName !== '' && $tenantDbName === $controlDbName);
                $privilegedRoles = array('admin', 'superadmin', 'owner', 'system_admin');
                $userRole = strtolower((string) ($user['role'] ?? ''));
                $isPrivileged = in_array($userRole, $privilegedRoles, true);
                if (
                    $selectedCompanyId <= 0
                    || (
                        $resolvedCompanyId !== $selectedCompanyId
                        && !$sharedTenantDb
                        && !$isPrivileged
                    )
                ) {
                    clearAuthSession();
                    return false;
                }
                $_SESSION['company_id'] = $selectedCompanyId;
                $_SESSION['company_name'] = (string) ($selectedCompany['company_name'] ?? '');
                if (columnExists('companies', 'company_slug')) {
                    $_SESSION['company_slug'] = (string) ($selectedCompany['company_slug'] ?? '');
                }
            } elseif ($resolvedCompanyId > 0 && tableExists('companies') && columnExists('companies', 'company_slug')) {
                try {
                    $usePdo = $control_pdo ?? $pdo;
                    $stSlug = $usePdo->prepare("SELECT company_name, company_slug FROM companies WHERE id = ? LIMIT 1");
                    $stSlug->execute([$resolvedCompanyId]);
                    $cRow = $stSlug->fetch(PDO::FETCH_ASSOC);
                    if ($cRow) {
                        $_SESSION['company_name'] = (string) ($cRow['company_name'] ?? ($_SESSION['company_name'] ?? ''));
                        $_SESSION['company_slug'] = (string) ($cRow['company_slug'] ?? '');
                    }
                } catch (Throwable $eSlug) {
                }
            }
            return true;
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function isAdmin()
{
    if (!isset($_SESSION['role'])) return false;
    $role = strtolower(trim((string)$_SESSION['role']));
    $roleAdmin = defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin';
    $roleCoAdmin = defined('ROLE_COMPANY_ADMIN') ? ROLE_COMPANY_ADMIN : 'company_admin';
    return $role === $roleAdmin || $role === $roleCoAdmin || in_array($role, ['admin', 'administrator', 'superadmin', 'super_admin', 'company_admin', 'company admin', 'owner'], true);
}

function isCompanyAdmin()
{
    if (!isset($_SESSION['role'])) return false;
    $role = strtolower(trim((string)$_SESSION['role']));
    $roleCoAdmin = defined('ROLE_COMPANY_ADMIN') ? ROLE_COMPANY_ADMIN : 'company_admin';
    return $role === $roleCoAdmin || $role === 'company_admin' || $role === 'company admin';
}

// Simple CSRF token utilities (idempotent). Stores tokens per session.
function csrf_token()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verify_csrf($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

// Finance users: identified by department value 'Finance' (case-insensitive).
// Admins are not treated as Finance by default for actions restricted to Finance only,
// but you can expand this if needed.
function isFinance()
{
    // Admins have full access to finance controls
    if (isAdmin())
        return true;

    if (!isset($_SESSION['department']))
        return false;
    $dept = trim((string) $_SESSION['department']);
    // Treat any department string that contains "finance" or "account" (case-insensitive) as Finance.
    // This matches "Finance", "Accounts", "Accounting", "Finance Dept", etc.
    return (preg_match('/\b(finance|account|accounts|accounting)\b/i', $dept) === 1);
}

function requireLogin()
{
    global $pdo;
    ensureMultiCompanyControlSchema();
    $requestedCompanySlug = getRequestedCompanySlug();
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
            if ($requestedCompanySlug !== '') {
                header('Location: ' . company_login_url($requestedCompanySlug));
            } else {
                header('Location: ' . app_url('/login.php'));
            }
        }
        exit;
    }
    if (isCompanyScopingEnabled() && empty($_SESSION['company_id'])) {
        $resolvedCompanyId = currentCompanyId();
        if (empty($resolvedCompanyId)) {
            clearAuthSession();
            if (!headers_sent()) {
                header('Location: ' . app_url('/login.php?error=company_context'));
            } else {
                echo "<script>window.location.href='" . app_url('/login.php?error=company_context') . "';</script>";
            }
            exit;
        }
    }
    // Force first-time company admin onboarding before normal module usage.
    if (isCompanyScopingEnabled()) {
        if ($requestedCompanySlug !== '') {
            $requestedCompany = findCompanyBySlug($requestedCompanySlug);
            if (!$requestedCompany || strtolower((string) ($requestedCompany['status'] ?? 'inactive')) !== 'active') {
                renderCompanyNotFoundPage('Company not found.');
            }
            $requestedCompanyId = (int) ($requestedCompany['id'] ?? 0);
            $sessionCompanyId = (int) ($_SESSION['company_id'] ?? 0);
            if ($requestedCompanyId <= 0 || $sessionCompanyId <= 0 || $requestedCompanyId !== $sessionCompanyId) {
                clearAuthSession();
                header('Location: ' . company_login_url($requestedCompanySlug));
                exit;
            }
            $_SESSION['company_slug'] = $requestedCompanySlug;
            $_SESSION['company_name'] = (string) ($requestedCompany['company_name'] ?? ($_SESSION['company_name'] ?? ''));
        }
        $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        if ($role === 'company_admin' && $companyId > 0) {
            $setupStatus = 'active';
            try {
                $st = $pdo->prepare("SELECT setup_status FROM companies WHERE id = ? LIMIT 1");
                $st->execute([$companyId]);
                $setupStatus = strtolower(trim((string) ($st->fetchColumn() ?: 'active')));
            } catch (Throwable $e) {
                $setupStatus = 'active';
            }
            if ($setupStatus === 'pending_setup') {
                $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
                $setupUrl = app_url('/admin/company-settings.php?company_id=' . $companyId . '&step=1');
                $allowed = [
                    '/admin/company-settings.php',
                    '/logout.php',
                    '/login.php',
                    '/api/',
                ];
                $isAllowed = false;
                foreach ($allowed as $allow) {
                    if (strpos($script, $allow) !== false) {
                        $isAllowed = true;
                        break;
                    }
                }
                if (!$isAllowed) {
                    if (!headers_sent()) {
                        header('Location: ' . $setupUrl);
                    } else {
                        echo "<script>window.location.href='" . $setupUrl . "';</script>";
                    }
                    exit;
                }
            }
        }
    }
}

// System Time Helper
function getSystemTime()
{
    global $pdo;
    static $cachedTime = null;

    if ($cachedTime !== null)
        return $cachedTime;

    try {
        ensureSystemSettingsSchema();
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

function getSystemTimeFormat()
{
    global $pdo;
    try {
        ensureSystemSettingsSchema();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_time_format'");
        $stmt->execute();
        $fmt = $stmt->fetchColumn();
        return ($fmt === '12') ? 'h:i A' : 'H:i';
    } catch (Exception $e) {
        return 'H:i';
    }
}

/**
 * Return the first existing column name from preferred + fallbacks.
 * Useful when live DB schemas differ between deployments.
 */
function resolveExistingColumn(string $table, string $preferred, array $fallbacks = [])
{
    global $pdo;
    static $cache = [];
    $key = $table . '|' . $preferred . '|' . implode(',', $fallbacks);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $candidates = array_values(array_unique(array_merge([$preferred], $fallbacks)));
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) {
                $cache[$key] = $c;
                return $c;
            }
        }
    } catch (Exception $e) {
        // ignore and return null
    }

    $cache[$key] = null;
    return null;
}

function requireAdmin()
{
    requireLogin();
    if (!isAdmin()) {
        $dashboardUrl = company_url('employee/dashboard');
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        if ($qs !== '') {
            $dashboardUrl .= (strpos($dashboardUrl, '?') === false ? '?' : '&') . $qs;
        }
        if (!headers_sent()) {
            header('Location: ' . $dashboardUrl);
        } else {
            echo "<script>window.location.href='" . $dashboardUrl . "';</script>";
        }
        exit();
    }
}

function requireFinanceOrAdmin()
{
    requireLogin();
    // Allow admins and finance department users
    // Normalized checks
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $department = strtolower(trim($_SESSION['department'] ?? ''));
    $isAdmin = ($role === 'admin');

    /* DEBUG */ file_put_contents(__DIR__ . '/../debug_access.log', "Time: " . date('Y-m-d H:i:s') . " | Role: " . $role . " | Dept: " . $department . " | IsAdmin: " . ($isAdmin ? 'Yes' : 'No') . "\n", FILE_APPEND);
    
    // Check: If NOT admin AND NOT finance, deny access
    if (!isFinanceOrAdmin()) {
        header('Location: ' . app_url('/select-module.php?error=access_denied'));
        exit();
    }
}

/**
 * Returns true if the user is an admin or in the finance department.
 */
function isFinanceOrAdmin()
{
    if (!isLoggedIn()) return false;
    // isFinance() already returns true for admins
    return isFinance();
}


function logout()
{
    session_destroy();
    header('Location: ' . app_url('/login.php'));
    exit();
}

// ---------------- Flash notifications (session-based) ----------------
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

function generateVoucherNumber()
{
    global $pdo, $control_pdo;

    try {
        $companyId = (int) (currentCompanyId() ?? 0);
        $year = (int) date('Y');
        $seqPdo = documentSequencesPdo($pdo);
        $configuredPrefix = getCurrentPaymentVoucherSequencePrefix($pdo, $companyId, $year);
        $prefix = $configuredPrefix;
        if ($prefix === '') {
            $code = 'UGT';
            $companiesPdo = ($control_pdo instanceof PDO && tableExists('companies', $control_pdo))
                ? $control_pdo
                : $pdo;
            if ($companyId > 0 && tableExists('companies', $companiesPdo)) {
                try {
                    $stmtCode = $companiesPdo->prepare('SELECT company_name FROM companies WHERE id = ? LIMIT 1');
                    $stmtCode->execute([$companyId]);
                    $name = trim((string) $stmtCode->fetchColumn());
                    if ($name !== '') {
                        $parts = preg_split('/\s+/', strtoupper($name));
                        $letters = '';
                        foreach ($parts as $part) {
                            if ($part !== '') {
                                $letters .= $part[0];
                            }
                            if (strlen($letters) >= 3) {
                                break;
                            }
                        }
                        if (strlen($letters) >= 2) {
                            $code = substr($letters, 0, 3);
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            $prefix = "PV/{$code}/{$year}/";
        }

        // 1. Query the highest sequence number in payment_vouchers across all prefixes for this company and year
        $maxSeq = 0;
        try {
            $yearLike = "%/{$year}/%";
            if ($companyId > 0 && columnExists('payment_vouchers', 'company_id', $pdo)) {
                $maxSql = "SELECT voucher_no FROM payment_vouchers WHERE (voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%') AND voucher_no LIKE ? AND company_id = ? ORDER BY CAST(SUBSTRING_INDEX(voucher_no, '/', -1) AS UNSIGNED) DESC LIMIT 1";
                $maxStmt = $pdo->prepare($maxSql);
                $maxStmt->execute([$yearLike, $companyId]);
            } else {
                $maxSql = "SELECT voucher_no FROM payment_vouchers WHERE (voucher_no LIKE 'PV/%' OR voucher_no LIKE 'PA/%') AND voucher_no LIKE ? ORDER BY CAST(SUBSTRING_INDEX(voucher_no, '/', -1) AS UNSIGNED) DESC LIMIT 1";
                $maxStmt = $pdo->prepare($maxSql);
                $maxStmt->execute([$yearLike]);
            }
            $lastV = $maxStmt->fetch();
            if ($lastV) {
                $parts = explode('/', $lastV['voucher_no']);
                $maxSeq = isset($parts[3]) ? intval($parts[3]) : 0;
            }
        } catch (Throwable $e) {
        }
        $nextSequence = max(1, $maxSeq + 1);

        if ($companyId > 0 && ($seqPdo instanceof PDO)) {
            try {
                $seqPdo->beginTransaction();
                $seqStmt = $seqPdo->prepare("SELECT id, prefix, next_number, padding FROM document_sequences WHERE company_id = ? AND document_type = 'payment_voucher' AND year = ? FOR UPDATE");
                $seqStmt->execute([$companyId, $year]);
                $seq = $seqStmt->fetch(PDO::FETCH_ASSOC);
                if (!$seq) {
                    $rawPrefix = '';
                    $stmtPrev = $seqPdo->prepare("SELECT prefix, year FROM document_sequences WHERE company_id = ? AND document_type = 'payment_voucher' ORDER BY year DESC LIMIT 1");
                    $stmtPrev->execute([$companyId]);
                    $prevRow = $stmtPrev->fetch(PDO::FETCH_ASSOC);
                    if ($prevRow) {
                        $prevPfx = trim((string)$prevRow['prefix'] ?? '');
                        $prevYr = (int)$prevRow['year'] ?? 0;
                        if (strpos($prevPfx, '{YEAR}') !== false) {
                            $rawPrefix = $prevPfx;
                        } elseif ($prevYr > 0) {
                            $rawPrefix = str_replace((string)$prevYr, '{YEAR}', $prevPfx);
                        } else {
                            $rawPrefix = $prevPfx;
                        }
                    }
                    if ($rawPrefix === '') {
                        $rawPrefix = $prefix;
                    }
                    $ins = $seqPdo->prepare("INSERT INTO document_sequences (company_id, document_type, prefix, next_number, padding, year) VALUES (?, 'payment_voucher', ?, ?, 3, ?)");
                    $ins->execute([$companyId, $rawPrefix, $nextSequence, $year]);
                    $seq = [
                        'id' => (int) $seqPdo->lastInsertId(),
                        'prefix' => $rawPrefix,
                        'next_number' => $nextSequence,
                        'padding' => 3
                    ];
                }
                $nextNumber = max($nextSequence, (int) ($seq['next_number'] ?? 1));
                $padding = max(3, (int) ($seq['padding'] ?? 3));
                $resolvedPrefix = str_replace('{YEAR}', (string)$year, $seq['prefix'] ?? $prefix);

                // document_sequences can drift behind payment_vouchers; always verify uniqueness.
                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM payment_vouchers WHERE voucher_no = ?');
                $uniqAttempts = 0;
                $uniqMax = 1000;
                do {
                    $voucherNumber = (string) $resolvedPrefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
                    $checkStmt->execute([$voucherNumber]);
                    $exists = (int) $checkStmt->fetchColumn() > 0;
                    if (!$exists) {
                        break;
                    }
                    $nextNumber++;
                    $uniqAttempts++;
                } while ($uniqAttempts < $uniqMax);

                $upSeq = $seqPdo->prepare('UPDATE document_sequences SET next_number = ?, updated_at = NOW() WHERE id = ?');
                $upSeq->execute([$nextNumber + 1, (int) $seq['id']]);
                $seqPdo->commit();
                return $voucherNumber;
            } catch (Throwable $seqError) {
                if ($seqPdo->inTransaction()) {
                    $seqPdo->rollBack();
                }
            }
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
        for ($fb = 0; $fb < 50; $fb++) {
            $candidate = $prefix . str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM payment_vouchers WHERE voucher_no = ?');
            $checkStmt->execute([$candidate]);
            if ((int) $checkStmt->fetchColumn() === 0) {
                return $candidate;
            }
        }
        return $prefix . str_pad((string) time() % 1000, 3, '0', STR_PAD_LEFT);

    } catch (Exception $e) {
        // Fallback voucher number if database query fails
        return "PV/UGC/" . date('Y') . "/" . str_pad((string) (time() % 1000), 3, '0', STR_PAD_LEFT);
    }
}

function canEditVoucher($voucher_id, $user_id)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    $company_sql = getCompanySql('pv');
    if ($company_sql !== '' && $companyId <= 0) {
        return false;
    }
    // Include posted flag so we can lock editing after posting
    // Also fetch is_restricted and creator department
    $params = array_merge([$voucher_id], getCompanyParam());
    
    $stmt = $pdo->prepare("
        SELECT pv.status, pv.created_by, IFNULL(pv.is_posted,0) AS is_posted, pv.is_restricted, u.department AS creator_department
        FROM payment_vouchers pv 
        LEFT JOIN users u ON pv.created_by = u.id
        WHERE pv.id = ?$company_sql");
    $stmt->execute($params);
    $voucher = $stmt->fetch();

    if (!$voucher)
        return false;

    // Admin can always edit
    if (isAdmin())
        return true;

    // Restricted Check
    if (!empty($voucher['is_restricted']) && (int)$voucher['is_restricted'] === 1) {
        // Allow Finance users to edit locked vouchers (as requested)
        if (function_exists('isFinance') && isFinance()) {
            return true;
        }
        // Allow creator? Usually yes for "Confidential" content, but "Lock" might imply Freezing.
        // Given earlier prompt "admin and finance users should be able to access", implies others shouldn't.
        // I'll allow Creator for now to avoid locking them out of their own work, 
        // unless the "Lock" is an Admin Action to prevent tampering.
        // Let's assume Locked = Restricted Access (Privacy).
        if ($voucher['created_by'] == $user_id) {
            return true;
        }
        // Conflict: If status is pending but restricted, block others.
        return false;
    }

    // Once posted, only admin can edit
    if ((int) ($voucher['is_posted'] ?? 0) === 1)
        return false;
    
    // Finance can edit any unposted voucher (e.g. to fix details before posting)
    if (isFinance()) {
        return true;
    }

    // Any logged-in employee can edit vouchers that are still before final approval.
    // This includes pending and confirming states so users can correct mistakes.
    $status = strtolower((string)($voucher['status'] ?? ''));
    return in_array($status, ['pending', 'confirming'], true);
}

/**
 * Whether admins enabled limited reclassification of approved payment vouchers.
 */
function isApprovedVoucherClassificationEditEnabled(): bool
{
    return getCompanySetting('allow_edit_approved_voucher_classification', '0') === '1';
}

/**
 * May the user open limited edit on an approved voucher (purpose + quotation links only)?
 */
function canLimitedEditApprovedVoucher($voucher_id, $user_id): bool
{
    if (!isApprovedVoucherClassificationEditEnabled()) {
        return false;
    }

    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    $company_sql = getCompanySql('pv');
    if ($company_sql !== '' && $companyId <= 0) {
        return false;
    }

    $params = array_merge([(int) $voucher_id], getCompanyParam($companyId));
    try {
        $stmt = $pdo->prepare("
            SELECT pv.status, pv.created_by, IFNULL(pv.is_posted, 0) AS is_posted, pv.is_restricted
            FROM payment_vouchers pv
            WHERE pv.id = ?$company_sql
            LIMIT 1
        ");
        $stmt->execute($params);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }

    if (!$voucher) {
        return false;
    }

    if (strtolower(trim((string) ($voucher['status'] ?? ''))) !== 'approved') {
        return false;
    }

    if (!empty($voucher['is_restricted']) && (int) $voucher['is_restricted'] === 1) {
        if (isAdmin() || (function_exists('isFinance') && isFinance())) {
            return true;
        }
        if ((int) ($voucher['created_by'] ?? 0) === (int) $user_id) {
            return true;
        }
        return false;
    }

    // Non-restricted approved vouchers: any logged-in user when the company setting is on.
    return (int) $user_id > 0;
}

/**
 * Should approved-voucher edit UI be limited to purpose + sales-order links?
 */
function voucherUsesLimitedClassificationEditMode(array $voucher, int $voucherId, int $userId): bool
{
    if (strtolower(trim((string) ($voucher['status'] ?? ''))) !== 'approved') {
        return false;
    }
    if (!isApprovedVoucherClassificationEditEnabled()) {
        return false;
    }
    if (!canLimitedEditApprovedVoucher($voucherId, $userId)) {
        return false;
    }

    return !canEditVoucher($voucherId, $userId);
}

/**
 * Validate sales order ids exist for voucher quotation linking.
 *
 * @param int[] $linkedOrderIds
 * @return int[]
 */
function validateLinkedSalesOrderIdsForVoucher(PDO $pdo, array $linkedOrderIds, int $companyId = 0): array
{
    $linkedOrderIds = array_values(array_unique(array_filter(array_map('intval', $linkedOrderIds), static function ($id) {
        return $id > 0;
    })));
    if ($linkedOrderIds === [] || !tableExists('sales_orders', $pdo)) {
        return [];
    }

    $valid = [];
    try {
        $stmt = $pdo->prepare('SELECT id FROM sales_orders WHERE id = ?' . getCompanySql('sales_orders') . ' LIMIT 1');
        $cid = $companyId > 0 ? $companyId : (int) (currentCompanyId() ?? 0);
        foreach ($linkedOrderIds as $sid) {
            $stmt->execute(array_merge([(int) $sid], getCompanyParam($cid)));
            if ($stmt->fetchColumn()) {
                $valid[] = (int) $sid;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $valid;
}

/**
 * Save purpose + quotation links on an approved voucher (limited edit).
 *
 * @param int[] $linkedOrderIds
 * @return array{ok: bool, message: string}
 */
function saveApprovedVoucherLimitedClassification(PDO $pdo, int $voucherId, int $userId, string $voucherPurpose, array $linkedOrderIds): array
{
    $companyId = (int) (currentCompanyId() ?? 0);
    $params = array_merge([$voucherId], getCompanyParam($companyId));
    $companySql = getCompanySql('pv');
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_vouchers WHERE id = ?$companySql LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to load voucher.'];
    }

    if (!$row || !canLimitedEditApprovedVoucher($voucherId, $userId)) {
        return ['ok' => false, 'message' => 'You are not allowed to update this approved voucher.'];
    }

    if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'approved') {
        return ['ok' => false, 'message' => 'Only approved vouchers can be updated this way.'];
    }

    $purpose = normalizePaymentVoucherPurpose($voucherPurpose);
    $linkedOrderIds = validateLinkedSalesOrderIdsForVoucher($pdo, $linkedOrderIds, $companyId);
    $linkedPrimary = !empty($linkedOrderIds) ? (int) $linkedOrderIds[0] : 0;

    $oldPurpose = resolvePaymentVoucherPurposeFromRow($row);
    $oldLinked = parseLinkedSalesOrderIdsFromVoucher($row);

    try {
        $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $sets = [];
        $vals = [];
        $purposeFragments = buildPaymentVoucherPurposeUpdateFragments($purpose, $pvCols);
        foreach ($purposeFragments['sets'] as $setFragment) {
            $sets[] = $setFragment;
        }
        foreach ($purposeFragments['vals'] as $valFragment) {
            $vals[] = $valFragment;
        }
        if (in_array('linked_sales_order_id', $pvCols, true)) {
            $sets[] = 'linked_sales_order_id = ?';
            $vals[] = $linkedPrimary > 0 ? $linkedPrimary : null;
        }
        if (in_array('linked_sales_order_ids', $pvCols, true)) {
            $sets[] = 'linked_sales_order_ids = ?';
            $vals[] = !empty($linkedOrderIds) ? json_encode(array_values($linkedOrderIds)) : null;
        }
        if ($sets === []) {
            return ['ok' => false, 'message' => 'Voucher classification columns are not available.'];
        }
        $vals[] = $voucherId;
        $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        $comment = sprintf(
            'Limited classification update: purpose %s → %s; linked sales orders [%s] → [%s]',
            $oldPurpose,
            $purpose,
            implode(',', $oldLinked),
            implode(',', $linkedOrderIds)
        );
        logVoucherAction($voucherId, $userId, 'limited_classification_update', $comment);

        $newUploads = processVoucherSupportingFileUploads($voucherId, $userId);
        if ($newUploads > 0) {
            logVoucherAction($voucherId, $userId, 'attachments_added', 'Added ' . $newUploads . ' supporting document(s) during limited edit.');
        }

        $message = 'Voucher classification updated.';
        if ($newUploads > 0) {
            $message .= ' ' . $newUploads . ' file(s) uploaded.';
        }

        return ['ok' => true, 'message' => $message];
    } catch (Throwable $e) {
        error_log('saveApprovedVoucherLimitedClassification: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to save voucher classification.'];
    }
}

function logVoucherAction($voucher_id, $user_id, $action, $comments = null)
{
    global $pdo;

    $voucher_id = (int) $voucher_id;
    $user_id = (int) $user_id;
    if ($voucher_id <= 0) {
        return false;
    }
    if ($user_id <= 0 && $pdo instanceof PDO && function_exists('resolveVoucherSessionUserId')) {
        $user_id = (int) resolveVoucherSessionUserId($pdo);
    }
    if ($user_id <= 0) {
        return false;
    }

    try {
        $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
        if (columnExists('approval_logs', 'company_id', $pdo) && $companyId > 0) {
            $stmt = $pdo->prepare('INSERT INTO approval_logs (voucher_id, user_id, action, comments, company_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$voucher_id, $user_id, $action, $comments, $companyId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO approval_logs (voucher_id, user_id, action, comments) VALUES (?, ?, ?, ?)');
            $stmt->execute([$voucher_id, $user_id, $action, $comments]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('logVoucherAction voucher ' . $voucher_id . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Map PDO / SQL errors to user-safe voucher workflow messages.
 */
function voucherWorkflowFriendlyError(string $errorMsg): string
{
    if (strpos($errorMsg, 'Please') !== false || strpos($errorMsg, 'required') !== false) {
        return $errorMsg;
    }
    if (stripos($errorMsg, 'SQLSTATE') !== false || stripos($errorMsg, 'Integrity constraint') !== false) {
        if (stripos($errorMsg, 'Duplicate entry') !== false) {
            return 'A database conflict occurred. Please refresh the page and try again.';
        }
        if (stripos($errorMsg, 'user_id') !== false || stripos($errorMsg, 'approval_logs') !== false || stripos($errorMsg, 'created_by') !== false || stripos($errorMsg, 'approved_by') !== false) {
            return 'Your user account could not be verified. Please log out and sign in again.';
        }
        return 'Database constraint error. Please check that all required fields are filled correctly.';
    }
    if (strpos($errorMsg, 'Column') !== false && strpos($errorMsg, 'cannot be null') !== false) {
        return 'Missing required information. Please fill in all required fields.';
    }
    return 'An error occurred while processing the voucher. Please try again.';
}

/**
 * SQL expression for company display name (multicompany vs legacy tenant schema).
 */
function companiesDisplayNameSql($explicitPdo = null, string $tableAlias = 'c', string $resultAlias = 'company_name'): string
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!$usePdo instanceof PDO || !tableExists('companies', $usePdo)) {
        return "'' AS {$resultAlias}";
    }
    if (columnExists('companies', 'company_name', $usePdo)) {
        return "{$tableAlias}.company_name AS {$resultAlias}";
    }
    if (columnExists('companies', 'name', $usePdo)) {
        return "{$tableAlias}.name AS {$resultAlias}";
    }
    return "'' AS {$resultAlias}";
}

function companiesJoinSql($explicitPdo = null, string $userAlias = 'u', string $companyAlias = 'c'): string
{
    global $pdo, $control_pdo;
    $usePdo = $explicitPdo ?? ($control_pdo ?? $pdo);
    if (!$usePdo instanceof PDO || !tableExists('companies', $usePdo) || !columnExists('users', 'company_id', $usePdo)) {
        return '';
    }
    return " LEFT JOIN companies {$companyAlias} ON {$userAlias}.company_id = {$companyAlias}.id";
}

/**
 * Build OR conditions so voucher list search matches visible table columns.
 */
function buildPaymentVoucherSearchSql(string $search, array &$params, string $pvAlias = 'pv', string $userAlias = 'u'): string
{
    $search = trim($search);
    if ($search === '') {
        return '';
    }

    $term = '%' . $search . '%';
    $termLower = '%' . strtolower($search) . '%';
    $parts = [];

    $addLike = static function (string $expr) use (&$parts, &$params, $term) {
        $parts[] = $expr . ' LIKE ?';
        $params[] = $term;
    };

    $addLike("{$pvAlias}.voucher_no");
    $addLike("{$pvAlias}.payee_name");
    $addLike("COALESCE({$pvAlias}.prepared_by, '')");
    $addLike("COALESCE({$userAlias}.full_name, '')");
    $addLike("COALESCE({$userAlias}.department, '')");
    $addLike("COALESCE({$pvAlias}.description, '')");
    $addLike("COALESCE({$pvAlias}.currency, '')");
    $addLike("CAST({$pvAlias}.id AS CHAR)");
    $addLike("CAST({$pvAlias}.total_amount AS CHAR)");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%d/%m/%Y')");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%Y-%m-%d')");
    $addLike("DATE_FORMAT({$pvAlias}.created_at, '%d/%m/%Y %H:%i')");
    $addLike("DATE_FORMAT({$pvAlias}.created_at, '%H:%i')");
    // Month-based search (e.g. "January", "Jan", "January 2026", "2026-01")
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%M')");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%b')");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%M %Y')");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%b %Y')");
    $addLike("DATE_FORMAT({$pvAlias}.date_created, '%Y-%m')");
    $parts[] = "LOWER(COALESCE({$pvAlias}.status, '')) LIKE ?";
    $params[] = $termLower;
    $addLike("COALESCE((SELECT ua.full_name FROM users ua WHERE ua.id = {$pvAlias}.approved_by LIMIT 1), '')");

    $amountNorm = str_replace([',', ' ', 'TZS', 'USD', 'KES', 'UGX', 'EUR', 'GBP'], '', strtoupper($search));
    if ($amountNorm !== '' && is_numeric($amountNorm)) {
        $parts[] = "{$pvAlias}.total_amount = ?";
        $params[] = (float) $amountNorm;
        $parts[] = "CAST({$pvAlias}.total_amount AS CHAR) LIKE ?";
        $params[] = '%' . $amountNorm . '%';
    }

    $statusKey = strtolower($search);
    if (str_contains($statusKey, 'paid')) {
        $parts[] = "IFNULL({$pvAlias}.is_paid, 0) = 1";
    }
    if (str_contains($statusKey, 'post')) {
        $parts[] = "IFNULL({$pvAlias}.is_posted, 0) = 1";
    }
    if (str_contains($statusKey, 'draft')) {
        $parts[] = "({$pvAlias}.status IN ('pending', 'confirming') AND (COALESCE({$pvAlias}.payee_name,'') = '' OR COALESCE({$pvAlias}.total_amount,0) <= 0 OR NOT EXISTS (SELECT 1 FROM voucher_items vi WHERE vi.voucher_id = {$pvAlias}.id)))";
    }
    if (str_contains($statusKey, 'pending') || str_contains($statusKey, 'confirm')) {
        $parts[] = "{$pvAlias}.status IN ('pending', 'confirming')";
    }
    if (str_contains($statusKey, 'approv')) {
        $parts[] = "{$pvAlias}.status = 'approved'";
    }
    if (str_contains($statusKey, 'reject')) {
        $parts[] = "{$pvAlias}.status = 'rejected'";
    }

    if (preg_match('/^\d+$/', $search)) {
        $parts[] = "(SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = {$pvAlias}.id) = ?";
        $params[] = (int) $search;
    }
    if (preg_match('/doc|attach|file|paperclip/i', $search)) {
        $parts[] = "EXISTS (SELECT 1 FROM voucher_attachments va WHERE va.voucher_id = {$pvAlias}.id)";
    }

    if ($parts === []) {
        return '';
    }

    return '(' . implode(' OR ', $parts) . ')';
}

// Strictly mark a voucher as paid (Finance/Admin only) and only if status is approved
// Returns ['ok'=>true] on success or ['ok'=>false,'error'=>'message'] on failure
function markVoucherPaidStrict($voucher_id, $user_id)
{
    global $pdo;
    if (!isAdmin() && !isFinance()) {
        return ['ok' => false, 'error' => 'Not authorized'];
    }
    try {
        $companyId = (int) (currentCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new Exception('Missing company context');
        }
        $pdo->beginTransaction();
        // Lock the voucher row and fetch core fields needed for validation
        if (columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare("SELECT id, status, IFNULL(is_paid,0) AS is_paid, approved_by, COALESCE(payee_name,'') AS payee_name, COALESCE(total_amount,0) AS total_amount FROM payment_vouchers WHERE id=? AND company_id = ? FOR UPDATE");
            $stmt->execute([(int) $voucher_id, $companyId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, status, IFNULL(is_paid,0) AS is_paid, approved_by, COALESCE(payee_name,'') AS payee_name, COALESCE(total_amount,0) AS total_amount FROM payment_vouchers WHERE id=? FOR UPDATE");
            $stmt->execute([(int) $voucher_id]);
        }
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
            if (columnExists('voucher_items', 'company_id')) {
                $ci = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_items WHERE voucher_id = ? AND company_id = ?');
                $ci->execute([(int) $voucher_id, $companyId]);
            } else {
                $ci = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_items WHERE voucher_id = ?');
                $ci->execute([(int) $voucher_id]);
            }
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
            if (columnExists('users', 'company_id')) {
                $u = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1 AND company_id = ?");
                $u->execute([$approverId, $companyId]);
            } else {
                $u = $pdo->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
                $u->execute([$approverId]);
            }
            $ur = $u->fetch();
            if (!$ur || (string) $ur['role'] !== ROLE_ADMIN) {
                throw new Exception('Only admin-approved vouchers can be marked paid by Finance');
            }
        }

        if (columnExists('payment_vouchers', 'company_id')) {
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_paid=1, paid_by=?, paid_at=NOW() WHERE id=? AND company_id = ?");
            $up->execute([(int) $user_id, (int) $voucher_id, $companyId]);
        } else {
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_paid=1, paid_by=?, paid_at=NOW() WHERE id=?");
            $up->execute([(int) $user_id, (int) $voucher_id]);
        }
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

// Mark a voucher as posted (final finance bookkeeping step)
// Preconditions: voucher exists, is_paid = 1, is_posted = 0, caller is finance OR admin.
// Returns array: ['ok'=>bool, 'error'=>string|null]
function markVoucherPosted($voucher_id, $user_id)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        return ['ok' => false, 'error' => 'Missing company context'];
    }
    // Fetch current state
    if (columnExists('payment_vouchers', 'company_id')) {
        $stmt = $pdo->prepare("SELECT id, IFNULL(is_paid,0) AS is_paid, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id=? AND company_id = ? LIMIT 1");
        $stmt->execute([(int) $voucher_id, $companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, IFNULL(is_paid,0) AS is_paid, IFNULL(is_posted,0) AS is_posted FROM payment_vouchers WHERE id=? LIMIT 1");
        $stmt->execute([(int) $voucher_id]);
    }
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
        if (columnExists('payment_vouchers', 'company_id')) {
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_posted=1, posted_by=?, posted_at=NOW() WHERE id=? AND company_id = ?");
            $up->execute([(int) $user_id, (int) $voucher_id, $companyId]);
        } else {
            $up = $pdo->prepare("UPDATE payment_vouchers SET is_posted=1, posted_by=?, posted_at=NOW() WHERE id=?");
            $up->execute([(int) $user_id, (int) $voucher_id]);
        }
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

/**
 * Key/value store for system-wide settings (time zone, WhatsApp link, notices, etc.).
 * Safe to call on every request; runs CREATE TABLE at most once per process.
 */
function ensureSystemSettingsSchema()
{
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('ensureSystemSettingsSchema: ' . $e->getMessage());
    }
}

/**
 * Available system-wide UI fonts (Settings hub selector).
 *
 * @return array<string, array{label:string,stack:string,google?:string,local_css?:string}>
 */
function getSystemFontCatalog(): array
{
    return [
        'poppins' => [
            'label' => 'Poppins (Default)',
            'stack' => "'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        ],
        'arima' => [
            'label' => 'Arima',
            'stack' => "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Arima:wght@400;500;600;700&display=swap',
            'local_css' => '/assets/css/arima-local.css',
        ],
        'inter' => [
            'label' => 'Inter',
            'stack' => "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        ],
        'outfit' => [
            'label' => 'Outfit',
            'stack' => "'Outfit', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
        ],
        'roboto' => [
            'label' => 'Roboto',
            'stack' => "'Roboto', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
        ],
        'open_sans' => [
            'label' => 'Open Sans',
            'stack' => "'Open Sans', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&display=swap',
        ],
        'lato' => [
            'label' => 'Lato',
            'stack' => "'Lato', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap',
        ],
        'montserrat' => [
            'label' => 'Montserrat',
            'stack' => "'Montserrat', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap',
        ],
        'nunito_sans' => [
            'label' => 'Nunito Sans',
            'stack' => "'Nunito Sans', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@300;400;600;700&display=swap',
        ],
        'source_sans_3' => [
            'label' => 'Source Sans 3',
            'stack' => "'Source Sans 3', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;500;600;700&display=swap',
        ],
        'dm_sans' => [
            'label' => 'DM Sans',
            'stack' => "'DM Sans', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap',
        ],
        'raleway' => [
            'label' => 'Raleway',
            'stack' => "'Raleway', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;500;600;700&display=swap',
        ],
        'ubuntu' => [
            'label' => 'Ubuntu',
            'stack' => "'Ubuntu', system-ui, -apple-system, sans-serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap',
        ],
        'merriweather' => [
            'label' => 'Merriweather',
            'stack' => "'Merriweather', Georgia, 'Times New Roman', serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700&display=swap',
        ],
        'playfair_display' => [
            'label' => 'Playfair Display',
            'stack' => "'Playfair Display', Georgia, 'Times New Roman', serif",
            'google' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&display=swap',
        ],
        'system_ui' => [
            'label' => 'System UI',
            'stack' => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        ],
    ];
}

function getSystemFontKey(): string
{
    global $pdo;
    $catalog = getSystemFontCatalog();
    $default = 'poppins';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return $default;
    }
    try {
        ensureSystemSettingsSchema();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_ui_font' LIMIT 1");
        $stmt->execute();
        $key = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
        if ($key !== '' && isset($catalog[$key])) {
            return $key;
        }
    } catch (Throwable $e) {
        error_log('getSystemFontKey: ' . $e->getMessage());
    }
    return $default;
}

/**
 * @return array{label:string,stack:string,google?:string,local_css?:string}|null
 */
function getSystemFontDefinition(?string $key = null): ?array
{
    $catalog = getSystemFontCatalog();
    $resolved = strtolower(trim((string) ($key ?? getEffectiveFontKey())));
    return $catalog[$resolved] ?? $catalog['poppins'] ?? null;
}

function saveSystemFontKey(string $key): bool
{
    global $pdo;
    $catalog = getSystemFontCatalog();
    $key = strtolower(trim($key));
    if (!isset($catalog[$key])) {
        return false;
    }
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return false;
    }
    try {
        ensureSystemSettingsSchema();
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('system_ui_font', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key]);
        return true;
    } catch (Throwable $e) {
        error_log('saveSystemFontKey: ' . $e->getMessage());
        return false;
    }
}

function ensureUserUiFontColumn(): void
{
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }
    try {
        if (!function_exists('columnExists') || columnExists('users', 'ui_font', $pdo)) {
            return;
        }
        $pdo->exec('ALTER TABLE users ADD COLUMN ui_font VARCHAR(64) NULL');
    } catch (Throwable $e) {
        error_log('ensureUserUiFontColumn: ' . $e->getMessage());
    }
}

/**
 * Per-user UI font override (null = use company default).
 */
function getUserFontKey(?int $userId = null): ?string
{
    global $pdo;
    $catalog = getSystemFontCatalog();
    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || !isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    ensureUserUiFontColumn();
    try {
        $stmt = $pdo->prepare('SELECT ui_font FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $key = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
        if ($key === '' || $key === 'default' || $key === 'company') {
            return null;
        }
        if (!isset($catalog[$key])) {
            return null;
        }
        return $key;
    } catch (Throwable $e) {
        error_log('getUserFontKey: ' . $e->getMessage());
        return null;
    }
}

function saveUserFontKey(int $userId, ?string $key): bool
{
    global $pdo;
    $catalog = getSystemFontCatalog();
    if ($userId <= 0 || !isset($pdo) || !($pdo instanceof PDO)) {
        return false;
    }

    ensureUserUiFontColumn();
    $stored = null;
    if ($key !== null) {
        $key = strtolower(trim($key));
        if ($key === '' || $key === 'default' || $key === 'company') {
            $stored = null;
        } elseif (!isset($catalog[$key])) {
            return false;
        } else {
            $stored = $key;
        }
    }

    try {
        $stmt = $pdo->prepare('UPDATE users SET ui_font = ? WHERE id = ?');
        $stmt->execute([$stored, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('saveUserFontKey: ' . $e->getMessage());
        return false;
    }
}

/** Font key applied for the current request (user override, else company default). */
function getEffectiveFontKey(?int $userId = null): string
{
    $userKey = getUserFontKey($userId);
    if ($userKey !== null) {
        return $userKey;
    }

    return getSystemFontKey();
}

/** Cache-buster for dynamic system font stylesheet. */
function erp_system_font_css_version(): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $effective = getEffectiveFontKey($userId > 0 ? $userId : null);
    $def = getSystemFontDefinition($effective);

    return substr(md5($effective . '|' . ($def['stack'] ?? '') . '|u' . $userId), 0, 12);
}

/** URL to tenant-aware dynamic font CSS (linked from style.css and HTML head injection). */
function erp_system_font_css_url(): string
{
    $v = erp_system_font_css_version();
    if (function_exists('app_url')) {
        return app_url('/assets/css/erp-system-font.css.php?v=' . $v);
    }
    return '/assets/css/erp-system-font.css.php?v=' . $v;
}

/**
 * CSS rules for the active system font (used by erp-system-font.css.php and inline fallback).
 */
function erp_build_system_font_css_rules(): string
{
    $def = getSystemFontDefinition();
    if (!$def) {
        return '';
    }

    $stack = $def['stack'];
    $css = '';

    if (!empty($def['local_css']) && function_exists('app_url')) {
        $localUrl = app_url($def['local_css']);
        $css .= '@import url(' . json_encode($localUrl, JSON_UNESCAPED_SLASHES) . ");\n";
    }
    if (!empty($def['google'])) {
        $css .= '@import url(' . json_encode($def['google'], JSON_UNESCAPED_SLASHES) . ");\n";
    }

    $iconExclude = ':not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not(.bi)';
    $iconExclude .= ':not(svg):not(path):not(circle):not(rect):not(line):not(polyline):not(polygon)';

    $ff = 'font-family:var(--erp-font-family)!important;';
    $css .= ':root{--erp-font-family:' . $stack . ";}\n";
    $css .= "html{{$ff}}\n";
    $css .= "html body,html body[class],\n";
    $css .= "html body *{$iconExclude}{{$ff}}\n";
    $css .= "html body input,html body select,html body textarea,html body button,html body label,\n";
    $css .= "html body .form-control,html body .form-input,html body .btn,html body .table,\n";
    $css .= "html body .sidebar,html body .layout-main-wrapper,html body .main-content,\n";
    $css .= "html body .settings-shell,html body .editor-shell,html body .page-shell,\n";
    $css .= "html body .modal,html body .modal-content,html body .dropdown-menu,\n";
    $css .= "html body .app-label,html body .app-desc,html body .top-bar,\n";
    $css .= "html body .po-shell,html body .pay-shell,html body .desk-pay-modal,\n";
    $css .= "html body .pe-shell,html body .prod-desk-page,html body .pe-product-search-menu,\n";
    $css .= "html body .mov-desk,html body .repl-desk,html body .dash-desk,\n";
    $css .= "html body .ld-react-root,html body .ld-dash,html body .tl-page,html body .tf-page,\n";
    $css .= "html body.page-backup-desk,html body .bk-page{{$ff}}\n";
    $css .= "html body.page-exp-desk,html body.page-exp-desk *{$iconExclude},\n";
    $css .= "html body.page-crm-desk,html body.page-crm-desk *{$iconExclude},\n";
    $css .= "html body.page-crm-desk button,html body.page-crm-desk input,html body.page-crm-desk select,html body.page-crm-desk textarea,\n";
    $css .= "html body.page-crm-desk .crm-page,html body.page-crm-desk .crm-page *{$iconExclude},\n";
    $css .= "html body.page-exp-desk button,html body.page-exp-desk input,html body.page-exp-desk select,html body.page-exp-desk textarea,\n";
    $css .= "html body.exp-dashboard-page,html body.exp-dashboard-page *{$iconExclude},\n";
    $css .= "html .exp-desk-page,html .exp-desk-page *{$iconExclude},\n";
    $css .= "html .pc-voucher-view,html .pc-voucher-view *{$iconExclude},\n";
    $css .= "html .pc-pcv-form,html .pc-pcv-form *{$iconExclude},\n";
    $css .= "html .exp-create-shell,html .exp-create-shell *{$iconExclude}{{$ff}}\n";
    // Beat page-local !important rules (voucher form, dashboard shells, sidebar).
    $css .= "html body.dashboard,html body.dashboard *{$iconExclude},\n";
    $css .= "html body.dashboard button,html body.dashboard input,html body.dashboard select,html body.dashboard textarea,\n";
    $css .= "html body.dashboard.vf-voucher-form,html body.dashboard.vf-voucher-form *{$iconExclude},\n";
    $css .= "html body.dashboard.vf-voucher-form button,html body.dashboard.vf-voucher-form input,\n";
    $css .= "html body.dashboard.vf-voucher-form select,html body.dashboard.vf-voucher-form textarea{{$ff}}\n";
    $css .= "html #native-sidebar,html #native-sidebar *{$iconExclude}{{$ff}}\n";
    $css .= "html .po-shell,html .po-shell *{$iconExclude},html .pay-shell,html .pay-shell *{$iconExclude},\n";
    $css .= "html .pe-shell,html .pe-shell *{$iconExclude},html .prod-desk-page,html .prod-desk-page *{$iconExclude},\n";
    $css .= "html .pe-product-search-menu,html .pe-product-search-menu *{$iconExclude},\n";
    $css .= "html .mov-desk,html .mov-desk *{$iconExclude},html .repl-desk,html .repl-desk *{$iconExclude},\n";
    $css .= "html .dash-desk,html .dash-desk *{$iconExclude},\n";
    $css .= "html .ld-react-root,html .ld-react-root *{$iconExclude},html .ld-dash,html .ld-dash *{$iconExclude},\n";
    $css .= "html .tl-page,html .tl-page *{$iconExclude},html .tf-page,html .tf-page *{$iconExclude},\n";
    $css .= "html .bk-page,html .bk-page *{$iconExclude}{{$ff}}\n";
    // Keep Font Awesome / Bootstrap Icons on system font pages (e.g. select-module).
    $css .= "html body .fa,html body .fas,html body .far,html body .fab,html body .fal,html body .fad,\n";
    $css .= "html body .fa-solid,html body .fa-regular,html body .fa-brands,\n";
    $css .= "html body i[class*=\"fa-\"]{font-family:\"Font Awesome 6 Free\",\"Font Awesome 5 Free\",FontAwesome!important;font-style:normal!important;}\n";
    $css .= "html body .fab,html body .fa-brands,html body i.fab{font-family:\"Font Awesome 6 Brands\",\"Font Awesome 5 Brands\"!important;}\n";
    $css .= "html body .fas,html body .fa-solid,html body i.fas,html body i.fa-solid{font-weight:900!important;}\n";
    $css .= "html body .far,html body .fa-regular,html body i.far,html body i.fa-regular{font-weight:400!important;}\n";
    $css .= "html body .bi,html body [class^=\"bi-\"],html body [class*=\" bi-\"]{font-family:bootstrap-icons!important;}\n";

    return $css;
}

/**
 * Full font assets for <head> (stylesheet + live rules). Safe inside output-buffer callbacks.
 */
function erp_get_system_font_assets_html(): string
{
    static $rendered = false;
    if ($rendered || !function_exists('erp_system_font_css_url')) {
        return '';
    }
    $rendered = true;

    $html = '<link rel="stylesheet" id="erp-system-font" href="'
        . htmlspecialchars(erp_system_font_css_url(), ENT_QUOTES, 'UTF-8') . '">' . "\n";

    $rules = erp_build_system_font_css_rules();
    if ($rules !== '') {
        $html .= "<style id=\"erp-system-font-live\">\n" . $rules . "</style>\n";
    }

    return $html;
}

/** Final override block (injected before </body> to beat page-local <style> in body). */
function erp_get_system_font_body_override_html(): string
{
    $rules = erp_build_system_font_css_rules();
    if ($rules === '') {
        return '';
    }
    return "<style id=\"erp-system-font-final\">\n" . $rules . "</style>\n";
}

/** Absolute path to global dark-theme stylesheet. */
function erp_dark_theme_css_path(): string
{
    return dirname(__DIR__) . '/assets/css/dark-theme.css';
}

/** Cache-busted URL for dark-theme.css. */
function erp_dark_theme_css_url(): string
{
    $path = erp_dark_theme_css_path();
    $v = is_file($path) ? (int) filemtime($path) : time();
    if (function_exists('app_url')) {
        return app_url('/assets/css/dark-theme.css') . '?v=' . $v;
    }
    return '/assets/css/dark-theme.css?v=' . $v;
}

/** Inline script: apply saved theme before first paint (prevents flash). */
function erp_get_theme_init_html(): string
{
    return '<script id="erp-theme-init">(function(){try{var t=localStorage.getItem("theme")||"light";document.documentElement.setAttribute("data-theme",t);}catch(e){document.documentElement.setAttribute("data-theme","light");}})();</script>' . "\n";
}

/** Dark theme stylesheet link for &lt;head&gt;. */
function erp_get_dark_theme_head_html(): string
{
    static $rendered = false;
    if ($rendered || !erp_should_inject_theme_assets()) {
        return '';
    }
    $rendered = true;

    return '<link rel="stylesheet" id="erp-dark-theme" href="'
        . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/** Final dark-theme block before &lt;/body&gt; (beats page-local light &lt;style&gt;). */
function erp_get_dark_theme_body_override_html(): string
{
    if (!erp_should_inject_theme_assets()) {
        return '';
    }
    $path = erp_dark_theme_css_path();
    if (!is_file($path)) {
        return '';
    }
    $css = file_get_contents($path);
    if (!is_string($css) || trim($css) === '') {
        return '';
    }
    return "<style id=\"erp-dark-theme-final\">\n" . $css . "\n</style>\n";
}

/** Skip theme injection on print views. */
function erp_should_inject_theme_assets(): bool
{
    if (!empty($_GET['print'])) {
        return false;
    }
    return true;
}

/**
 * Inject font + dark theme on every HTML page (head assets + final body override).
 */
function erp_inject_system_font_into_html_buffer(string $buffer): string
{
    if ($buffer === '' || (stripos($buffer, '<html') === false && stripos($buffer, '<!DOCTYPE') === false)) {
        return $buffer;
    }

    if (erp_should_inject_theme_assets() && stripos($buffer, 'erp-theme-init') === false) {
        $themeInit = erp_get_theme_init_html();
        if (preg_match('/<head[^>]*>/i', $buffer)) {
            $replaced = preg_replace('/<head([^>]*)>/i', '<head$1>' . $themeInit, $buffer, 1);
            if (is_string($replaced)) {
                $buffer = $replaced;
            }
        } elseif (stripos($buffer, '<html') !== false) {
            $replaced = preg_replace('/<html([^>]*)>/i', '<html$1>' . $themeInit, $buffer, 1);
            if (is_string($replaced)) {
                $buffer = $replaced;
            }
        }
    }

    if (stripos($buffer, '</head>') !== false) {
        $headMarkup = '';
        if (stripos($buffer, 'erp-system-font-live') === false) {
            $headMarkup .= erp_get_system_font_assets_html();
        }
        if (stripos($buffer, 'id="erp-dark-theme"') === false) {
            $headMarkup .= erp_get_dark_theme_head_html();
        }
        if ($headMarkup !== '') {
            $replaced = preg_replace('/<\/head>/i', $headMarkup . '</head>', $buffer, 1);
            if (is_string($replaced)) {
                $buffer = $replaced;
            }
        }
    }

    if (stripos($buffer, '</body>') !== false) {
        $bodyMarkup = '';
        if (stripos($buffer, 'erp-system-font-final') === false) {
            $bodyMarkup .= erp_get_system_font_body_override_html();
        }
        if (stripos($buffer, 'erp-dark-theme-final') === false) {
            $bodyMarkup .= erp_get_dark_theme_body_override_html();
        }
        if ($bodyMarkup !== '') {
            $replaced = preg_replace('/<\/body>/i', $bodyMarkup . '</body>', $buffer, 1);
            if (is_string($replaced)) {
                $buffer = $replaced;
            }
        }
    }

    return $buffer;
}

/** Echo font stylesheet + rules for the active system font. */
function renderSystemFontHeadMarkup(): void
{
    echo erp_get_system_font_assets_html();
}

/** Start global output buffer so font CSS is injected on every HTML page. */
function erp_bootstrap_system_font_output_buffer(): void
{
    if (defined('ERP_SYSTEM_FONT_OB_STARTED') || PHP_SAPI === 'cli') {
        return;
    }
    if (defined('ERP_SKIP_SYSTEM_FONT_OB') && ERP_SKIP_SYSTEM_FONT_OB) {
        return;
    }
    if (defined('ULTITECH_DIAGNOSTIC_SCRIPT') && ULTITECH_DIAGNOSTIC_SCRIPT) {
        return;
    }
    define('ERP_SYSTEM_FONT_OB_STARTED', true);
    if (function_exists('erp_inject_system_font_into_html_buffer')) {
        ob_start('erp_inject_system_font_into_html_buffer');
    }
}

// -------------- Notifications --------------
function ensureNotificationsSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (function_exists('ensurePaymentVouchersCoreSchema')) {
        ensurePaymentVouchersCoreSchema($pdo);
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            audience VARCHAR(20) NOT NULL DEFAULT 'user',
            title VARCHAR(150) NOT NULL,
            message TEXT,
            type VARCHAR(20) DEFAULT 'info',
            voucher_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (audience),
            INDEX (voucher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->query('SELECT type FROM notifications LIMIT 1');
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(20) DEFAULT 'info' AFTER message");
            } catch (PDOException $e2) {
            }
        }
    } catch (Throwable $e) {
        error_log('ensureNotificationsSchema: ' . $e->getMessage());
    }

    $ensured = true;
}

function createNotification($opts)
{
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

function getNotificationsForCurrentUser($limit = 10)
{
    global $pdo;
    ensureNotificationsSchema();
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE (audience IN ('user','all') AND (user_id = ? OR audience='all')) ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, (int) $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return [];
}

function getNotificationsForCurrentUserPaged($limit = 20, $offset = 0)
{
    global $pdo;
    ensureNotificationsSchema();
    $limit = (int) $limit;
    $offset = (int) $offset;
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE (audience IN ('user','all') AND (user_id = ? OR audience='all')) ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, (int) $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return [];
}

/**
 * Turn stored system_notifications.link into an absolute URL for the app.
 */
function resolveStoredNotificationLink($rawLink)
{
    $rawLink = $rawLink !== null ? trim((string) $rawLink) : '';
    if ($rawLink === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $rawLink)) {
        return $rawLink;
    }
    if (isset($rawLink[0]) && $rawLink[0] === '/') {
        return app_url($rawLink);
    }

    return app_url('/' . ltrim($rawLink, '/'));
}

/**
 * Resolve payment_vouchers.id from notification text (e.g. voucher number in message).
 */
function nc_guess_voucher_id_from_notification(array $n): int
{
    $vid = (int) ($n['voucher_id'] ?? 0);
    if ($vid > 0) {
        return $vid;
    }
    $blob = (string) ($n['message'] ?? '') . ' ' . (string) ($n['title'] ?? '');
    if (!preg_match('/\b([A-Z]{2,}(?:\/[A-Z0-9]+)+)\b/', $blob, $m)) {
        return 0;
    }
    $voucherNo = trim((string) $m[1]);
    if ($voucherNo === '') {
        return 0;
    }
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    try {
        if ($companyId > 0 && columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare('SELECT id FROM payment_vouchers WHERE voucher_no = ? AND company_id = ? LIMIT 1');
            $stmt->execute([$voucherNo, $companyId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM payment_vouchers WHERE voucher_no = ? LIMIT 1');
            $stmt->execute([$voucherNo]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) ($row['id'] ?? 0) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Destination URL when user opens a notification card.
 */
function nc_notification_href(array $n): string
{
    $src = strtolower((string) ($n['src'] ?? $n['source'] ?? 'core'));
    if ($src === 'system') {
        $link = trim((string) ($n['link_url'] ?? $n['link'] ?? ''));
        if ($link !== '' && function_exists('resolveStoredNotificationLink')) {
            $resolved = resolveStoredNotificationLink($link);

            return $resolved !== null && $resolved !== '' ? (string) $resolved : '';
        }

        return $link;
    }

    $vid = nc_guess_voucher_id_from_notification($n);
    if ($vid <= 0) {
        return '';
    }

    $path = 'employee/view-voucher.php?id=' . $vid;
    if (function_exists('company_url') && trim((string) ($_SESSION['company_slug'] ?? '')) !== '') {
        return company_url($path);
    }

    return app_url('/' . $path);
}

/**
 * Notification centre (/notifications.php): merge core voucher feed + system_notifications, newest first.
 *
 * @return list<array<string,mixed>>
 */
function getNotificationCentreFeedPaged(int $limit = 20, int $offset = 0): array
{
    global $pdo;
    if (!isLoggedIn()) {
        return [];
    }
    ensureNotificationsSchema();
    ensureNotificationsTable();
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    if (isAdmin()) {
        $sql = "
            SELECT * FROM (
                SELECT 'core' AS src, n.id, n.title, n.message, n.type, n.is_read, n.created_at, n.voucher_id, CAST(NULL AS CHAR(512)) AS link_url
                FROM notifications n
                WHERE n.audience IN ('admin','all')
                UNION ALL
                SELECT 'system' AS src, s.id, s.title, s.message, s.type, s.is_read, s.created_at, CAST(NULL AS SIGNED) AS voucher_id, COALESCE(s.link, '') AS link_url
                FROM system_notifications s
                WHERE s.user_id = ?
            ) AS u
            ORDER BY u.created_at DESC
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid]);
    } else {
        $sql = "
            SELECT * FROM (
                SELECT 'core' AS src, n.id, n.title, n.message, n.type, n.is_read, n.created_at, n.voucher_id, CAST(NULL AS CHAR(512)) AS link_url
                FROM notifications n
                WHERE (n.audience IN ('user','all') AND (n.user_id = ? OR n.audience='all'))
                UNION ALL
                SELECT 'system' AS src, s.id, s.title, s.message, s.type, s.is_read, s.created_at, CAST(NULL AS SIGNED) AS voucher_id, COALESCE(s.link, '') AS link_url
                FROM system_notifications s
                WHERE s.user_id = ?
            ) AS u
            ORDER BY u.created_at DESC
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid, $uid]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getUnreadCountForCurrentUser()
{
    global $pdo;
    try {
        ensureNotificationsSchema();
        if (!tableExists('notifications', $pdo)) {
            return 0;
        }
        if (isAdmin()) {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM notifications WHERE audience IN ('admin','all') AND is_read = 0");
            return (int) $stmt->fetchColumn();
        }
        if (isLoggedIn()) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0 AND (user_id = ? OR audience='all') AND audience IN ('user','all')");
            $stmt->execute([(int) $_SESSION['user_id']]);
            return (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('getUnreadCountForCurrentUser: ' . $e->getMessage());
    }
    return 0;
}

function markAllNotificationsReadForCurrentUser()
{
    global $pdo;
    ensureNotificationsSchema();
    if (isAdmin()) {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE audience IN ('admin','all')");
    } else if (isLoggedIn()) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR audience='all') AND audience IN ('user','all')");
        $stmt->execute([$_SESSION['user_id']]);
    }
    if (isLoggedIn()) {
        ensureNotificationsTable();
        try {
            $stmt = $pdo->prepare('UPDATE system_notifications SET is_read = 1 WHERE user_id = ?');
            $stmt->execute([(int) $_SESSION['user_id']]);
        } catch (Throwable $e) {
            /* ignore */
        }
    }
}

/**
 * Unread rows in system_notifications for the logged-in user.
 */
function getUnreadSystemNotificationsCountForCurrentUser($onlyModuleKey = null): int
{
    global $pdo;
    if (!isLoggedIn()) {
        return 0;
    }
    ensureNotificationsTable();
    try {
        $sql = 'SELECT COUNT(*) FROM system_notifications WHERE user_id = ? AND is_read = 0';
        $params = [(int) $_SESSION['user_id']];

        if ($onlyModuleKey) {
            // Match common deep-link patterns: /modules/<key>/... or ?module=<key>
            $sql .= " AND COALESCE(link,'') <> '' AND (link LIKE ? OR link LIKE ?)";
            $params[] = '%/modules/' . $onlyModuleKey . '/%';
            $params[] = '%module=' . $onlyModuleKey . '%';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Badge count: voucher notifications table + per-user system_notifications.
 *
 * @param bool $includeCoreVoucherFeed When false, only system_notifications unreads count (e.g. budget module header).
 */
function getTotalHeaderUnreadNotificationCount(bool $includeCoreVoucherFeed = true, $onlySystemModuleKey = null): int
{
    $n = getUnreadSystemNotificationsCountForCurrentUser($onlySystemModuleKey);
    if ($includeCoreVoucherFeed) {
        $n += getUnreadCountForCurrentUser();
    }

    return $n;
}

/**
 * Recent notifications for header dropdown (core + system), newest first.
 *
 * @param bool $includeCoreVoucherFeed When false, omit core `notifications` rows (payment/voucher feed); system bell items only.
 * @return list<array{source:string,id:int,title:string,message:string,type:string,is_read:int,created_at:string,link:?string}>
 */
function getHeaderNotificationsMerged(int $limit = 12, bool $includeCoreVoucherFeed = true, $onlySystemModuleKey = null): array
{
    global $pdo;
    if (!isLoggedIn()) {
        return [];
    }
    ensureNotificationsSchema();
    ensureNotificationsTable();

    $take = max($limit, 1) * 2;
    $core = [];
    if ($includeCoreVoucherFeed && tableExists('notifications', $pdo)) {
        try {
            if (isAdmin()) {
                $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') ORDER BY created_at DESC LIMIT " . (int) $take);
                $stmt->execute();
                $core = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE (audience IN ('user','all') AND (user_id = ? OR audience='all')) ORDER BY created_at DESC LIMIT " . (int) $take);
                $stmt->execute([(int) $_SESSION['user_id']]);
                $core = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            error_log('getHeaderNotificationsMerged core: ' . $e->getMessage());
            $core = [];
        }
    }

    $system = [];
    try {
        $sysSql = 'SELECT id, title, message, link, type, is_read, created_at FROM system_notifications WHERE user_id = ?';
        $sysParams = [(int) $_SESSION['user_id']];
        if ($onlySystemModuleKey) {
            $sysSql .= " AND COALESCE(link,'') <> '' AND (link LIKE ? OR link LIKE ?)";
            $sysParams[] = '%/modules/' . $onlySystemModuleKey . '/%';
            $sysParams[] = '%module=' . $onlySystemModuleKey . '%';
        }
        $sysSql .= ' ORDER BY created_at DESC LIMIT ' . (int) $take;

        $st = $pdo->prepare($sysSql);
        $st->execute($sysParams);
        $system = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $system = [];
    }

    $merged = [];
    foreach ($core as $r) {
        $vid = isset($r['voucher_id']) ? (int) $r['voucher_id'] : 0;
        $merged[] = [
            'source' => 'core',
            'id' => (int) ($r['id'] ?? 0),
            'title' => (string) ($r['title'] ?? ''),
            'message' => (string) ($r['message'] ?? ''),
            'type' => (string) ($r['type'] ?? 'info'),
            'is_read' => (int) ($r['is_read'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'link' => $vid > 0 ? app_url('/employee/view-voucher.php?id=' . $vid) : null,
        ];
    }
    foreach ($system as $r) {
        $rawLink = isset($r['link']) ? trim((string) $r['link']) : '';
        $link = null;
        if ($rawLink !== '') {
            if (preg_match('#^https?://#i', $rawLink)) {
                $link = $rawLink;
            } elseif (isset($rawLink[0]) && $rawLink[0] === '/') {
                $link = app_url($rawLink);
            } else {
                $link = app_url('/' . ltrim($rawLink, '/'));
            }
        }
        $merged[] = [
            'source' => 'system',
            'id' => (int) ($r['id'] ?? 0),
            'title' => (string) ($r['title'] ?? ''),
            'message' => (string) ($r['message'] ?? ''),
            'type' => (string) ($r['type'] ?? 'info'),
            'is_read' => (int) ($r['is_read'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'link' => $link,
        ];
    }

    usort($merged, static function (array $a, array $b): int {
        return strtotime($b['created_at'] ?: 'now') <=> strtotime($a['created_at'] ?: 'now');
    });

    return array_slice($merged, 0, $limit);
}

/**
 * Unread core notifications (voucher feed) for toast polling.
 *
 * @return list<array<string,mixed>>
 */
function getUnreadCoreNotificationsForPoll(int $userId, int $limit = 15): array
{
    global $pdo;
    ensureNotificationsSchema();
    $limit = max(1, min(50, $limit));
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE audience IN ('admin','all') AND is_read = 0 ORDER BY created_at DESC LIMIT " . (int) $limit);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare("SELECT id, title, message, type, voucher_id, is_read, created_at FROM notifications WHERE is_read = 0 AND audience IN ('user','all') AND (user_id = ? OR audience='all') ORDER BY created_at DESC LIMIT " . (int) $limit);
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function markCoreNotificationRead(int $id): bool
{
    global $pdo;
    ensureNotificationsSchema();
    if (!isLoggedIn()) {
        return false;
    }
    $id = (int) $id;
    if ($id <= 0) {
        return false;
    }
    if (isAdmin()) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience IN ('admin','all')");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience IN ('user','all') AND (user_id = ? OR audience='all')");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }

    return $stmt->rowCount() > 0;
}

function notifyAdminsNewVoucher($voucher_id)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        return;
    }
    // Fetch voucher info
    if (columnExists('payment_vouchers', 'company_id')) {
        $stmt = $pdo->prepare("SELECT voucher_no, payee_name, total_amount FROM payment_vouchers WHERE id = ? AND company_id = ?");
        $stmt->execute([$voucher_id, $companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT voucher_no, payee_name, total_amount FROM payment_vouchers WHERE id = ?");
        $stmt->execute([$voucher_id]);
    }
    $v = $stmt->fetch();
    if (!$v)
        return;
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

function notifyUserVoucherStatus($voucher_id, $status)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        return;
    }
    // fetch owner
    if (columnExists('payment_vouchers', 'company_id')) {
        $stmt = $pdo->prepare("SELECT voucher_no, created_by FROM payment_vouchers WHERE id = ? AND company_id = ?");
        $stmt->execute([$voucher_id, $companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT voucher_no, created_by FROM payment_vouchers WHERE id = ?");
        $stmt->execute([$voucher_id]);
    }
    $v = $stmt->fetch();
    if (!$v)
        return;
    $title = 'Voucher ' . strtoupper($status);
    if ($status === 'posted') {
        $msg = sprintf('Your voucher %s has been posted (finalized).', $v['voucher_no']);
    } else {
        $msg = sprintf('Your voucher %s has been %s.', $v['voucher_no'], $status);
    }
    createNotification([
        'user_id' => (int) $v['created_by'],
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
function notifyCheckedByAssignee($voucher_id)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        return;
    }
    try {
        if (columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare("SELECT voucher_no, checked_by FROM payment_vouchers WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([(int) $voucher_id, $companyId]);
        } else {
            $stmt = $pdo->prepare("SELECT voucher_no, checked_by FROM payment_vouchers WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $voucher_id]);
        }
        $v = $stmt->fetch();
        if (!$v)
            return; // no voucher
        $checkedByName = trim((string) ($v['checked_by'] ?? ''));
        if ($checkedByName === '')
            return; // nothing to notify

        // Resolve full_name -> user_id (active)
        if (columnExists('users', 'company_id')) {
            $u = $pdo->prepare("SELECT id FROM users WHERE full_name = ? AND is_active = 1 AND company_id = ? LIMIT 1");
            $u->execute([$checkedByName, $companyId]);
        } else {
            $u = $pdo->prepare("SELECT id FROM users WHERE full_name = ? AND is_active = 1 LIMIT 1");
            $u->execute([$checkedByName]);
        }
        $row = $u->fetch();
        if (!$row || empty($row['id']))
            return; // user not found

        $title = 'Voucher requires checking';
        $msg = sprintf('You were chosen to check voucher %s. Please visit the voucher to confirm.', (string) $v['voucher_no']);
        createNotification([
            'user_id' => (int) $row['id'],
            'audience' => 'user',
            'title' => $title,
            'message' => $msg,
            'voucher_id' => (int) $voucher_id,
        ]);
    } catch (Exception $e) {
        // Best-effort; do not throw
        if (function_exists('app_log')) {
            app_log('notifyCheckedByAssignee failed for voucher ' . $voucher_id . ': ' . $e->getMessage());
        }
    }
}

function canDeleteVoucher($voucher_id, $user_id)
{
    global $pdo;
    $companyId = (int) (currentCompanyId() ?? 0);
    $companySql = getCompanySql();
    if ($companySql !== '' && $companyId <= 0) {
        return false;
    }

    $params = array_merge([(int) $voucher_id], getCompanyParam());
    try {
        $stmt = $pdo->prepare("SELECT status, created_by FROM payment_vouchers WHERE id = ?" . $companySql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'company_id') === false) {
            throw $e;
        }
        $stmt = $pdo->prepare("SELECT status, created_by FROM payment_vouchers WHERE id = ?");
        $stmt->execute([(int) $voucher_id]);
    }
    $voucher = $stmt->fetch();

    if (!$voucher)
        return false;

    // Admin can always delete
    if (isAdmin())
        return true;

    // Employee can delete their own voucher only if it's not approved yet (pending or rejected)
    return $voucher['created_by'] == $user_id && $voucher['status'] !== STATUS_APPROVED;
}

// -------------- Schema patchers --------------
// Ensure signature_path column on users exists
function ensureUserSignatureColumn()
{
    global $pdo;
    static $ensuredSig = false;
    if ($ensuredSig)
        return;
    try {
        $pdo->query("SELECT signature_path FROM users LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN signature_path VARCHAR(255) NULL AFTER department");
        } catch (PDOException $e2) { /* ignore */
        }
    }
    $ensuredSig = true;
}

// -------------- Schema patch: add checked_by to payment_vouchers if missing --------------
function ensureCheckedByColumnOnPaymentVouchers()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;
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
function ensurePaidColumnsOnPaymentVouchers()
{
    global $pdo;
    static $ensuredPaid = false;
    if ($ensuredPaid)
        return;
    try {
        $pdo->query("SELECT is_paid, paid_by, paid_at FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        // Add columns if any missing; attempt individually for resilience
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_by");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN paid_by INT NULL AFTER is_paid");
        } catch (PDOException $e3) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN paid_at TIMESTAMP NULL AFTER paid_by");
        } catch (PDOException $e4) { /* ignore */
        }
        // Add FK if possible
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_payment_vouchers_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL");
        } catch (PDOException $e5) { /* ignore */
        }
    }
    $ensuredPaid = true;
}

/**
 * Normalize Payment Voucher purpose from form input.
 */
function normalizePaymentVoucherPurpose($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'general';
    }
    $normalized = strtolower(str_replace([' ', '-'], '_', $raw));
    if (in_array($normalized, ['stock_purchase', 'stockpurchase'], true)) {
        return 'stock_purchase';
    }
    if (strpos($normalized, 'stock') !== false && strpos($normalized, 'purchase') !== false) {
        return 'stock_purchase';
    }
    if (in_array($normalized, ['general', 'general_payment'], true)) {
        return 'general';
    }
    return 'general';
}

/**
 * Resolve purpose from a payment_vouchers row (backward compatible).
 */
function resolvePaymentVoucherPurposeFromRow(array $row): string
{
    foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $column) {
        if (!array_key_exists($column, $row)) {
            continue;
        }
        $value = trim((string) ($row[$column] ?? ''));
        if ($value === '') {
            continue;
        }
        return normalizePaymentVoucherPurpose($value);
    }
    return 'general';
}

/**
 * Check if a column exists on payment_vouchers.
 */
function paymentVoucherColumnExists($pdo, string $columnName): bool
{
    if (function_exists('columnExists')) {
        return columnExists('payment_vouchers', $columnName, $pdo);
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_vouchers' AND COLUMN_NAME = ?"
        );
        $stmt->execute([$columnName]);
        return ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Append purpose-related columns to INSERT when they exist.
 *
 * @param array<int,string> $insertCols
 * @param array<int,mixed> $insertVals
 * @param array<int,string>|null $pvCols
 */
function appendPaymentVoucherPurposeToInsert(array &$insertCols, array &$insertVals, $pdo, string $voucherPurpose, $pvCols = null)
{
    $purpose = normalizePaymentVoucherPurpose($voucherPurpose);

    if ($pvCols === null) {
        try {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $pvCols = [];
        }
    }

    if (in_array('purpose', $pvCols, true)) {
        $insertCols[] = 'purpose';
        $insertVals[] = $purpose;
    }
    if (in_array('payment_purpose', $pvCols, true)) {
        $insertCols[] = 'payment_purpose';
        $insertVals[] = $purpose;
    }
    if (in_array('voucher_purpose', $pvCols, true)) {
        $insertCols[] = 'voucher_purpose';
        $insertVals[] = $purpose;
    }
}

/**
 * Build UPDATE SET fragments for purpose-related columns.
 *
 * @param array<int,string> $pvCols
 * @return array{sets: array<int,string>, vals: array<int,mixed>}
 */
function buildPaymentVoucherPurposeUpdateFragments(string $voucherPurpose, array $pvCols): array
{
    $purpose = normalizePaymentVoucherPurpose($voucherPurpose);
    $sets = [];
    $vals = [];

    if (in_array('purpose', $pvCols, true)) {
        $sets[] = 'purpose = ?';
        $vals[] = $purpose;
    }
    if (in_array('payment_purpose', $pvCols, true)) {
        $sets[] = 'payment_purpose = ?';
        $vals[] = $purpose;
    }
    if (in_array('voucher_purpose', $pvCols, true)) {
        $sets[] = 'voucher_purpose = ?';
        $vals[] = $purpose;
    }

    return ['sets' => $sets, 'vals' => $vals];
}

/** User-facing error when a PV cannot be linked to a stock PO. */
function stockPurchasePoPaymentVoucherLinkErrorMessage(): string
{
    return 'The selected Payment Voucher is no longer available for stock purchase PO linking.';
}

/**
 * PDO connections that may hold payment_vouchers (stock tenant vs ERP data DB).
 *
 * @return array<int, PDO>
 */
function stockPurchasePaymentVoucherPdoCandidates($contextPdo = null)
{
    $candidates = [];
    $seen = [];

    $add = static function ($conn) use (&$candidates, &$seen) {
        if (!$conn instanceof PDO) {
            return;
        }
        $oid = spl_object_id($conn);
        if (isset($seen[$oid])) {
            return;
        }
        $seen[$oid] = true;
        $candidates[] = $conn;
    };

    if (!function_exists('balances_resolve_pdo')) {
        $balancesFile = dirname(__DIR__) . '/modules/balances/functions.php';
        if (is_file($balancesFile)) {
            require_once $balancesFile;
        }
    }
    if (function_exists('balances_collect_pdo_candidates')) {
        foreach (balances_collect_pdo_candidates() as $conn) {
            $add($conn);
        }
    } elseif (function_exists('balances_resolve_pdo')) {
        $add(balances_resolve_pdo());
    }

    if (function_exists('erp_data_pdo')) {
        try {
            $add(erp_data_pdo());
        } catch (Throwable $e) {
            // ignore
        }
    }
    $add($contextPdo);
    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $add($GLOBALS['pdo']);
    }
    global $pdo, $control_pdo;
    $add($pdo ?? null);
    if (!empty($control_pdo) && $control_pdo instanceof PDO) {
        $add($control_pdo);
    }
    if (function_exists('stock_company_pdo')) {
        try {
            $add(stock_company_pdo());
        } catch (Throwable $e) {
            // ignore
        }
    }

    $withTable = [];
    foreach ($candidates as $conn) {
        if (tableExists('payment_vouchers', $conn)) {
            $withTable[] = $conn;
        }
    }

    if ($withTable !== []) {
        return $withTable;
    }

    return $contextPdo instanceof PDO ? [$contextPdo] : [];
}

/**
 * Best PDO for payment_vouchers writes/reads in stock purchase flows.
 */
function stockPurchasePaymentVouchersPdo($contextPdo = null)
{
    $candidates = stockPurchasePaymentVoucherPdoCandidates($contextPdo);
    if ($candidates !== []) {
        return $candidates[0];
    }
    global $pdo;
    if ($contextPdo instanceof PDO) {
        return $contextPdo;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    throw new RuntimeException('No database connection available for payment vouchers.');
}

/**
 * SQL fragment: voucher purpose is stock_purchase (supports purpose and/or payment_purpose).
 *
 * @param array<int,string>|null $pvCols
 */
function buildPaymentVoucherStockPurchasePurposeWhereSql($alias = 'pv', $pvCols = null)
{
    if ($pvCols === null) {
        $pvCols = [];
    }
    $parts = [];
    foreach (['purpose', 'payment_purpose', 'voucher_purpose'] as $col) {
        if (!in_array($col, $pvCols, true)) {
            continue;
        }
        $parts[] = "LOWER(TRIM(COALESCE({$alias}.`{$col}`, ''))) = 'stock_purchase'";
        $parts[] = "REPLACE(LOWER(TRIM(COALESCE({$alias}.`{$col}`, ''))), ' ', '_') = 'stock_purchase'";
    }

    if ($parts === []) {
        return '1 = 0';
    }

    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Classification-edit picker: approved stock-purchase PVs + already linked to this PO.
 *
 * @param array<int,mixed> $params
 * @return array<int,string>
 */
function buildStockPurchasePoClassificationVoucherWhereParts(PDO $pdo, int $companyId, int $poId, array &$params, $pvCols = null)
{
    if ($pvCols === null) {
        try {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $pvCols = [];
        }
    }

    $where = ["LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'"];

    $purposeSql = buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $pvCols);
    $eligible = [];
    if ($purposeSql !== '1 = 0') {
        $eligible[] = $purposeSql;
    }
    if ($poId > 0 && in_array('linked_stock_po_id', $pvCols, true)) {
        $eligible[] = 'pv.linked_stock_po_id = ?';
        $params[] = $poId;
    }
    if ($eligible === []) {
        $where[] = '1 = 0';
    } else {
        $where[] = '(' . implode(' OR ', $eligible) . ')';
    }

    if (in_array('linked_stock_po_id', $pvCols, true)) {
        if ($poId > 0) {
            $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0 OR pv.linked_stock_po_id = ?)';
            $params[] = $poId;
        } else {
            $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)';
        }
    }

    $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
    if ($companyWhere !== null) {
        $where[] = $companyWhere;
    }

    return $where;
}

/**
 * @param array<int,string> $pvCols
 * @return array{sql: string, payeesJoin: string, suppliersJoin: string}
 */
function stockPurchasePaymentVoucherPickerQueryParts(PDO $pdo, array $pvCols): array
{
    $hasPayees = tableExists('payees', $pdo);
    $hasStocksSuppliers = tableExists('stocks_suppliers', $pdo);
    $payeeTypeSelect = $hasPayees ? "COALESCE(py.type, '') AS payee_type" : "'' AS payee_type";
    $supplierIdSelect = $hasStocksSuppliers ? 'ss.id AS supplier_id' : 'NULL AS supplier_id';
    $payeesJoin = $hasPayees
        ? ' LEFT JOIN payees py ON LOWER(TRIM(py.name)) = LOWER(TRIM(pv.payee_name))'
        : '';
    $suppliersJoin = $hasStocksSuppliers
        ? ' LEFT JOIN stocks_suppliers ss ON LOWER(TRIM(ss.name)) = LOWER(TRIM(pv.payee_name))'
        : '';
    $purposeSelect = in_array('purpose', $pvCols, true) ? "COALESCE(pv.purpose, '') AS purpose" : "'' AS purpose";
    $paymentPurposeSelect = in_array('payment_purpose', $pvCols, true) ? "COALESCE(pv.payment_purpose, '') AS payment_purpose" : "'' AS payment_purpose";
    $voucherPurposeSelect = in_array('voucher_purpose', $pvCols, true) ? "COALESCE(pv.voucher_purpose, '') AS voucher_purpose" : "'' AS voucher_purpose";
    $linkedPoSelect = in_array('linked_stock_po_id', $pvCols, true) ? 'COALESCE(pv.linked_stock_po_id, 0) AS linked_stock_po_id' : '0 AS linked_stock_po_id';
    $isPaidSelect = in_array('is_paid', $pvCols, true) ? 'COALESCE(pv.is_paid, 0) AS is_paid' : '0 AS is_paid';
    $descriptionSelect = in_array('description', $pvCols, true) ? 'pv.description AS description' : "'' AS description";
    $preparedBySelect = in_array('prepared_by', $pvCols, true) ? 'pv.prepared_by AS prepared_by' : "'' AS prepared_by";
    $dateCreatedSelect = in_array('date_created', $pvCols, true) ? 'pv.date_created AS date_created' : 'NULL AS date_created';
    $linkedSalesOrderIdSelect = in_array('linked_sales_order_id', $pvCols, true) ? 'pv.linked_sales_order_id AS linked_sales_order_id' : 'NULL AS linked_sales_order_id';
    $linkedSalesOrderIdsSelect = in_array('linked_sales_order_ids', $pvCols, true) ? 'pv.linked_sales_order_ids AS linked_sales_order_ids' : 'NULL AS linked_sales_order_ids';
    $orderBy = in_array('date_created', $pvCols, true) ? 'pv.date_created DESC, pv.id DESC' : 'pv.id DESC';

    $sql = "
        SELECT pv.id, pv.voucher_no, pv.payee_name, $payeeTypeSelect, pv.currency, pv.total_amount, pv.status,
               $isPaidSelect, $purposeSelect, $paymentPurposeSelect, $voucherPurposeSelect,
               $linkedPoSelect, $linkedSalesOrderIdSelect, $linkedSalesOrderIdsSelect,
               $descriptionSelect, $preparedBySelect, $dateCreatedSelect, $supplierIdSelect
        FROM payment_vouchers pv{$payeesJoin}{$suppliersJoin}
    ";

    return [
        'sql' => $sql,
        'orderBy' => $orderBy,
        'payeesJoin' => $payeesJoin,
        'suppliersJoin' => $suppliersJoin,
        'payeeTypeSelect' => $payeeTypeSelect,
        'supplierIdSelect' => $supplierIdSelect,
        'purposeSelect' => $purposeSelect,
        'paymentPurposeSelect' => $paymentPurposeSelect,
        'voucherPurposeSelect' => $voucherPurposeSelect,
        'linkedPoSelect' => $linkedPoSelect,
        'isPaidSelect' => $isPaidSelect,
        'descriptionSelect' => $descriptionSelect,
        'preparedBySelect' => $preparedBySelect,
        'dateCreatedSelect' => $dateCreatedSelect,
        'linkedSalesOrderIdSelect' => $linkedSalesOrderIdSelect,
        'linkedSalesOrderIdsSelect' => $linkedSalesOrderIdsSelect,
    ];
}

/**
 * Run stock-purchase voucher picker query on one PDO.
 *
 * @param array<int,string> $whereParts
 * @param array<int,mixed> $params
 * @return array<int, array<string, mixed>>
 */
function queryStockPurchasePaymentVoucherPickerRows(PDO $pvPdo, array $pvCols, array $whereParts, array $params): array
{
    if ($whereParts === []) {
        return [];
    }
    $parts = stockPurchasePaymentVoucherPickerQueryParts($pvPdo, $pvCols);
    $sql = $parts['sql'] . ' WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY ' . $parts['orderBy'] . ' LIMIT 250';
    try {
        $stmt = $pvPdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('queryStockPurchasePaymentVoucherPickerRows: ' . $e->getMessage());
    }

    $simpleSelects = [
        'pv.id',
        'pv.voucher_no',
        'pv.payee_name',
        "'' AS payee_type",
        'pv.currency',
        'pv.total_amount',
        'pv.status',
    ];
    foreach (['is_paid', 'purpose', 'payment_purpose', 'voucher_purpose', 'linked_stock_po_id', 'description', 'prepared_by', 'date_created', 'linked_sales_order_id', 'linked_sales_order_ids'] as $col) {
        if (in_array($col, $pvCols, true)) {
            $simpleSelects[] = "pv.`$col`";
        }
    }
    $simpleSql = 'SELECT ' . implode(', ', $simpleSelects) . ' FROM payment_vouchers pv WHERE '
        . implode(' AND ', $whereParts) . ' ORDER BY pv.id DESC LIMIT 250';
    try {
        $stmt = $pvPdo->prepare($simpleSql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('queryStockPurchasePaymentVoucherPickerRows simple: ' . $e->getMessage());
        return [];
    }
}

/**
 * Finance desk "Awaiting PO Link" style WHERE (approved, stock purchase, unpaid, unlinked).
 *
 * @param array<int,mixed> $params
 * @return array<int,string>
 */
function buildStockPurchaseDeskAwaitingPoWhereParts(array $pvCols, int $companyId, array &$params): array
{
    $where = [];
    $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
    if ($companyWhere !== null) {
        $where[] = $companyWhere;
    }
    $where[] = "LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'";
    if (in_array('is_paid', $pvCols, true)) {
        $where[] = 'COALESCE(pv.is_paid, 0) = 0';
    }
    $purposeSql = buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $pvCols);
    if ($purposeSql !== '1 = 0') {
        $where[] = $purposeSql;
    }
    if (in_array('linked_stock_po_id', $pvCols, true)) {
        $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)';
    }

    return $where;
}

/**
 * Approved vouchers already linked to a specific PO (any pay state).
 *
 * @param array<int,mixed> $params
 * @return array<int,string>
 */
function buildStockPurchaseDeskLinkedPoWhereParts(array $pvCols, int $poId, array &$params): array
{
    if ($poId <= 0 || !in_array('linked_stock_po_id', $pvCols, true)) {
        return ['1 = 0'];
    }
    $params[] = $poId;

    return [
        "LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'",
        'pv.linked_stock_po_id = ?',
    ];
}

/**
 * Company scope for payment_vouchers queries (tenant DB or NULL company_id rows).
 *
 * @param array<int,mixed> $params
 */
function buildPaymentVoucherCompanyScopeWhereSql($alias, array $pvCols, $companyId, array &$params)
{
    if (!in_array('company_id', $pvCols, true)) {
        return null;
    }
    if ($companyId > 0) {
        $params[] = $companyId;
        return "({$alias}.company_id IS NULL OR {$alias}.company_id = 0 OR {$alias}.company_id = ?)";
    }
    if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
        return null;
    }

    return null;
}

/**
 * Build WHERE conditions for approved, unpaid, unlinked stock-purchase PVs (PO picker).
 *
 * @param array<int,mixed> $params
 * @param array<int,string>|null $pvCols
 * @return array<int,string>
 */
function buildStockPurchasePoLinkableVoucherWhereParts(PDO $pdo, $companyId, array &$params, $pvCols = null)
{
    if ($pvCols === null) {
        try {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $pvCols = [];
        }
    }

    $where = [
        "LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'",
        buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $pvCols),
    ];

    if (in_array('is_paid', $pvCols, true) && !stockPurchasePoAllowPostedVoucherPicker()) {
        $where[] = 'COALESCE(pv.is_paid, 0) = 0';
    }
    if (in_array('linked_stock_po_id', $pvCols, true)) {
        $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)';
    }
    $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
    if ($companyWhere !== null) {
        $where[] = $companyWhere;
    }

    return $where;
}

/**
 * Whether a payment_vouchers row may be linked to a new stock PO.
 */
function paymentVoucherRowEligibleForStockPoLink(array $row, int $companyId): bool
{
    if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'approved') {
        return false;
    }
    if (
        array_key_exists('is_paid', $row)
        && (int) ($row['is_paid'] ?? 0) === 1
        && !stockPurchasePoAllowPostedVoucherPicker()
    ) {
        return false;
    }
    if ($companyId > 0 && array_key_exists('company_id', $row)) {
        $rowCompanyId = (int) ($row['company_id'] ?? 0);
        if ($rowCompanyId > 0 && $rowCompanyId !== $companyId) {
            return false;
        }
    }
    if (resolvePaymentVoucherPurposeFromRow($row) !== 'stock_purchase') {
        return false;
    }
    if ((int) ($row['linked_stock_po_id'] ?? 0) > 0) {
        return false;
    }

    return true;
}

/**
 * Load and validate a payment voucher for stock PO linking.
 *
 * @return array{ok: bool, message: string, row: ?array}
 */
function validatePaymentVoucherForStockPoLink(PDO $pdo, int $voucherId, int $companyId): array
{
    $fail = [
        'ok' => false,
        'message' => stockPurchasePoPaymentVoucherLinkErrorMessage(),
        'row' => null,
    ];

    if ($voucherId <= 0) {
        return $fail;
    }

    foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $pvPdo) {
        try {
            $pvCols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            continue;
        }

        $selects = [
            'pv.id',
            'pv.voucher_no',
            'pv.status',
            'pv.payee_name',
            'pv.currency',
            'pv.total_amount',
        ];
        foreach (['purpose', 'payment_purpose', 'voucher_purpose', 'is_paid', 'linked_stock_po_id', 'company_id', 'description', 'date_created', 'prepared_by', 'linked_sales_order_id', 'linked_sales_order_ids'] as $col) {
            if (in_array($col, $pvCols, true)) {
                $selects[] = "pv.`$col`";
            }
        }

        $params = [$voucherId];
        $where = ['pv.id = ?'];
        $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
        if ($companyWhere !== null) {
            $where[] = $companyWhere;
        }

        $sql = 'SELECT ' . implode(', ', $selects)
            . ' FROM payment_vouchers pv WHERE ' . implode(' AND ', $where)
            . ' LIMIT 1';

        try {
            $stmt = $pvPdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            continue;
        }

        if ($row && paymentVoucherRowEligibleForStockPoLink($row, $companyId)) {
            return ['ok' => true, 'message' => '', 'row' => $row];
        }
    }

    return $fail;
}

/**
 * Approved, unpaid, unlinked stock-purchase payment vouchers for PO create picker.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockPurchasePoLinkableVouchers(PDO $pdo, int $companyId): array
{
    $byId = [];
    foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $pvPdo) {
        try {
            $pvCols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            continue;
        }

        $params = [];
        $whereParts = buildStockPurchasePoLinkableVoucherWhereParts($pvPdo, $companyId, $params, $pvCols);
        if ($whereParts === []) {
            continue;
        }

        foreach (queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereParts, $params) as $pv) {
            $id = (int) ($pv['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $pv;
            }
        }
    }

    return array_values($byId);
}

/**
 * Dropdown label for stock-purchase PO voucher picker.
 */
function formatStockPurchasePoVoucherOptionLabel(array $pv): string
{
    $vno = trim((string) ($pv['voucher_no'] ?? ''));
    if ($vno === '') {
        $vno = 'PV-' . (int) ($pv['id'] ?? 0);
    }
    $payee = trim((string) ($pv['payee_name'] ?? 'Unknown Payee'));
    $currency = trim((string) ($pv['currency'] ?? ''));
    $amount = number_format((float) ($pv['total_amount'] ?? 0), 0, '.', ',');

    return $vno . ' - ' . $payee . ' - ' . $currency . ' ' . $amount . ' - Stock Purchase';
}

/**
 * PDO used for per-company settings in stock / tenant context.
 */
function stockPurchaseCompanySettingsPdo()
{
    global $pdo, $control_pdo;
    if (function_exists('stock_company_pdo')) {
        $tenant = stock_company_pdo();
        if ($tenant instanceof PDO) {
            return $tenant;
        }
    }
    if (function_exists('erp_data_pdo')) {
        $erp = erp_data_pdo();
        if ($erp instanceof PDO) {
            return $erp;
        }
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    return ($control_pdo instanceof PDO) ? $control_pdo : null;
}

/**
 * Read a company setting from the tenant DB first (stock purchase workflow keys).
 */
function getStockPurchaseCompanySetting(string $settingKey, $default = null)
{
    $cid = (int) (currentCompanyId() ?? 0);
    if ($cid <= 0) {
        return $default;
    }
    $settingsPdo = stockPurchaseCompanySettingsPdo();
    if ($settingsPdo instanceof PDO) {
        $settings = fetchCompanySettingsMap($settingsPdo, $cid);
        if (array_key_exists($settingKey, $settings)) {
            return $settings[$settingKey];
        }
    }
    return getCompanySetting($settingKey, $default);
}

/**
 * Include posted/paid approved stock-purchase vouchers in PO picker when both workflow flags are enabled.
 */
function stockPurchasePoAllowPostedVoucherPicker(): bool
{
    $workflowEnabled = getStockPurchaseCompanySetting('approval_workflow_enabled', '0') === '1';
    $limitedEditEnabled = getStockPurchaseCompanySetting('allow_edit_approved_voucher_classification', '0') === '1';
    return $workflowEnabled && $limitedEditEnabled;
}

/**
 * Whether company setting allows admin/procurement to fix PO type and payment voucher links.
 */
function isStockPurchasePoClassificationEditEnabled(): bool
{
    return getStockPurchaseCompanySetting('stock_purchase_allow_po_classification_edit', '0') === '1';
}

/**
 * Roles allowed to edit PO classification (internal/abroad + voucher links) from settings.
 *
 * @return array<int, string>
 */
function stockPurchasePoClassificationEditRoles(): array
{
    $raw = trim((string) getStockPurchaseCompanySetting('stock_purchase_po_edit_roles', 'admin,procurement'));
    if ($raw === '') {
        return ['admin', 'procurement'];
    }
    $roles = array_values(array_filter(array_map(static function ($r) {
        return strtolower(trim((string) $r));
    }, preg_split('/\s*,\s*/', $raw) ?: [])));
    return $roles !== [] ? $roles : ['admin', 'procurement'];
}

/**
 * May the current user edit PO type / voucher linkage from Stock Purchase settings?
 */
function canEditStockPurchasePoClassification(): bool
{
    if (!isStockPurchasePoClassificationEditEnabled()) {
        return false;
    }
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return true;
    }
    $allowed = stockPurchasePoClassificationEditRoles();
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if ($role !== '' && in_array($role, $allowed, true)) {
        return true;
    }
    if (function_exists('isAdmin') && isAdmin() && in_array('admin', $allowed, true)) {
        return true;
    }
    if (function_exists('isCompanyAdmin') && isCompanyAdmin() && in_array('company_admin', $allowed, true)) {
        return true;
    }
    return false;
}

/**
 * PO statuses where type + voucher linkage may be corrected (not fully closed).
 *
 * @return array<int, string>
 */
function stockPurchasePoClassificationEditableStatuses(): array
{
    $statuses = [
        'Draft',
        'Pending',
        'Pending Supplier',
        'Pending Approval',
        'Supplier Responded',
        'Negotiation Requested',
        'Negotiation',
        'Approved',
    ];
    if (isStockPurchasePoClassificationEditEnabled()) {
        $statuses[] = 'Received';
    }
    return $statuses;
}

/**
 * Whether PO type / voucher linkage may be edited for this status (settings enabled).
 */
function canEditStockPurchasePoClassificationForStatus($status)
{
    if (!canEditStockPurchasePoClassification()) {
        return false;
    }
    $status = trim((string) $status);
    if ($status === '' || strcasecmp($status, 'Cancelled') === 0) {
        return false;
    }
    foreach (stockPurchasePoClassificationEditableStatuses() as $allowedStatus) {
        if (strcasecmp($status, $allowedStatus) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Case-insensitive status check for PO classification updates.
 */
function stockPurchasePoStatusAllowsClassificationEdit($status)
{
    return canEditStockPurchasePoClassificationForStatus($status);
}

/**
 * Vouchers for classification edit picker (linkable + already linked to this PO, including paid).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockPurchasePoVouchersForClassificationEdit(PDO $pdo, int $companyId, int $poId, array $linkedIds = []): array
{
    $byId = [];
    $mergeRow = static function (array $pv) use (&$byId) {
        $id = (int) ($pv['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $pv;
        }
    };

    foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $pvPdo) {
        try {
            $pvCols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            continue;
        }
        if ($pvCols === []) {
            continue;
        }

        $paramsAwaiting = [];
        $whereAwaiting = buildStockPurchaseDeskAwaitingPoWhereParts($pvCols, $companyId, $paramsAwaiting);
        if ($whereAwaiting !== []) {
            foreach (queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereAwaiting, $paramsAwaiting) as $pv) {
                $mergeRow($pv);
            }
        }

        if ($poId > 0) {
            $paramsLinked = [];
            $whereLinked = buildStockPurchaseDeskLinkedPoWhereParts($pvCols, $poId, $paramsLinked);
            foreach (queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereLinked, $paramsLinked) as $pv) {
                $mergeRow($pv);
            }
        }

        if ($linkedIds !== []) {
            $missing = array_values(array_filter(array_map('intval', $linkedIds), static function ($id) use ($byId) {
                return $id > 0 && !isset($byId[$id]);
            }));
            if ($missing !== []) {
                $placeholders = implode(',', array_fill(0, count($missing), '?'));
                $paramsById = $missing;
                $whereById = ["pv.id IN ($placeholders)"];
                foreach (queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereById, $paramsById) as $pv) {
                    $mergeRow($pv);
                }
            }
        }

        if ($byId === []) {
            $paramsFallback = [];
            $whereFallback = ["LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'"];
            if ($poId > 0 && in_array('linked_stock_po_id', $pvCols, true)) {
                $whereFallback[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0 OR pv.linked_stock_po_id = ?)';
                $paramsFallback[] = $poId;
            }
            foreach (queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereFallback, $paramsFallback) as $pv) {
                $purpose = resolvePaymentVoucherPurposeFromRow($pv);
                $linkedPo = (int) ($pv['linked_stock_po_id'] ?? 0);
                if ($purpose !== 'stock_purchase' && $linkedPo !== $poId) {
                    continue;
                }
                $mergeRow($pv);
            }
        }
    }

    return array_values($byId);
}

/**
 * Human label for stocks_purchase_orders.purchase_type.
 */
function stockPurchasePoTypeLabel($purchaseType)
{
    return ($purchaseType ?? 'domestic') === 'import' ? 'Abroad' : 'Internal';
}

/**
 * @param array<int,mixed> $params
 * @return array<int,string>
 */
function buildStockPurchasePoLinkableVoucherWherePartsForPo(PDO $pdo, $companyId, $poId, array &$params, $pvCols = null, $classificationEditPicker = false)
{
    if ($pvCols === null) {
        try {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $pvCols = [];
        }
    }

    $where = [
        "LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'",
        buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $pvCols),
    ];

    if (!$classificationEditPicker && in_array('is_paid', $pvCols, true)) {
        $where[] = 'COALESCE(pv.is_paid, 0) = 0';
    }
    if (in_array('linked_stock_po_id', $pvCols, true)) {
        if ($poId > 0) {
            $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0 OR pv.linked_stock_po_id = ?)';
            $params[] = $poId;
        } else {
            $where[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)';
        }
    }
    $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
    if ($companyWhere !== null) {
        $where[] = $companyWhere;
    }

    return $where;
}

/**
 * Approved stock-purchase vouchers for PO admin edit (includes vouchers already linked to this PO).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockPurchasePoLinkableVouchersForPo(PDO $pdo, int $companyId, int $poId): array
{
    if ($poId <= 0) {
        return fetchStockPurchasePoLinkableVouchers($pdo, $companyId);
    }

    try {
        $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $params = [];
    $whereParts = buildStockPurchasePoLinkableVoucherWherePartsForPo($pdo, $companyId, $poId, $params, $pvCols);
    if ($whereParts === []) {
        return [];
    }

    $linkedPoSelect = in_array('linked_stock_po_id', $pvCols, true)
        ? 'COALESCE(pv.linked_stock_po_id, 0) AS linked_stock_po_id'
        : '0 AS linked_stock_po_id';

    $sql = "
        SELECT pv.id, pv.voucher_no, pv.payee_name, pv.currency, pv.total_amount, pv.status,
               COALESCE(pv.is_paid, 0) AS is_paid,
               $linkedPoSelect
        FROM payment_vouchers pv
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY pv.id DESC
        LIMIT 250
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('fetchStockPurchasePoLinkableVouchersForPo: ' . $e->getMessage());
        return [];
    }
}

/**
 * Load a purchase order by id (stocks_purchase_orders, then legacy purchases).
 *
 * @return array<string, mixed>|null Row includes _po_table: stocks_purchase_orders|purchases
 */
function fetchStockPurchaseOrderById(PDO $pdo, $poId, $withSupplierName = true)
{
    if ($poId <= 0) {
        return null;
    }

    if (tableExists('stocks_purchase_orders', $pdo)) {
        try {
            $sql = $withSupplierName
                ? 'SELECT po.*, ss.name AS supplier_name
                   FROM stocks_purchase_orders po
                   LEFT JOIN stocks_suppliers ss ON ss.id = po.supplier_id
                   WHERE po.id = ? LIMIT 1'
                : 'SELECT * FROM stocks_purchase_orders WHERE id = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$poId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['_po_table'] = 'stocks_purchase_orders';
                return $row;
            }
        } catch (Throwable $e) {
            error_log('fetchStockPurchaseOrderById stocks: ' . $e->getMessage());
        }
    }

    if (!tableExists('purchases', $pdo)) {
        return null;
    }

    try {
        $hasLegacySuppliers = tableExists('suppliers', $pdo);
        if ($withSupplierName) {
            $supplierNameExpr = $hasLegacySuppliers
                ? "COALESCE(ss.name, ls.name, CONCAT('Supplier #', p.supplier_id))"
                : "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))";
            $sql = "SELECT p.*, {$supplierNameExpr} AS supplier_name
                    FROM purchases p
                    LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
            if ($hasLegacySuppliers) {
                $sql .= ' LEFT JOIN suppliers ls ON p.supplier_id = ls.id';
            }
        } else {
            $sql = 'SELECT p.* FROM purchases p';
        }
        $sql .= ' WHERE p.id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$poId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['_po_table'] = 'purchases';
        $row['po_number'] = $row['po_number'] ?? $row['purchase_no'] ?? ('PO-' . $poId);
        $row['purchase_no'] = $row['purchase_no'] ?? $row['po_number'];
        $row['purchase_type'] = $row['purchase_type'] ?? 'domestic';
        $row['status'] = $row['status'] ?? 'Pending';
        $row['currency'] = $row['currency'] ?? 'TZS';
        $row['exchange_rate'] = $row['exchange_rate'] ?? 1;
        return $row;
    } catch (Throwable $e) {
        error_log('fetchStockPurchaseOrderById legacy: ' . $e->getMessage());
        return null;
    }
}

/**
 * Line items for PO edit/display (stocks_po_items or legacy purchase_items).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockPurchaseOrderLineItems(PDO $pdo, $poId, $poTable = null)
{
    if ($poId <= 0) {
        return [];
    }
    $poTable = $poTable ?: 'stocks_purchase_orders';

    if ($poTable === 'purchases' && tableExists('purchase_items', $pdo)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT product_id, quantity, unit_price FROM purchase_items WHERE purchase_id = ? ORDER BY id ASC'
            );
            $stmt->execute([$poId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['unit_price_is_display'] = true;
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            error_log('fetchStockPurchaseOrderLineItems legacy: ' . $e->getMessage());
            return [];
        }
    }

    if (!tableExists('stocks_po_items', $pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT item_id AS product_id, qty_ordered AS quantity, unit_cost AS unit_price
             FROM stocks_po_items WHERE po_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            // unit_cost is stored in base (USD); caller converts with exchange_rate for display.
            $row['unit_price_is_display'] = false;
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('fetchStockPurchaseOrderLineItems stocks: ' . $e->getMessage());
        return [];
    }
}

/**
 * Parse payment_voucher_ids JSON/column from a PO row.
 *
 * @return array<int, int>
 */
function parseStockPurchasePoLinkedVoucherIds(array $poRow): array
{
    $ids = [];
    $raw = trim((string) ($poRow['payment_voucher_ids'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        } else {
            foreach (preg_split('/\s*,\s*/', $raw) as $token) {
                $id = (int) $token;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
    }
    $single = (int) ($poRow['payment_voucher_id'] ?? 0);
    if ($single > 0) {
        $ids[$single] = $single;
    }
    return array_values($ids);
}

/**
 * Classification update for legacy purchases table rows.
 *
 * @param array<int,int> $voucherIds
 * @return array{ok: bool, message: string}
 */
function updateLegacyPurchasePoClassification(PDO $pdo, int $companyId, int $poId, array $po, string $purchaseType, array $voucherIds): array
{
    if (!tableExists('purchases', $pdo)) {
        return ['ok' => false, 'message' => 'Purchase order not found.'];
    }

    if (!stockPurchasePoStatusAllowsClassificationEdit((string) ($po['status'] ?? ''))) {
        return ['ok' => false, 'message' => 'This purchase order status cannot be updated from settings.'];
    }

    foreach ($voucherIds as $pvId) {
        $check = validatePaymentVoucherForStockPoLink($pdo, $pvId, $companyId);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message']];
        }
    }

    try {
        $poCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $pdo->beginTransaction();
        $sets = [];
        $vals = [];
        if (in_array('purchase_type', $poCols, true)) {
            $sets[] = 'purchase_type = ?';
            $vals[] = $purchaseType;
        }
        if (in_array('updated_at', $poCols, true)) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($sets !== []) {
            $pdo->prepare('UPDATE purchases SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute(array_merge($vals, [$poId]));
        }

        if (tableExists('payment_vouchers', $pdo)) {
            $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('linked_stock_po_id', $pvCols, true)) {
                $pdo->prepare('UPDATE payment_vouchers SET linked_stock_po_id = NULL WHERE linked_stock_po_id = ?')->execute([$poId]);
                if (!empty($voucherIds)) {
                    $linkStmt = $pdo->prepare('UPDATE payment_vouchers SET linked_stock_po_id = ? WHERE id = ?');
                    foreach ($voucherIds as $pvLinkId) {
                        $linkStmt->execute([$poId, $pvLinkId]);
                    }
                }
            }
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Purchase order updated. Finance can process linked vouchers on the Stock Purchase Payment Desk.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('updateLegacyPurchasePoClassification: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
    }
}

/**
 * Update PO purchase type and payment voucher links (settings / procurement correction).
 *
 * @param array<int,int> $voucherIds
 * @param string|null $currencyCode
 * @return array{ok: bool, message: string}
 */
function updateStockPurchasePoClassification(PDO $pdo, int $companyId, int $poId, string $purchaseType, array $voucherIds, ?string $currencyCode = null): array
{
    if (!canEditStockPurchasePoClassification()) {
        return ['ok' => false, 'message' => 'You are not allowed to edit purchase order classification.'];
    }
    if ($poId <= 0) {
        return ['ok' => false, 'message' => 'Invalid purchase order.'];
    }
    if (!in_array($purchaseType, ['domestic', 'import'], true)) {
        $purchaseType = 'domestic';
    }
    $currencyCode = strtoupper(trim((string) $currencyCode));
    if ($currencyCode === '') {
        $currencyCode = null;
    }
    $allowedCurrencies = ['USD', 'TZS', 'EUR', 'GBP', 'KES'];
    if ($currencyCode !== null && !in_array($currencyCode, $allowedCurrencies, true)) {
        $currencyCode = null;
    }

    $voucherIds = array_values(array_unique(array_filter(array_map('intval', $voucherIds), static function ($id) {
        return $id > 0;
    })));

    try {
        if (!tableExists('stocks_purchase_orders', $pdo)) {
            return ['ok' => false, 'message' => 'Purchase orders table is not available.'];
        }

        $po = fetchStockPurchaseOrderById($pdo, $poId, false);
        if (!$po) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        $poTable = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');
        if ($poTable === 'purchases') {
            return updateLegacyPurchasePoClassification($pdo, $companyId, $poId, $po, $purchaseType, $voucherIds);
        }
        unset($po['_po_table']);

        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $poHasCompanyId = in_array('company_id', $poCols, true);
        if ($poHasCompanyId && $companyId > 0) {
            $poCompanyId = (int) ($po['company_id'] ?? 0);
            if ($poCompanyId > 0 && $poCompanyId !== $companyId) {
                return ['ok' => false, 'message' => 'Purchase order not found.'];
            }
        }

        if (!stockPurchasePoStatusAllowsClassificationEdit((string) ($po['status'] ?? ''))) {
            return ['ok' => false, 'message' => 'This purchase order status cannot be updated from settings.'];
        }

        foreach ($voucherIds as $pvId) {
            $check = validatePaymentVoucherForStockPoLink($pdo, $pvId, $companyId);
            if ($check['ok']) {
                continue;
            }
            $pvRow = null;
            foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $pvPdo) {
                if (!tableExists('payment_vouchers', $pvPdo)) {
                    continue;
                }
                $pvCols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $params = [$pvId];
                $where = ['pv.id = ?'];
                $companyWhere = buildPaymentVoucherCompanyScopeWhereSql('pv', $pvCols, $companyId, $params);
                if ($companyWhere !== null) {
                    $where[] = $companyWhere;
                }
                $stmtPv = $pvPdo->prepare('SELECT * FROM payment_vouchers pv WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
                $stmtPv->execute($params);
                $pvRow = $stmtPv->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($pvRow) {
                    break;
                }
            }
            if (!$pvRow) {
                return ['ok' => false, 'message' => $check['message']];
            }
            $linkedPo = (int) ($pvRow['linked_stock_po_id'] ?? 0);
            $alreadyOnThisPo = $linkedPo === $poId;
            if ($linkedPo > 0 && !$alreadyOnThisPo) {
                return ['ok' => false, 'message' => 'One or more vouchers are already linked to another purchase order.'];
            }
            if (resolvePaymentVoucherPurposeFromRow($pvRow) !== 'stock_purchase') {
                return ['ok' => false, 'message' => 'Only Stock Purchase payment vouchers can be linked.'];
            }
            if (!$alreadyOnThisPo) {
                if (strtolower(trim((string) ($pvRow['status'] ?? ''))) !== 'approved') {
                    return ['ok' => false, 'message' => 'Only approved, unpaid stock purchase vouchers can be linked.'];
                }
                if ((int) ($pvRow['is_paid'] ?? 0) === 1) {
                    return ['ok' => false, 'message' => 'Cannot link a voucher that is already marked paid.'];
                }
            }
        }

        $pdo->beginTransaction();

        $sets = [];
        $vals = [];
        if (in_array('purchase_type', $poCols, true)) {
            $sets[] = 'purchase_type = ?';
            $vals[] = $purchaseType;
        }
        if (in_array('payment_voucher_id', $poCols, true)) {
            $sets[] = 'payment_voucher_id = ?';
            $vals[] = !empty($voucherIds) ? (int) $voucherIds[0] : null;
        }
        if (in_array('payment_voucher_ids', $poCols, true)) {
            $sets[] = 'payment_voucher_ids = ?';
            $vals[] = !empty($voucherIds) ? json_encode($voucherIds) : null;
        }
        if ($currencyCode !== null && in_array('currency', $poCols, true)) {
            $sets[] = 'currency = ?';
            $vals[] = $currencyCode;
        }
        if (in_array('updated_at', $poCols, true)) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($sets !== []) {
            $pdo->prepare(
                'UPDATE stocks_purchase_orders SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute(array_merge($vals, [$poId]));
        }

        $pvPdo = stockPurchasePaymentVouchersPdo($pdo);
        $pvCols = tableExists('payment_vouchers', $pvPdo)
            ? $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: []
            : [];
        if (in_array('linked_stock_po_id', $pvCols, true)) {
            $pvPdo->prepare('UPDATE payment_vouchers SET linked_stock_po_id = NULL WHERE linked_stock_po_id = ?')
                ->execute([$poId]);
            if (!empty($voucherIds)) {
                $linkStmt = $pvPdo->prepare('UPDATE payment_vouchers SET linked_stock_po_id = ? WHERE id = ?');
                foreach ($voucherIds as $pvLinkId) {
                    $linkStmt->execute([$poId, $pvLinkId]);
                }
            }
        }

        $pdo->commit();

        $syncMessage = '';
        if (!empty($voucherIds) && in_array('supplier_id', $poCols, true)) {
            $workflowPath = dirname(__DIR__) . '/stock/modules/purchases/purchase_workflow.php';
            if (is_file($workflowPath)) {
                require_once $workflowPath;
                if (function_exists('stockPurchaseSyncPoSupplierFromVouchers')) {
                    $sync = stockPurchaseSyncPoSupplierFromVouchers($pdo, $poId, $companyId);
                    if (!empty($sync['changed'])) {
                        $syncMessage = ' ' . (string) ($sync['message'] ?? '');
                    }
                }
            }
        }

        return ['ok' => true, 'message' => 'Purchase order updated. Finance can process linked vouchers on the Stock Purchase Payment Desk.' . $syncMessage];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('updateStockPurchasePoClassification: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
    }
}

/**
 * Short description preview for PO voucher picker.
 */
function stockPurchasePoVoucherDescriptionPreview(array $pv, int $maxLen = 60): string
{
    $text = trim((string) ($pv['description'] ?? ''));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
    }
    if (strlen($text) <= $maxLen) {
        return $text;
    }
    return rtrim(substr($text, 0, $maxLen - 1)) . '…';
}

/**
 * Whether the voucher has a linked sales quotation/order reference.
 */
function paymentVoucherHasLinkedQuotation(array $pv): bool
{
    if ((int) ($pv['linked_sales_order_id'] ?? 0) > 0) {
        return true;
    }
    $idsJson = trim((string) ($pv['linked_sales_order_ids'] ?? ''));
    if ($idsJson === '') {
        return false;
    }
    $decoded = json_decode($idsJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $idVal) {
            if ((int) $idVal > 0) {
                return true;
            }
        }
        return false;
    }
    foreach (preg_split('/\s*,\s*/', $idsJson) as $idVal) {
        if ((int) $idVal > 0) {
            return true;
        }
    }

    return false;
}

// Ensure stock-purchase linking columns exist on vouchers and stock POs
function ensureVoucherStockPurchaseSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    // payment_vouchers.purpose
    try {
        $pdo->query("SELECT purpose FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN purpose VARCHAR(40) NOT NULL DEFAULT 'general' AFTER checked_by");
        } catch (PDOException $e2) { /* ignore */
        }
    }

    // payment_vouchers.linked_stock_po_id
    try {
        $pdo->query("SELECT linked_stock_po_id FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN linked_stock_po_id INT NULL AFTER payment_account_id");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD INDEX idx_pv_linked_stock_po_id (linked_stock_po_id)");
        } catch (PDOException $e3) { /* ignore */
        }
    }

    // stocks_purchase_orders.payment_voucher_id
    try {
        $pdo->query("SELECT payment_voucher_id FROM stocks_purchase_orders LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN payment_voucher_id INT NULL AFTER supplier_id");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE stocks_purchase_orders ADD INDEX idx_spo_payment_voucher_id (payment_voucher_id)");
        } catch (PDOException $e3) { /* ignore */
        }
    }

    $ensured = true;
}

// Ensure custom tasks table exists
ensureTasksSchema();

// Ensure schema patch is applied early for pages that reference the field
ensureCheckedByColumnOnPaymentVouchers();
ensurePaidColumnsOnPaymentVouchers();
ensureVoucherStockPurchaseSchema();
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

// -------------- Payment vouchers core schema (required for admin dashboard) --------------
function ensurePaymentVouchersCoreSchema($explicitPdo = null)
{
    global $pdo;
    $usePdo = $explicitPdo ?? $pdo;
    if (!($usePdo instanceof PDO)) {
        return false;
    }
    static $completed = array();
    $token = pdoInstanceToken($usePdo);
    if (isset($completed[$token])) {
        return true;
    }

    try {
        if (!tableExists('payment_vouchers', $usePdo)) {
            $usePdo->exec("
                CREATE TABLE IF NOT EXISTS payment_vouchers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NULL,
                    voucher_no VARCHAR(20) NOT NULL,
                    payee_name VARCHAR(100) NOT NULL DEFAULT '',
                    description TEXT NULL,
                    currency VARCHAR(10) NOT NULL DEFAULT 'TZS',
                    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    supporting_documents INT NOT NULL DEFAULT 0,
                    applicant VARCHAR(50) NULL,
                    department_manager VARCHAR(50) NULL,
                    general_manager VARCHAR(50) NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'confirming',
                    created_by INT NOT NULL DEFAULT 1,
                    date_created DATE NOT NULL,
                    prepared_by VARCHAR(50) NULL,
                    checked_by VARCHAR(50) NULL,
                    is_paid TINYINT(1) NOT NULL DEFAULT 0,
                    paid_by INT NULL,
                    paid_at TIMESTAMP NULL,
                    swift_document VARCHAR(300) NULL,
                    is_posted TINYINT(1) NOT NULL DEFAULT 0,
                    posted_by INT NULL,
                    posted_at TIMESTAMP NULL,
                    payment_account_id INT NULL,
                    approved_by INT NULL,
                    approved_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_payment_vouchers_no (voucher_no),
                    KEY idx_payment_vouchers_company (company_id),
                    KEY idx_payment_vouchers_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } elseif (!columnExists('payment_vouchers', 'company_id', $usePdo)) {
            $usePdo->exec('ALTER TABLE payment_vouchers ADD COLUMN company_id INT NULL AFTER id');
        }

        if (!tableExists('voucher_items', $usePdo)) {
            $usePdo->exec("
                CREATE TABLE IF NOT EXISTS voucher_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    voucher_id INT NOT NULL,
                    payment_type VARCHAR(50) NOT NULL DEFAULT '',
                    budget_type VARCHAR(50) NOT NULL DEFAULT '',
                    name VARCHAR(100) NOT NULL DEFAULT '',
                    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    description TEXT NULL,
                    KEY idx_voucher_items_voucher (voucher_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!tableExists('approval_logs', $usePdo)) {
            $usePdo->exec("
                CREATE TABLE IF NOT EXISTS approval_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    voucher_id INT NOT NULL,
                    user_id INT NOT NULL,
                    action VARCHAR(30) NOT NULL,
                    comments TEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_approval_logs_voucher (voucher_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $completed[$token] = true;
        return true;
    } catch (Throwable $e) {
        error_log('ensurePaymentVouchersCoreSchema: ' . $e->getMessage());
        return false;
    }
}

// -------------- Voucher attachments schema & helpers --------------
function ensureVoucherAttachmentsSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;
    ensurePaymentVouchersCoreSchema($pdo);
    // Table to store individual uploaded supporting documents per voucher.
    // Some shared-hosting DBs have mismatched FK column definitions (errno 150),
    // so we try strict FK creation first, then fallback to a non-FK table.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS voucher_attachments (\n        id INT AUTO_INCREMENT PRIMARY KEY,\n        voucher_id INT NOT NULL,\n        file_path VARCHAR(300) NOT NULL,\n        original_name VARCHAR(255) NOT NULL,\n        mime_type VARCHAR(150) NOT NULL,\n        size_bytes INT NOT NULL DEFAULT 0,\n        uploaded_by INT NOT NULL,\n        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n        INDEX(voucher_id),\n        INDEX(uploaded_by),\n        FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id) ON DELETE CASCADE,\n        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {
        error_log('ensureVoucherAttachmentsSchema FK create failed: ' . $e->getMessage());
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS voucher_attachments (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            voucher_id INT NOT NULL,\n            file_path VARCHAR(300) NOT NULL,\n            original_name VARCHAR(255) NOT NULL,\n            mime_type VARCHAR(150) NOT NULL,\n            size_bytes INT NOT NULL DEFAULT 0,\n            uploaded_by INT NULL,\n            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            INDEX(voucher_id),\n            INDEX(uploaded_by)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (PDOException $e2) {
            error_log('ensureVoucherAttachmentsSchema fallback create failed: ' . $e2->getMessage());
        }
    }
    $ensured = true;
}

/** Ensure approvals table exists (required by all-vouchers and voucher workflow). */
function ensureApprovalsTableSchema()
{
    global $pdo;
    static $ensuredApprovals = false;
    if ($ensuredApprovals) {
        return;
    }
    if (!($pdo instanceof PDO)) {
        return;
    }
    ensurePaymentVouchersCoreSchema($pdo);
    if (tableExists('approvals', $pdo)) {
        $ensuredApprovals = true;
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            voucher_id INT NOT NULL,
            approver_id INT NULL,
            approver_name VARCHAR(100) NOT NULL,
            role VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            signature_path VARCHAR(300) NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            company_id INT NULL,
            INDEX idx_approvals_voucher (voucher_id),
            INDEX idx_approvals_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureApprovalsTableSchema: ' . $e->getMessage());
    }
    $ensuredApprovals = true;
}

// Ensure swift_document column on payment_vouchers (stores a single proof of payment file path)
function ensureSwiftDocumentColumn()
{
    global $pdo;
    static $ensuredSwift = false;
    if ($ensuredSwift)
        return;
    ensurePaymentVouchersCoreSchema($pdo);
    if (!tableExists('payment_vouchers', $pdo)) {
        return;
    }
    try {
        $pdo->query("SELECT swift_document FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN swift_document VARCHAR(300) NULL AFTER paid_at");
        } catch (PDOException $e2) { /* ignore */
        }
    }
    $ensuredSwift = true;
}

// Ensure posted bookkeeping columns on payment_vouchers
function ensurePostedColumnsOnPaymentVouchers()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;
    ensurePaymentVouchersCoreSchema($pdo);
    if (!tableExists('payment_vouchers', $pdo)) {
        return;
    }
    try {
        $pdo->query("SELECT is_posted, posted_by, posted_at, payment_account_id FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN is_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER swift_document");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN posted_by INT NULL AFTER is_posted");
        } catch (PDOException $e3) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN posted_at TIMESTAMP NULL AFTER posted_by");
        } catch (PDOException $e4) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN payment_account_id INT NULL AFTER posted_at");
        } catch (PDOException $e5) { /* ignore */
        }
        
        // Add FKs
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_payment_vouchers_posted_by FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL");
        } catch (PDOException $e6) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_payment_vouchers_pay_acc FOREIGN KEY (payment_account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL");
        } catch (PDOException $e7) { /* ignore */
        }
    }
    $ensured = true;
}

// -------------- Meetings schema (tables for meetings, participants, and signaling) --------------
function ensureMeetingsSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;
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
        try {
            $pdo->query("SELECT is_locked FROM meetings LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meetings ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meetings.host_id
        try {
            $pdo->query("SELECT host_id FROM meetings LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meetings ADD COLUMN host_id INT NULL AFTER created_by, ADD CONSTRAINT fk_meetings_host FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE SET NULL");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meetings.start_time
        try {
            $pdo->query("SELECT start_time FROM meetings LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meetings ADD COLUMN start_time DATETIME NULL AFTER scheduled_time");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meetings.end_time
        try {
            $pdo->query("SELECT end_time FROM meetings LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meetings ADD COLUMN end_time DATETIME NULL AFTER start_time");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meeting_participants.peer_id
        try {
            $pdo->query("SELECT peer_id FROM meeting_participants LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN peer_id VARCHAR(100) NULL AFTER user_id, ADD INDEX(peer_id)");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meeting_participants.role
        try {
            $pdo->query("SELECT role FROM meeting_participants LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN role ENUM('host','guest') DEFAULT 'guest' AFTER peer_id");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meeting_participants.is_video_on
        try {
            $pdo->query("SELECT is_video_on FROM meeting_participants LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meeting_participants ADD COLUMN is_video_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_muted");
            } catch (PDOException $e2) { /* ignore */
            }
        }
        // Probe meeting_signals.created_at
        try {
            $pdo->query("SELECT created_at FROM meeting_signals LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE meeting_signals ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER signal_data");
            } catch (PDOException $e2) { /* ignore */
            }
        }

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
        if (function_exists('error_log')) {
            error_log('ensureMeetingsSchema error: ' . $ex->getMessage());
        }
    }
    $ensured = true;
}

// Get chat messages for a meeting
function getMeetingChatMessages($meeting_id, $limit = 50)
{
    global $pdo;
    ensureMeetingsSchema();
    $stmt = $pdo->prepare("SELECT mcm.*, u.full_name 
        FROM meeting_chat_messages mcm
        JOIN users u ON mcm.user_id = u.id
        WHERE mcm.meeting_id = ?
        ORDER BY mcm.sent_at ASC
        LIMIT ?");
    $stmt->bindValue(1, (int) $meeting_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Send a chat message in a meeting
function sendMeetingChatMessage($meeting_id, $user_id, $message)
{
    global $pdo;
    ensureMeetingsSchema();
    $stmt = $pdo->prepare("INSERT INTO meeting_chat_messages (meeting_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([(int) $meeting_id, (int) $user_id, trim($message)]);
    return $pdo->lastInsertId();
}


$__perfFunctions = __DIR__ . '/../modules/performance/includes/performance_functions.php';
if (is_file($__perfFunctions)) {
    require_once $__perfFunctions;
}

// Ensure Purchasing Schema
function ensurePurchasingSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    if (!$pdo) {
        if (function_exists('error_log')) {
            error_log('ensurePurchasingSchema error: Global $pdo is null or invalid.');
        }
        return; // Fail gracefully
    }

    try {
        // Suppliers Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(255),
            email VARCHAR(255),
            phone VARCHAR(50),
            address TEXT,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Purchase Orders Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) NOT NULL UNIQUE,
            supplier_id INT NOT NULL,
            order_date DATE,
            expected_date DATE,
            total_amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'draft',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES erp_suppliers(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Purchase Order Items Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_purchase_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity DECIMAL(10,2),
            unit_cost DECIMAL(10,2),
            total_cost DECIMAL(10,2),
            FOREIGN KEY (po_id) REFERENCES erp_purchase_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    } catch (Throwable $ex) {
        if (function_exists('error_log')) {
            error_log('ensurePurchasingSchema error: ' . $ex->getMessage());
        }
    }
    $ensured = true;
}

function ensureVoucherUploadsDir()
{
    $dir = dirname(__DIR__) . '/assets/uploads/vouchers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return $dir;
}

/**
 * Supporting voucher files (sales orders, quotes, PO docs) are not SWIFT/bank payment proof.
 */
function voucherSwiftPathLooksLikeSupportingDocument(string $path): bool
{
    $name = strtolower(basename(trim($path)));
    if ($name === '') {
        return false;
    }
    $patterns = [
        'order_', 'order-', 'so-', 'so_', 'sales_order', 'salesorder',
        'quotation', 'quote_', 'quote-', 'pur-', 'purchase-', 'purchase_',
        'proforma', 'delivery', 'grn', 'requisition', 'waybill', 'packing',
    ];
    foreach ($patterns as $pattern) {
        if (strpos($name, $pattern) !== false) {
            return true;
        }
    }

    return false;
}

function voucherSwiftProofFileExists(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $path)) {
        return true;
    }
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($rel, 'assets/') !== 0 && strpos($rel, 'uploads/') !== 0 && strpos($rel, 'storage/') !== 0) {
        $rel = 'assets/uploads/vouchers/' . ltrim($rel, '/');
    }
    if (!function_exists('resolveStoredMediaFilePath')) {
        require_once __DIR__ . '/media_path_resolver.php';
    }
    $companyId = 0;
    if (function_exists('currentCompanyId')) {
        $companyId = (int) (currentCompanyId() ?? 0);
    }

    return resolveStoredMediaFilePath($rel, $companyId) !== '';
}

/**
 * True only when payment_vouchers.swift_document points to a usable SWIFT/bank proof file.
 */
function voucherSwiftDocumentIsUsablePaymentProof(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (voucherSwiftPathLooksLikeSupportingDocument($path)) {
        return false;
    }

    return voucherSwiftProofFileExists($path);
}

// Retrieve attachments for a voucher
function getVoucherAttachments($voucherId)
{
    global $pdo;
    ensureVoucherAttachmentsSchema();
    // Prepend 'assets/' to file_path to fix broken links (since file_path stored as 'uploads/vouchers/...')
    // OR just rely on the stored path if it's relative.
    // Let's check how it's stored.
    // addVoucherAttachment stores: $storedPath.
    // If ensureVoucherUploadsDir returns .../assets/uploads/vouchers, then $storedPath is likely just 'uploads/vouchers/...' or full path?
    // Let's look at addVoucherAttachment again. It inserts $storedPath.
    // Users reported link: http://localhost/assets/uploads/vouchers/109/...
    // This implies the stored path starts with 'assets/'.
    // If we are at localhost/view-voucher.php, a link to 'assets/...' means localhost/assets/... which IS correct.
    // BUT the user says the BROKEN link is http://localhost/assets/...
    // This means the link currently has a leading slash: '/assets/...'.
    // I need to remove that leading slash or prepend 'staff/'.
    // Best fix: Ensure the returned path is relative to the site root, not absolute to server root.
    
    $stmt = $pdo->prepare("SELECT id, file_path, original_name, mime_type, size_bytes, uploaded_at FROM voucher_attachments WHERE voucher_id = ? ORDER BY id");
    $stmt->execute([(int) $voucherId]);
    $rows = $stmt->fetchAll();
    
    // Fix paths to be relative to site root (prepend assets/)
    foreach ($rows as &$row) {
        if (strpos($row['file_path'], 'assets/') === false) {
             $row['file_path'] = 'assets/' . $row['file_path'];
        }
    }
    return $rows;
}

/**
 * Sales orders for voucher create/edit picker (uses sales DB when split from voucher tenant).
 *
 * @return list<array<string, mixed>>
 */
function fetchVoucherFormSalesOrders(PDO $pdo, int $limit = 150): array
{
    $limit = max(1, min(500, $limit));
    $salesPdo = $pdo;
    if (!tableExists('sales_orders', $salesPdo)) {
        $dbNames = array();
        if (defined('SALES_DB_NAME') && trim((string) SALES_DB_NAME) !== '') {
            $dbNames[] = trim((string) SALES_DB_NAME);
        }
        if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
            $dbNames[] = trim((string) DATA_DB_NAME);
        }
        $dbNames = array_values(array_unique(array_filter($dbNames)));
        foreach ($dbNames as $dbName) {
            if (!function_exists('connectToTenantDatabase')) {
                break;
            }
            $try = connectToTenantDatabase($dbName);
            if ($try instanceof PDO && tableExists('sales_orders', $try)) {
                $salesPdo = $try;
                break;
            }
        }
    }
    if (!tableExists('sales_orders', $salesPdo)) {
        return array();
    }
    try {
        $sql = "
            SELECT
                so.id,
                so.order_number,
                so.status,
                so.created_at,
                COALESCE(c.company_name, c.contact_person, 'Unknown Customer') AS customer_name,
                COALESCE(u.full_name, 'Unassigned') AS salesperson_name
            FROM sales_orders so
            LEFT JOIN customers c ON c.id = so.customer_id
            LEFT JOIN users u ON u.id = so.created_by
            ORDER BY so.created_at DESC, so.id DESC
            LIMIT " . (int) $limit;
        return $salesPdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    } catch (Throwable $e) {
        return array();
    }
}

/**
 * Parse linked sales order id(s) stored on a payment voucher row.
 *
 * @return int[]
 */
function parseLinkedSalesOrderIdsFromVoucher(array $voucher): array
{
    $linkedIds = [];
    $idsRaw = trim((string) ($voucher['linked_sales_order_ids'] ?? ''));
    if ($idsRaw !== '') {
        $decoded = json_decode($idsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idVal) {
                $idInt = (int) $idVal;
                if ($idInt > 0) {
                    $linkedIds[$idInt] = $idInt;
                }
            }
        } else {
            foreach (preg_split('/\s*,\s*/', $idsRaw) as $idVal) {
                $idInt = (int) $idVal;
                if ($idInt > 0) {
                    $linkedIds[$idInt] = $idInt;
                }
            }
        }
    }
    if (empty($linkedIds) && !empty($voucher['linked_sales_order_id'])) {
        $idInt = (int) $voucher['linked_sales_order_id'];
        if ($idInt > 0) {
            $linkedIds[$idInt] = $idInt;
        }
    }
    return array_values($linkedIds);
}

/**
 * Sales orders linked to a voucher (for Supporting Documents PDF cards).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchLinkedSalesOrdersForVoucher(array $voucher, $companyId = null): array
{
    global $pdo;
    $linkedIds = parseLinkedSalesOrderIdsFromVoucher($voucher);
    if ($linkedIds === [] || !tableExists('sales_orders')) {
        return [];
    }
    $cid = (int) ($companyId ?? currentCompanyId() ?? 0);
    $orders = [];
    try {
        $stmtSo = $pdo->prepare(
            "SELECT so.id, so.order_number, so.status, so.created_at, "
            . "COALESCE(c.company_name, c.contact_person, 'Unknown Customer') AS customer_name "
            . "FROM sales_orders so "
            . "LEFT JOIN customers c ON c.id = so.customer_id "
            . "WHERE so.id = ?" . getCompanySql('so')
        );
        foreach ($linkedIds as $sid) {
            $stmtSo->execute(array_merge([(int) $sid], getCompanyParam($cid)));
            $row = $stmtSo->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $orders[] = $row;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $orders;
}

/**
 * Distinct sales catalogue product ids on sales order line items.
 *
 * @param int[] $orderIds
 * @return int[]
 */
function fetchSalesProductIdsForSalesOrders(PDO $pdo, array $orderIds, int $companyId = 0): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static function ($id) {
        return $id > 0;
    })));
    if ($orderIds === [] || !tableExists('sales_order_items', $pdo) || !tableExists('sales_orders', $pdo)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    try {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT soi.product_id
             FROM sales_order_items soi
             INNER JOIN sales_orders so ON so.id = soi.order_id
             WHERE soi.order_id IN (' . $ph . ')
               AND soi.product_id > 0' . getCompanySql('so')
        );
        $stmt->execute(array_merge($orderIds, getCompanyParam($companyId)));
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $ids[$pid] = $pid;
            }
        }
        return array_values($ids);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ensure a stocks_items row exists for a sales catalogue product; return stocks_items.id.
 */
function ensureStockItemForSalesProductId(PDO $pdo, int $salesProductId): int
{
    if ($salesProductId <= 0 || !tableExists('stocks_items', $pdo) || !tableExists('products', $pdo)) {
        return 0;
    }

    try {
        $productStmt = $pdo->prepare('SELECT id, name, product_code FROM products WHERE id = ? LIMIT 1');
        $productStmt->execute([$salesProductId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            return 0;
        }

        $pName = trim((string) ($product['name'] ?? ''));
        $pCode = trim((string) ($product['product_code'] ?? ''));
        if ($pName === '' && $pCode === '') {
            return 0;
        }

        $existsStmt = $pdo->prepare(
            'SELECT id FROM stocks_items
             WHERE (LOWER(TRIM(name)) = LOWER(TRIM(?)) AND ? <> \'\')
                OR (sku IS NOT NULL AND sku <> \'\' AND LOWER(TRIM(sku)) = LOWER(TRIM(?)) AND ? <> \'\')
             LIMIT 1'
        );
        $existsStmt->execute([$pName, $pName, $pCode, $pCode]);
        $existingId = (int) $existsStmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }

        $cols = $pdo->query('SHOW COLUMNS FROM stocks_items')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($cols === []) {
            return 0;
        }

        $insert = [];
        foreach ($cols as $c) {
            $field = (string) ($c['Field'] ?? '');
            $null = (string) ($c['Null'] ?? '');
            $default = $c['Default'] ?? null;
            $extra = (string) ($c['Extra'] ?? '');
            $type = (string) ($c['Type'] ?? '');

            if ($field === '' || stripos($extra, 'auto_increment') !== false) {
                continue;
            }
            if ($field === 'name') {
                $insert[$field] = $pName;
                continue;
            }
            if ($field === 'sku') {
                // sku may be NOT NULL; never insert NULL when empty.
                $insert[$field] = $pCode !== '' ? $pCode : ('P-' . $salesProductId);
                continue;
            }
            if ($null === 'NO' && $default === null) {
                $lt = strtolower($type);
                if (preg_match('/^(int|tinyint|smallint|mediumint|bigint|decimal|float|double)/', $lt)) {
                    $insert[$field] = 0;
                } elseif (str_contains($lt, 'datetime') || str_contains($lt, 'timestamp')) {
                    $insert[$field] = date('Y-m-d H:i:s');
                } elseif (str_contains($lt, 'date')) {
                    $insert[$field] = date('Y-m-d');
                } else {
                    $insert[$field] = '';
                }
            }
        }

        if (!array_key_exists('name', $insert) || trim((string) $insert['name']) === '') {
            return 0;
        }

        $fields = array_keys($insert);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare(
            'INSERT INTO stocks_items (`' . implode('`, `', $fields) . '`) VALUES (' . $placeholders . ')'
        )->execute(array_values($insert));

        $existsStmt->execute([$pName, $pName, $pCode, $pCode]);
        return (int) $existsStmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolve a stocks_items.id from sales product name/code (PO autofill fallback).
 */
function resolveStockItemIdForSalesProduct(PDO $pdo, array $row): int
{
    $name = trim((string) ($row['sales_product_name'] ?? ''));
    $code = trim((string) ($row['sales_product_code'] ?? ''));
    if ($name === '' && $code === '') {
        return 0;
    }
    try {
        if ($name !== '') {
            $stmt = $pdo->prepare('SELECT id FROM stocks_items WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$name]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        if ($code !== '') {
            $stmt = $pdo->prepare(
                'SELECT id FROM stocks_items WHERE sku IS NOT NULL AND sku <> \'\' AND LOWER(TRIM(sku)) = LOWER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$code]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

/**
 * Quotation / sales-order line items for stock PO autofill from a payment voucher.
 *
 * @param array<int,array<string,mixed>> $stockProductByLinkedSalesId sales products.id => stocks_items row
 * @return array<int,array{product_id:int,sales_product_id:int,description:string,quantity:float,unit_price:float,unit_price_is_display:bool,needs_mapping:bool}>
 */
function fetchStockPurchaseVoucherQuotationLinesForPo(
    PDO $pdo,
    array $voucher,
    int $companyId,
    array $stockProductByLinkedSalesId = []
): array {
    $linkedOrderIds = parseLinkedSalesOrderIdsFromVoucher($voucher);
    if ($linkedOrderIds === [] || !tableExists('sales_order_items', $pdo) || !tableExists('sales_orders', $pdo)) {
        return [];
    }

    $validOrderIds = [];
    try {
        $phOrders = implode(',', array_fill(0, count($linkedOrderIds), '?'));
        $stmtValid = $pdo->prepare(
            'SELECT id FROM sales_orders WHERE id IN (' . $phOrders . ')' . getCompanySql('sales_orders')
        );
        $stmtValid->execute(array_merge($linkedOrderIds, getCompanyParam($companyId)));
        while ($oid = $stmtValid->fetchColumn()) {
            $oid = (int) $oid;
            if ($oid > 0) {
                $validOrderIds[] = $oid;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    if ($validOrderIds === []) {
        return [];
    }

    $soiCols = [];
    try {
        $soiCols = $pdo->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $soiCols = [];
    }
    $hasItemDesc = in_array('description', $soiCols, true);
    $descSelect = $hasItemDesc
        ? "COALESCE(NULLIF(TRIM(soi.description), ''), p.name, p.product_code, '')"
        : "COALESCE(p.name, p.product_code, '')";

    $productCols = [];
    try {
        $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $productCols = [];
    }
    $hasImageCol = in_array('image', $productCols, true);
    $hasMainImageCol = in_array('main_image', $productCols, true);
    if ($hasImageCol && $hasMainImageCol) {
        $salesImageSelect = "COALESCE(NULLIF(TRIM(p.image), ''), NULLIF(TRIM(p.main_image), ''))";
    } elseif ($hasImageCol) {
        $salesImageSelect = 'NULLIF(TRIM(p.image), \'\')';
    } elseif ($hasMainImageCol) {
        $salesImageSelect = 'NULLIF(TRIM(p.main_image), \'\')';
    } else {
        $salesImageSelect = 'NULL';
    }

    $ph = implode(',', array_fill(0, count($validOrderIds), '?'));
    $sql = '
        SELECT
            soi.id AS line_id,
            soi.product_id AS sales_product_id,
            COALESCE(soi.quantity, 0) AS quantity,
            COALESCE(soi.unit_price, 0) AS unit_price,
            ' . $descSelect . ' AS line_description,
            p.name AS sales_product_name,
            p.product_code AS sales_product_code,
            ' . $salesImageSelect . ' AS sales_product_image
        FROM sales_order_items soi
        INNER JOIN sales_orders so ON so.id = soi.order_id
        LEFT JOIN products p ON p.id = soi.product_id
        WHERE soi.order_id IN (' . $ph . ')' . getCompanySql('so') . '
        ORDER BY soi.order_id ASC, soi.id ASC
    ';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($validOrderIds, getCompanyParam($companyId)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if ($rows === []) {
        return [];
    }

    $lines = [];
    foreach ($rows as $r) {
        $salesProductId = (int) ($r['sales_product_id'] ?? 0);
        $stockProductId = 0;
        if ($salesProductId > 0) {
            $stockProductId = ensureStockItemForSalesProductId($pdo, $salesProductId);
        }
        if ($stockProductId <= 0 && $salesProductId > 0 && isset($stockProductByLinkedSalesId[$salesProductId])) {
            $stockProductId = (int) ($stockProductByLinkedSalesId[$salesProductId]['id'] ?? 0);
        }
        if ($stockProductId <= 0) {
            $stockProductId = resolveStockItemIdForSalesProduct($pdo, $r);
        }

        $qty = (float) ($r['quantity'] ?? 0);
        if ($qty <= 0) {
            $qty = 1;
        }
        $unit = (float) ($r['unit_price'] ?? 0);
        if ($unit < 0) {
            $unit = 0;
        }

        $desc = trim((string) ($r['line_description'] ?? ''));
        if ($desc === '' && !empty($r['sales_product_name'])) {
            $desc = trim((string) $r['sales_product_name']);
        }

        $lines[] = [
            'product_id' => $stockProductId,
            'sales_product_id' => $salesProductId,
            'sales_product_image' => trim((string) ($r['sales_product_image'] ?? '')),
            'description' => $desc,
            'quantity' => $qty,
            'unit_price' => $unit,
            'unit_price_is_display' => true,
            'needs_mapping' => $stockProductId <= 0,
        ];
    }

    return $lines;
}

/** Public URL to download/print a sales order as PDF. */
function salesOrderPrintPdfUrl(int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }
    $path = '/modules/sales/orders/print.php?id=' . $orderId . '&download=1';
    $slug = '';
    if (!empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    } elseif (function_exists('getRequestedCompanySlug')) {
        $slug = strtolower(trim(getRequestedCompanySlug()));
    }
    if ($slug !== '' && function_exists('company_url')) {
        return company_url('modules/sales/orders/print.php', $slug) . '?id=' . $orderId . '&download=1';
    }
    return function_exists('app_url') ? app_url($path) : $path;
}

/**
 * Upload supporting files for a voucher (additive; existing attachments retained).
 *
 * @return int Number of files successfully uploaded
 */
function processVoucherSupportingFileUploads($voucherId, $userId, $files = null)
{
    global $pdo;
    $voucherId = (int) $voucherId;
    $userId = (int) $userId;
    if ($voucherId <= 0 || $userId <= 0) {
        return 0;
    }

    $files = $files ?? ($_FILES['supporting_files'] ?? null);
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return 0;
    }

    ensureVoucherAttachmentsSchema();
    $baseDir = ensureVoucherUploadsDir();
    $voucherDir = $baseDir . DIRECTORY_SEPARATOR . $voucherId;
    if (!is_dir($voucherDir)) {
        @mkdir($voucherDir, 0775, true);
    }
    if (is_dir($voucherDir) && !is_writable($voucherDir)) {
        @chmod($voucherDir, 0775);
    }

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'doc', 'docx', 'xls', 'xlsx'];
    $toBytes = static function ($val) {
        $val = trim((string) $val);
        if ($val === '') {
            return 0;
        }
        $u = strtolower(substr($val, -1));
        $n = (float) $val;
        switch ($u) {
            case 'g':
                $n *= 1024;
            case 'm':
                $n *= 1024;
            case 'k':
                $n *= 1024;
        }
        return (int) round($n);
    };
    $maxServer = min(max(1, $toBytes(ini_get('upload_max_filesize') ?: '10M')), max(1, $toBytes(ini_get('post_max_size') ?: '10M')));

    $names = $files['name'];
    $tmps = $files['tmp_name'];
    $types = $files['type'];
    $sizes = $files['size'];
    $errs = $files['error'];
    $newUploads = 0;

    for ($i = 0, $count = count($names); $i < $count; $i++) {
        if (!isset($names[$i]) || ($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $orig = (string) $names[$i];
        $size = (int) ($sizes[$i] ?? 0);
        $mime = (string) ($types[$i] ?? 'application/octet-stream');
        $tmp = $tmps[$i] ?? '';
        if ($size <= 0 || $size > $maxServer || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }
        $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $unique = $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $destAbs = $voucherDir . DIRECTORY_SEPARATOR . $unique;
        $destRel = 'assets/uploads/vouchers/' . $voucherId . '/' . $unique;
        if (@move_uploaded_file($tmp, $destAbs)) {
            addVoucherAttachment($voucherId, $destRel, $orig, $mime, $size, $userId);
            $newUploads++;
        }
    }

    if ($newUploads > 0) {
        try {
            $attCountStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM voucher_attachments WHERE voucher_id = ?');
            $attCountStmt->execute([$voucherId]);
            $realCount = (int) ($attCountStmt->fetchColumn() ?: $newUploads);
            $up = $pdo->prepare('UPDATE payment_vouchers SET supporting_documents = ? WHERE id = ?');
            $up->execute([$realCount, $voucherId]);
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    return $newUploads;
}

// Record a single attachment row (internal helper)
function addVoucherAttachment($voucherId, $storedPath, $originalName, $mimeType, $sizeBytes, $uploadedBy)
{
    global $pdo;
    ensureVoucherAttachmentsSchema();
    // Ensure we store relative to assets folder if possible, or normalize.
    // Ideally we store 'uploads/vouchers/...'
    // If incoming path has assets/, strip it?
    // For now, just store what is passed, usually 'uploads/vouchers/...'
    $stmt = $pdo->prepare("INSERT INTO voucher_attachments (voucher_id, file_path, original_name, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
    return $stmt->execute([(int) $voucherId, $storedPath, $originalName, $mimeType, (int) $sizeBytes, (int) $uploadedBy]);
}

/**
 * Delete a specific attachment physically and from database.
 * Only allowed if user has edit permissions for the voucher.
 */
function deleteVoucherAttachment($attachmentId, $userId)
{
    global $pdo;
    $attachmentId = (int) $attachmentId;
    $userId = (int) $userId;

    try {
        // Fetch attachment details and voucher status
        // Removed pv.is_restricted to avoid SQL errors if column missing
        $stmt = $pdo->prepare("
            SELECT va.file_path, va.voucher_id, pv.status, va.original_name
            FROM voucher_attachments va
            JOIN payment_vouchers pv ON va.voucher_id = pv.id
            WHERE va.id = ?
        ");
        $stmt->execute([$attachmentId]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            return ['ok' => false, 'error' => 'Attachment not found'];
        }

        // Check permissions
        if (!canEditVoucher($attachment['voucher_id'], $userId)) {
            return ['ok' => false, 'error' => 'Permission denied'];
        }

        // Check approval status
        if (strtolower($attachment['status']) === 'approved') {
            return ['ok' => false, 'error' => 'Cannot delete attachments from an approved voucher'];
        }

        $pdo->beginTransaction();

        // 1. Remove from database
        $del = $pdo->prepare("DELETE FROM voucher_attachments WHERE id = ?");
        $del->execute([$attachmentId]);

        // 2. Decrement supporting_documents count
        $upd = $pdo->prepare("UPDATE payment_vouchers SET supporting_documents = GREATEST(0, IFNULL(supporting_documents, 1) - 1) WHERE id = ?");
        $upd->execute([$attachment['voucher_id']]);

        // 3. Log the action
        if (function_exists('logVoucherAction')) {
            logVoucherAction($attachment['voucher_id'], $userId, 'attachment_deleted', 'Deleted attachment: ' . ($attachment['original_name'] ?? basename($attachment['file_path'])));
        }

        // 4. Physical file deletion using secure UploadHelper
        if (class_exists('UploadHelper')) {
            UploadHelper::delete($attachment['file_path']);
        } else {
            $filePath = $attachment['file_path'];
            $rootPath = dirname(__DIR__); 
            $absPath = $rootPath . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\');
            if (file_exists($absPath)) {
                @unlink($absPath);
            }
        }

        $pdo->commit();
        return ['ok' => true];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Delete Attachment Error: " . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

// -------------- Signature helpers --------------
function getUserSignaturePathById($userId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([(int) $userId]);
    $row = $stmt->fetch();
    return $row && !empty($row['signature_path']) ? $row['signature_path'] : null;
}

function getUserSignaturePathByName($fullName)
{
    global $pdo;
    if (!$fullName)
        return null;
    $stmt = $pdo->prepare("SELECT signature_path FROM users WHERE full_name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$fullName]);
    $row = $stmt->fetch();
    return $row && !empty($row['signature_path']) ? $row['signature_path'] : null;
}

function ensureSignatureDir()
{
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
function handleUserSignatureUpload($userId, $fileField = 'signature_file', $maxBytes = 500000)
{
    global $pdo;
    ensureUserSignatureColumn();
    $userId = (int) $userId;
    $dir = ensureSignatureDir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'error' => 'Signature directory not writable', 'path' => null];
    }

    $savedPath = null;
    $sourceType = null; // 'canvas' | 'upload'

    // 1. Canvas base64 path
    if (!empty($_POST['signatureData']) && strpos($_POST['signatureData'], 'data:image') === 0) {
        $raw = (string) $_POST['signatureData'];
        $comma = strpos($raw, ',');
        if ($comma === false) {
            return ['ok' => false, 'error' => 'Malformed data URI', 'path' => null];
        }
        $b64 = substr($raw, $comma + 1);
        $bin = base64_decode($b64);
        if ($bin === false) {
            return ['ok' => false, 'error' => 'Invalid base64 data', 'path' => null];
        }
        if (strlen($bin) > $maxBytes) {
            return ['ok' => false, 'error' => 'Canvas image exceeds size limit', 'path' => null];
        }
        // Optional dimension check
        $info = @getimagesizefromstring($bin);
        if ($info) {
            if ($info[0] > 1600 || $info[1] > 800) {
                return ['ok' => false, 'error' => 'Image dimensions too large (max 1600x800)', 'path' => null];
            }
        }
        $name = 'sig_' . $userId . '_' . substr(hash('sha256', $userId . '|' . microtime(true) . '|' . random_bytes(8)), 0, 16) . '.png';
        $target = $dir . '/' . $name;
        if (@file_put_contents($target, $bin, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Failed to write signature file', 'path' => null];
        }
        $savedPath = 'assets/signatures/' . $name;
        $sourceType = 'canvas';
    }

    // 2. File upload path (only if not already saved)
    if (!$savedPath && isset($_FILES[$fileField])) {
        $err = $_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            if ($err === UPLOAD_ERR_NO_FILE) {
                return ['ok' => false, 'error' => 'No file selected', 'path' => null];
            }
            $map = [
                UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server size limit',
                UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds form size limit',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'PHP extension blocked the upload'
            ];
            return ['ok' => false, 'error' => $map[$err] ?? 'Unknown upload error', 'path' => null];
        }
        $tmp = $_FILES[$fileField]['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload (tmp not found)', 'path' => null];
        }
        $size = (int) ($_FILES[$fileField]['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty file', 'path' => null];
        }
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'File exceeds size limit (500KB)', 'path' => null];
        }

        // MIME sniff
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $tmp) : null;
        if ($finfo) {
            @finfo_close($finfo);
        }
        $allowed = ['image/png', 'image/jpeg'];
        if (!$mime || !in_array(strtolower($mime), $allowed, true)) {
            return ['ok' => false, 'error' => 'Only PNG or JPEG images are allowed', 'path' => null];
        }

        // Load & normalize to PNG (if GD available). If GD missing and MIME is png, move file directly.
        $pngName = 'sig_' . $userId . '_' . substr(hash('sha256', $userId . '|' . microtime(true) . '|' . random_bytes(8)), 0, 16) . '.png';
        $target = $dir . '/' . $pngName;
        if (function_exists('imagecreatefromstring')) {
            $blob = @file_get_contents($tmp);
            if ($blob === false) {
                return ['ok' => false, 'error' => 'Failed to read uploaded file', 'path' => null];
            }
            $img = @imagecreatefromstring($blob);
            if (!$img) {
                return ['ok' => false, 'error' => 'Unsupported or corrupted image', 'path' => null];
            }
            // Optional dimension constraint
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > 1600 || $h > 800) {
                imagedestroy($img);
                return ['ok' => false, 'error' => 'Image dimensions too large (max 1600x800)', 'path' => null];
            }
            if (!@imagepng($img, $target)) {
                imagedestroy($img);
                return ['ok' => false, 'error' => 'Failed saving PNG', 'path' => null];
            }
            imagedestroy($img);
        } else {
            if (strtolower($mime) !== 'image/png') {
                return ['ok' => false, 'error' => 'PNG only (enable GD for JPEG support)', 'path' => null];
            }
            if (!@move_uploaded_file($tmp, $target)) {
                return ['ok' => false, 'error' => 'Failed moving uploaded file', 'path' => null];
            }
        }
        $savedPath = 'assets/signatures/' . $pngName;
        $sourceType = 'upload';
    }

    if (!$savedPath) {
        return ['ok' => false, 'error' => 'No signature data provided', 'path' => null];
    }

    // Persist path to user row (replace previous if any)
    try {
        $stmt = $pdo->prepare('UPDATE users SET signature_path = ? WHERE id = ?');
        $stmt->execute([$savedPath, $userId]);
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'DB error saving signature', 'path' => null];
    }
    return ['ok' => true, 'error' => null, 'path' => $savedPath, 'source' => $sourceType];
}

/**
 * Render signature <img> tag for a user by ID or by exact full name.
 * $subject may be int user id or string user full_name.
 * $opts: ['class'=>string additional classes, 'maxHeight'=>int px, 'alt'=>string]
 * Returns HTML string (safe) or empty string if no signature or not found.
 */
function renderSignatureTag($subject, $opts = [])
{
    $sigPath = null;
    if (is_int($subject) || ctype_digit($subject)) {
        $sigPath = getUserSignaturePathById((int) $subject);
    } else if (is_string($subject) && trim($subject) !== '') {
        $sigPath = getUserSignaturePathByName($subject);
    }
    if (!$sigPath)
        return '';
    // Normalize relative path for caller context (account pages are one level deeper usually)
    $rel = $sigPath;
    if (strpos($rel, 'assets/') === 0) {
        // Caller must prepend appropriate ../ if needed; we keep raw path here.
    }
    $class = 'signature-img' . (!empty($opts['class']) ? (' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', $opts['class'])) : '');
    $maxH = isset($opts['maxHeight']) ? (int) $opts['maxHeight'] : 52;
    $alt = htmlspecialchars($opts['alt'] ?? 'Signature', ENT_QUOTES, 'UTF-8');
    return '<img src="' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="' . $class . '" style="max-height:' . $maxH . 'px; width:auto; object-fit:contain; display:inline-block;" />';
}

/**
 * Delete a user's signature file and clear DB reference.
 */
function deleteUserSignature($userId)
{
    global $pdo;
    $userId = (int) $userId;
    $existing = getUserSignaturePathById($userId);
    if ($existing) {
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($existing, '/\\'));
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
    $stmt = $pdo->prepare('UPDATE users SET signature_path = NULL WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Record attendance entry using existing account signature image.
 * $signType: 'sign_in' | 'sign_out'
 * Reads signature file from users.signature_path, converts to data URL (PNG), and stores with basic telemetry.
 */
function recordAttendanceWithAccountSignature($userId, $signType, $lat = 0.0, $lon = 0.0, $distance = 0.0, $deviceInfo = null, $ip = null)
{
    global $pdo;
    ensureAttendanceTable();
    $userId = (int) $userId;
    $signType = ($signType === 'sign_out') ? 'sign_out' : 'sign_in';

    $sigPath = getUserSignaturePathById($userId);
    if (!$sigPath) {
        return ['ok' => false, 'error' => 'No signature on file. Please add one in My Account.'];
    }
    // Build absolute path
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($sigPath, '/\\'));
    if (!is_file($abs) || !is_readable($abs)) {
        return ['ok' => false, 'error' => 'Signature file not found on server'];
    }
    $bytes = @file_get_contents($abs);
    if ($bytes === false) {
        return ['ok' => false, 'error' => 'Failed reading signature image'];
    }
    // Assume PNG as stored by handler; if not, best-effort MIME via finfo.
    $mime = 'image/png';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = @finfo_file($fi, $abs);
            if ($m) {
                $mime = $m;
            }
            @finfo_close($fi);
        }
    }
    $payload = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    // Basic sanitation for numbers
    $lat = is_numeric($lat) ? (float) $lat : 0.0;
    $lon = is_numeric($lon) ? (float) $lon : 0.0;
    $distance = is_numeric($distance) ? (float) $distance : 0.0;
    $deviceInfo = $deviceInfo !== null ? substr((string) $deviceInfo, 0, 255) : null;
    $ip = $ip !== null ? substr((string) $ip, 0, 45) : null;

    try {
        // Prevent consecutive same-type sign attempts
        $last = getLastAttendanceForUser($userId);
        if ($last) {
            $lastType = (string) ($last['sign_type'] ?? '');
            if ($lastType === $signType) {
                if ($signType === 'sign_in') {
                    return ['ok' => false, 'error' => 'Already signed in. Please sign out before signing in again.'];
                } else {
                    return ['ok' => false, 'error' => 'Already signed out. Please sign in before signing out again.'];
                }
            }
        }
        // Additional rule: require at least one Sign In today before allowing Sign Out
        if ($signType === 'sign_out') {
            $q = $pdo->prepare("SELECT COUNT(*) AS c FROM attendance WHERE user_id = ? AND sign_type = 'sign_in' AND DATE(signed_at) = CURDATE()");
            $q->execute([$userId]);
            $c = (int) ($q->fetch()['c'] ?? 0);
            if ($c === 0) {
                return ['ok' => false, 'error' => 'You must sign in today before signing out.'];
            }
        }
        // Compute geofence distance and enforce ONLY for sign-in (not sign-out)
        $distanceMeters = 0;
        $bypassGeofence = false;

        // Check IP Bypass
        if (defined('OFFICE_IP_ENABLED') && OFFICE_IP_ENABLED && defined('OFFICE_IPS') && OFFICE_IPS !== '') {
            $allowedIps = array_map('trim', explode(',', OFFICE_IPS));
            if (in_array((string) $ip, $allowedIps)) {
                $bypassGeofence = true;
            }
        }

        if ($signType === 'sign_in' && defined('OFFICE_LAT') && defined('OFFICE_LON') && OFFICE_LAT != 0.0 && OFFICE_LON != 0.0) {
            // Calculate distance
            if ($lat != 0.0 || $lon != 0.0) {
                $distanceMeters = haversineDistanceMeters($lat, $lon, (float) OFFICE_LAT, (float) OFFICE_LON);
            }

            // Enforce Geofence (only if NOT bypassing via IP)
            // Also respect OFFICE_LOCATION_ENABLED if defined
            $locationEnabled = defined('OFFICE_LOCATION_ENABLED') ? OFFICE_LOCATION_ENABLED : true;

            if ($locationEnabled && !$bypassGeofence) {
                // If client sent 0,0 and we need location, fail
                if ($lat == 0.0 && $lon == 0.0) {
                    return ['ok' => false, 'error' => 'Location not detected. Please enable GPS and try again.'];
                }

                if (defined('OFFICE_RADIUS_M') && $distanceMeters > (int) OFFICE_RADIUS_M) {
                    return ['ok' => false, 'error' => 'You are outside the office geofence. Distance: ' . number_format($distanceMeters) . 'm.'];
                }
            }
        } elseif ($signType === 'sign_out') {
            // For sign-out, still calculate distance for record-keeping but don't enforce
            if (defined('OFFICE_LAT') && defined('OFFICE_LON') && OFFICE_LAT != 0.0 && OFFICE_LON != 0.0 && $lat != 0.0 && $lon != 0.0) {
                $distanceMeters = haversineDistanceMeters($lat, $lon, (float) OFFICE_LAT, (float) OFFICE_LON);
            }
        }

        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, signature_image, latitude, longitude, distance_from_office, sign_type, device_info, ip_address) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$userId, $payload, $lat, $lon, $distanceMeters, $signType, $deviceInfo, $ip]);
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Failed to record attendance'];
    }
}

// Haversine distance in meters (small helper)
if (!function_exists('haversineDistanceMeters')) {
    function haversineDistanceMeters($lat1, $lon1, $lat2, $lon2)
    {
        $R = 6371000; // Earth radius meters
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return (int) round($R * $c);
    }
}

// Fetch most recent attendance record for a user
function getLastAttendanceForUser($userId)
{
    global $pdo;
    ensureAttendanceTable();
    $stmt = $pdo->prepare("SELECT id, sign_type, signed_at, latitude, longitude, distance_from_office FROM attendance WHERE user_id = ? ORDER BY signed_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int) $userId]);
    return $stmt->fetch();
}

// Determine if current user is in an "attendance locked" state (signed in today and not yet signed out)
function isAttendanceLocked($userId = null)
{
    global $pdo;
    if (isAdmin()) {
        return false;
    }
    if ($userId === null) {
        if (!isLoggedIn()) {
            return false;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
    }
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }
    try {
        ensureAttendanceTable();
        // Look only at today's attendance trail; if last action is sign_in, consider locked
        $q = $pdo->prepare("SELECT sign_type FROM attendance WHERE user_id = ? AND DATE(signed_at) = CURDATE() ORDER BY signed_at DESC, id DESC LIMIT 1");
        $q->execute([$userId]);
        $row = $q->fetch();
        if (!$row) {
            return false;
        }
        return (strtolower((string) ($row['sign_type'] ?? '')) === 'sign_in');
    } catch (Exception $e) {
        return false; // fail-open to avoid blocking access on DB error
    }
}

// Guard for voucher module pages: redirect employees who are currently signed in (attendance) to the sign page
function enforceVoucherAccessUnlocked($redirectTo = null)
{
    requireLogin();
    if (isAdmin()) {
        return;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
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
function ensureProfilePhotoColumn()
{
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Ignore if exists
    }
}

function ensureMessagesSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
    ensureProfilePhotoColumn();
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
    try {
        $pdo->exec("ALTER TABLE messages MODIFY COLUMN recipient_id INT NULL");
    } catch (PDOException $e) { /* ignore if already NULL */
    }
    // Ensure group_id exists on existing installs
    try {
        $pdo->query("SELECT group_id FROM messages LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN group_id INT NULL AFTER recipient_id");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("CREATE INDEX idx_messages_group_id ON messages(group_id)");
        } catch (PDOException $e3) { /* ignore */
        }
    }

    // Add reply_to_id if missing on existing installs
    try {
        $pdo->query("SELECT reply_to_id FROM messages LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN reply_to_id INT NULL AFTER recipient_id");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("CREATE INDEX idx_messages_reply_to_id ON messages(reply_to_id)");
        } catch (PDOException $e3) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE messages ADD CONSTRAINT fk_messages_reply FOREIGN KEY (reply_to_id) REFERENCES messages(id) ON DELETE SET NULL");
        } catch (PDOException $e4) { /* ignore */
        }
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
    try {
        $pdo->exec("ALTER TABLE messages ADD CONSTRAINT fk_messages_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE");
    } catch (PDOException $e) { /* ignore */
    }

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
    try {
        $pdo->query("SELECT id FROM message_reactions LIMIT 1");
    } catch (PDOException $e) {
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
    try {
        $pdo->query("SELECT edited_at FROM messages LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN edited_at TIMESTAMP NULL AFTER created_at");
        } catch (PDOException $e2) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
        } catch (PDOException $e3) { /* ignore */
        }
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN edited_message TEXT NULL AFTER message");
        } catch (PDOException $e4) { /* ignore */
        }
    }

    // Pinned messages table
    try {
        $pdo->query("SELECT id FROM pinned_messages LIMIT 1");
    } catch (PDOException $e) {
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
    try {
        $pdo->query("SELECT id FROM typing_indicators LIMIT 1");
    } catch (PDOException $e) {
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
    try {
        $pdo->query("SELECT id FROM message_reads LIMIT 1");
    } catch (PDOException $e) {
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

    } catch (Throwable $e) {
        error_log('ensureMessagesSchema: ' . $e->getMessage());
    }
    $ensured = true;
}



// Ensure uploads directory for chat attachments exists
function ensureMessageUploadsDir()
{
    $dir = dirname(__DIR__) . '/assets/uploads/messages';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return $dir;
}

// Ensure a default global chat group exists and that the given user is a member
function ensureGlobalGroupAndMembership($userId)
{
    global $pdo;
    ensureMessagesSchema();
    // Find or create the General group
    $stmt = $pdo->prepare("SELECT id FROM chat_groups WHERE name = 'General' LIMIT 1");
    $stmt->execute();
    $gidRow = $stmt->fetch();
    if ($gidRow && !empty($gidRow['id'])) {
        $gid = (int) $gidRow['id'];
    } else {
        $ins = $pdo->prepare("INSERT INTO chat_groups (name, created_by) VALUES ('General', ?)");
        $ins->execute([(int) $userId]);
        $gid = (int) $pdo->lastInsertId();
    }
    // Ensure membership for this user
    $mem = $pdo->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $mem->execute([$gid, (int) $userId]);
    return $gid;
}

function updateGroupLastRead($groupId, $userId)
{
    global $pdo;
    ensureMessagesSchema();
    $up = $pdo->prepare("INSERT INTO chat_group_reads (group_id, user_id, last_read_at) VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE last_read_at = NOW()");
    $up->execute([(int) $groupId, (int) $userId]);
}

function getUnreadMessagesCountForCurrentUser()
{
    global $pdo;
    if (!isLoggedIn()) {
        return 0;
    }
    try {
        ensureMessagesSchema();
        if (!tableExists('messages', $pdo) || !tableExists('chat_groups', $pdo)) {
            return 0;
        }
        $uid = (int) $_SESSION['user_id'];
        $gid = ensureGlobalGroupAndMembership($uid);
        $stmt = $pdo->prepare('SELECT last_read_at FROM chat_group_reads WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$gid, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $last = $row && !empty($row['last_read_at']) ? $row['last_read_at'] : null;
        if ($last) {
            $q = $pdo->prepare('SELECT COUNT(*) AS c FROM messages WHERE group_id = ? AND created_at > ? AND sender_id <> ?');
            $q->execute([$gid, $last, $uid]);
        } else {
            $q = $pdo->prepare('SELECT COUNT(*) AS c FROM messages WHERE group_id = ? AND sender_id <> ?');
            $q->execute([$gid, $uid]);
        }
        return (int) ($q->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('getUnreadMessagesCountForCurrentUser: ' . $e->getMessage());
        return 0;
    }
}

// -------------- Attendance/Signature System --------------
function ensureAttendanceTable()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;

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
    function app_log($message)
    {
        try {
            $base = dirname(__DIR__);
            $logDir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $file = $logDir . DIRECTORY_SEPARATOR . 'app.log';
            $line = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($message) ? $message : json_encode($message)) . "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // ignore logging errors
        }
    }
}

// -------------- Daily Task System --------------
function createTask($user_id, $type, $description)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, type, description, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$user_id, $type, $description]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function updateTaskStatus($task_id, $status, $feedback = null)
{
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

function getTasks($user_id, $filter_type = null)
{
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

function getAllTasks($filter_date = null, $filter_user = null)
{
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

function getPendingTasks($status = 'pending')
{
    global $pdo;
    $sql = "SELECT t.*, u.full_name, u.department FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.status = ? ORDER BY t.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

function getTaskById($task_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT t.*, u.full_name, u.department FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
    $stmt->execute([$task_id]);
    return $stmt->fetch();
}

// Check if user has created a daily task today
function hasDailyTaskToday($user_id)
{
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
function generateMeetingCode()
{
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
function createMeeting($title, $user_id, $scheduled_time = null)
{
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
function getMeetingById($meeting_id)
{
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
function getMeetingByCode($code)
{
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
function getUserMeetings($user_id, $status = null)
{
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
function getAllMeetings($status = null)
{
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
function joinMeeting($meeting_id, $user_id, $peer_id = null)
{
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
function leaveMeeting($meeting_id, $user_id)
{
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
function getMeetingParticipants($meeting_id)
{
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
function updateMeetingStatus($meeting_id, $status)
{
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
function deleteMeeting($meeting_id)
{
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
function endMeeting($meeting_id)
{
    return deleteMeeting($meeting_id);
}

/**
 * Ensure schema for Outstanding Invoices module
 */
function ensureOutstandingInvoicesSchema()
{
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
function toggleMeetingLock($meeting_id, $is_locked)
{
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
function cleanWhatsAppNumber($number)
{
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
function getWhatsAppLink($number, $message)
{
    if (empty($number)) {
        // If no number, return a general share link that opens contact picker
        return "https://api.whatsapp.com/send?text=" . urlencode($message);
    }
    $cleanNum = cleanWhatsAppNumber($number);
    return "https://wa.me/{$cleanNum}?text=" . urlencode($message);
}

/**
 * Get configured WhatsApp Group Link
 */
function getWhatsAppGroupLink()
{
    global $pdo;
    try {
        ensureSystemSettingsSchema();
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'whatsapp_group_link' LIMIT 1");
        $stmt->execute();
        return $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Render WhatsApp Link HTML with Icon
 */
function renderWaLink($name, $phones) {
    if (empty($name)) return '';
    
    $key = strtolower(trim((string)$name));
    if (empty($phones[$key])) return '';
    
    $raw = $phones[$key];
    $clean = preg_replace('/[^0-9]/', '', $raw);
    if (empty($clean)) return '';

    return '<a href="https://wa.me/' . $clean . '" target="_blank" title="Chat on WhatsApp" style="margin-right:5px; text-decoration:none;">
        <svg viewBox="0 0 24 24" class="no-print" width="16" height="16" fill="#25D366" style="vertical-align:middle;">
            <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.654-.698c1.02.595 1.95.838 2.806.838 3.193 0 5.764-2.586 5.763-5.766.001-3.18-2.587-5.765-5.763-5.765zm8.568 2.05c-.6-1.55-1.52-2.91-2.73-4.04s-2.61-1.99-4.22-2.48c-4.9-1.48-10.05.65-12.44 5.15-.3.56-.54 1.15-.72 1.76-.75 2.54-.31 5.3 1.2 7.7l-1.68 6.13a1 1 0 0 0 1.22 1.22l6.13-1.68c2.4 1.51 5.16 1.95 7.7 1.2 4.5-1.32 7.56-5.58 7.33-10.3-.06-1.63-.61-3.18-1.79-4.66z"/>
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.232-.297.347-.497.114-.2.073-.39-.033-.52-.107-.13-.976-2.352-1.337-3.221-.352-.849-.711-.733-.976-.749-.25-.015-.536-.015-.823-.015-.287 0-.754.108-1.148.539-.395.431-1.513 1.48-1.513 3.61 0 2.13 1.551 4.187 1.768 4.478.217.29 3.052 4.66 7.397 6.536 2.593 1.119 3.636 1.173 4.965.98 1.48-.216 3.064-1.253 3.496-2.463.433-1.21.433-2.246.304-2.463-.13-.216-.471-.34-.768-.489z" fill="#ffffff"/>
         </svg>
    </a>';
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
function getVoucherNotificationTarget($voucher, $currentUserFullName)
{
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

    if (!$targetNumber)
        return null;

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
function ensureTasksSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;

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


function ensureWeeklyTasksSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured || !$pdo) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            week_start_date DATE NOT NULL,
            status ENUM('planned', 'active', 'completed') DEFAULT 'planned',
            manager_rating DECIMAL(5,2) DEFAULT 0.00,
            manager_comment TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_week (user_id, week_start_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_plan_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            task_description TEXT NOT NULL,
            weight INT DEFAULT 1,
            is_completed TINYINT(1) DEFAULT 0,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (plan_id) REFERENCES weekly_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->query("SHOW COLUMNS FROM weekly_plan_items LIKE 'priority'");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE weekly_plan_items ADD COLUMN priority ENUM('low', 'medium', 'high') DEFAULT 'medium' AFTER task_description");
        }

        $ensured = true;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('ensureWeeklyTasksSchema error: ' . $e->getMessage());
        }
    }
}


// ==================== PASSWORD RESET FUNCTIONS ====================

// Ensure Password Reset Schema exists
function ensurePasswordResetSchema()
{
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
function generatePasswordResetToken($email)
{
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
function verifyResetToken($token)
{
    global $pdo;
    ensurePasswordResetSchema();

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$tokenHash]);
    return $stmt->fetchColumn(); // Returns user_id or false
}

// Reset Password
function resetUserPassword($userId, $newPassword)
{
    global $pdo;
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    return $stmt->execute([$hash, $userId]);
}


// ==================== MISSING ADMIN ACTIONS ====================

function approveVoucherByAdmin($voucherId, $adminId)
{
    global $pdo;
    try {
        $companyId = (int) (currentCompanyId() ?? 0);
        if (columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare("UPDATE payment_vouchers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([(int) $adminId, (int) $voucherId, $companyId]);
        } else {
            $stmt = $pdo->prepare("UPDATE payment_vouchers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([(int) $adminId, (int) $voucherId]);
        }

        logVoucherAction($voucherId, $adminId, 'approved', 'Quick approved via dashboard');

        // Notify creator
        try {
            notifyUserVoucherStatus($voucherId, 'approved');
        } catch (Exception $e) {
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function rejectVoucherByAdmin($voucherId, $adminId)
{
    global $pdo;
    try {
        $companyId = (int) (currentCompanyId() ?? 0);
        if (columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare("UPDATE payment_vouchers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?");
            $stmt->execute([(int) $adminId, (int) $voucherId, $companyId]);
        } else {
            $stmt = $pdo->prepare("UPDATE payment_vouchers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([(int) $adminId, (int) $voucherId]);
        }

        logVoucherAction($voucherId, $adminId, 'rejected', 'Quick rejected via dashboard');

        try {
            notifyUserVoucherStatus($voucherId, 'rejected');
        } catch (Exception $e) {
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function deleteVoucherHard($voucherId, $userId)
{
    global $pdo;
    try {
        $companyId = (int) (currentCompanyId() ?? 0);
        if (columnExists('payment_vouchers', 'company_id')) {
            $stmt = $pdo->prepare("DELETE FROM payment_vouchers WHERE id = ? AND company_id = ?");
            $stmt->execute([(int) $voucherId, $companyId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM payment_vouchers WHERE id = ?");
            $stmt->execute([(int) $voucherId]);
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Ensure is_restricted column on payment_vouchers
function ensureRestrictedColumn()
{
    global $pdo;
    static $ensured = false;
    if ($ensured)
        return;
    try {
        $pdo->query("SELECT is_restricted FROM payment_vouchers LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN is_restricted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        } catch (PDOException $e2) { /* ignore */
        }
    }
    $ensured = true;
}

// Ensure is_reference column on payment_vouchers (user-marked reference star)
function ensureVoucherReferenceColumn()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $pdo->query('SELECT is_reference FROM payment_vouchers LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE payment_vouchers ADD COLUMN is_reference TINYINT(1) NOT NULL DEFAULT 0 AFTER is_restricted');
        } catch (PDOException $e2) {
            try {
                $pdo->exec('ALTER TABLE payment_vouchers ADD COLUMN is_reference TINYINT(1) NOT NULL DEFAULT 0');
            } catch (PDOException $e3) { /* ignore */
            }
        }
    }
    $ensured = true;
}

/**
 * Migration helper for erp_expenses table columns
 */
function ensureExpenseColumns() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        // Check for pv_id
        try { $pdo->query("SELECT pv_id FROM erp_expenses LIMIT 1"); } 
        catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN pv_id INT NULL AFTER id");
        }

        // Check for source_type
        try { $pdo->query("SELECT source_type FROM erp_expenses LIMIT 1"); }
        catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN source_type ENUM('receipt', 'voucher') DEFAULT 'receipt' AFTER pv_id");
        }

        // Check for is_posted
        try { $pdo->query("SELECT is_posted FROM erp_expenses LIMIT 1"); }
        catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN is_posted TINYINT(1) DEFAULT 0 AFTER status");
        }

        // Approval audit columns (used when recording/posting expenses)
        try { $pdo->query("SELECT approved_by FROM erp_expenses LIMIT 1"); }
        catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN approved_by INT NULL");
        }

        try { $pdo->query("SELECT approved_at FROM erp_expenses LIMIT 1"); }
        catch (PDOException $e) {
            $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN approved_at TIMESTAMP NULL");
        }
    } catch (PDOException $e) {
        // Silently fail if table doesn't exist yet, it'll be created by init_db.php if run
    }
    $ensured = true;
}

/**
 * Ensure whatsapp_number column exists in users table
 */
function ensureWhatsAppColumn()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    try {
        $pdo->query("SELECT whatsapp_number FROM users LIMIT 1");
    } catch (PDOException $e) {
        try {
            // First check if 'phone' exists to place it after, otherwise just append
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(20) NULL");
        } catch (PDOException $e2) { /* ignore */ }
    }
    $ensured = true;
}

// Ensure all Stocks Module tables exist
function ensureStocksSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        // 1. Categories
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE
        )");

        // 2. Items (Master Data)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            uom VARCHAR(20) NOT NULL DEFAULT 'Each',
            category_id INT NULL,
            barcode VARCHAR(100) NULL,
            reorder_point INT NOT NULL DEFAULT 0,
            safety_stock INT NOT NULL DEFAULT 0,
            max_stock INT NOT NULL DEFAULT 0,
            stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES stocks_categories(id) ON DELETE SET NULL
        )");
        
        // Patch: Ensure stock_quantity exists if table was already created
        try {
            $pdo->query("SELECT stock_quantity FROM stocks_items LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE stocks_items ADD COLUMN stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER max_stock");
        }

        // 3. Suppliers
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 4. Supplier Items (Relations)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_supplier_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            item_id INT NOT NULL,
            supplier_part_number VARCHAR(100),
            lead_time_days INT DEFAULT 0,
            moq INT DEFAULT 1,
            unit_cost DECIMAL(15,2) DEFAULT 0.00,
            currency VARCHAR(10) DEFAULT 'TZS',
            FOREIGN KEY (supplier_id) REFERENCES stocks_suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES stocks_items(id) ON DELETE CASCADE
        )");

        // 5. Purchase Orders
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) NOT NULL UNIQUE,
            supplier_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft', 
            expected_delivery_date DATE NULL,
            created_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES stocks_suppliers(id)
        )");

        // 6. PO Items
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_po_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            item_id INT NOT NULL,
            qty_ordered DECIMAL(10,2) NOT NULL,
            qty_received DECIMAL(10,2) DEFAULT 0,
            unit_cost DECIMAL(15,2) NOT NULL,
            landed_cost DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (po_id) REFERENCES stocks_purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES stocks_items(id)
        )");

        // 7. Transactions (Traceability)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            reference_type VARCHAR(50),
            reference_id INT,
            batch_number VARCHAR(100) NULL,
            expiry_date DATE NULL,
            serial_number VARCHAR(100) NULL,
            transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_id INT NOT NULL,
            FOREIGN KEY (item_id) REFERENCES stocks_items(id)
        )");

        // Patch: Add missing columns for Procurement (GRN)
        $alters = [
            "ALTER TABLE stocks_transactions ADD COLUMN unit_cost DECIMAL(15,2) DEFAULT 0.00 AFTER quantity",
            "ALTER TABLE stocks_transactions ADD COLUMN tax_amount DECIMAL(15,2) DEFAULT 0.00 AFTER unit_cost",
            "ALTER TABLE stocks_transactions ADD COLUMN warehouse_location VARCHAR(100) DEFAULT NULL AFTER expiry_date",
            "ALTER TABLE stocks_transactions ADD COLUMN condition_status VARCHAR(50) DEFAULT 'Good' AFTER warehouse_location",
            "ALTER TABLE stocks_transactions ADD COLUMN external_reference VARCHAR(100) DEFAULT NULL COMMENT 'User entered Invoice or GRN or PO' AFTER reference_id",
            "ALTER TABLE stocks_transactions ADD COLUMN notes TEXT NULL AFTER external_reference"
        ];

        foreach ($alters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Ignore "duplicate column name" errors
            }
        }


    } catch (PDOException $e) { 
        // Silent fail or log if needed, avoiding page crash on minor schema race conditions
        error_log("Stocks Schema Error: " . $e->getMessage());
    }
    $ensured = true;
}

/**
 * Ensure Deliveries Module Schema exists
 * Updated 2025-12-20 for "Request -> Driver" flow
 */
function ensureDeliveriesSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        // 1. Delivery Trips
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_trips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trip_ref VARCHAR(50) NOT NULL UNIQUE,
            vehicle_id VARCHAR(50) NULL,
            driver_id INT NOT NULL,
            start_odometer DECIMAL(10,2) DEFAULT 0,
            end_odometer DECIMAL(10,2) DEFAULT 0,
            status ENUM('planned', 'loading', 'in_transit', 'completed') DEFAULT 'planned',
            start_time DATETIME NULL,
            end_time DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Delivery Orders
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requested_driver_id INT NOT NULL,
            trip_id INT NULL,
            client_name VARCHAR(100) NOT NULL,
            delivery_address TEXT NOT NULL,
            pickup_location VARCHAR(255) NULL,
            delivery_deadline DATETIME NULL,
            invoice_ref VARCHAR(50) NULL,
            package_weight VARCHAR(50) NULL,
            package_description TEXT NULL,
            package_image VARCHAR(255) NULL,
            receipt_file VARCHAR(255) NULL,  -- New field for Receipt
            invoice_file VARCHAR(255) NULL,
            status ENUM('request_pending', 'accepted', 'rejected', 'pending', 'loading', 'in_transit', 'delivered', 'partial', 'returned', 'failed') DEFAULT 'request_pending',
            
            -- Proof of Delivery (POD)
            recipient_name VARCHAR(100) NULL,
            recipient_role VARCHAR(100) NULL,
            geo_lat DECIMAL(10,8) NULL,
            geo_lng DECIMAL(11,8) NULL,
            signature_path VARCHAR(255) NULL,
            completion_time DATETIME NULL,
            failure_reason TEXT NULL,

            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (requested_driver_id) REFERENCES users(id),
            FOREIGN KEY (trip_id) REFERENCES delivery_trips(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Schema Patch for receipt_file validation
        try {
            $pdo->query("SELECT receipt_file FROM delivery_orders LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN receipt_file VARCHAR(255) NULL AFTER package_image");
        }
        
        // Schema Migrations (Idempotent)
        $cols = $pdo->query("DESCRIBE delivery_orders")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('requested_driver_id', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN requested_driver_id INT NULL AFTER trip_id");
        }
        if (!in_array('pickup_location', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN pickup_location TEXT NULL AFTER delivery_address");
        }
        if (!in_array('delivery_deadline', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN delivery_deadline DATETIME NULL AFTER pickup_location");
        }
        if (!in_array('route_cost', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN route_cost DECIMAL(12,2) NULL AFTER delivery_deadline");
        }
        if (!in_array('estimated_route_cost', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN estimated_route_cost DECIMAL(12,2) NULL AFTER route_cost");
        }
        // Add package_weight column
        if (!in_array('package_weight', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN package_weight VARCHAR(100) NULL AFTER invoice_ref");
        }
        if (!in_array('package_description', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN package_description TEXT NULL AFTER invoice_ref");
        }
        if (!in_array('client_phone', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN client_phone VARCHAR(50) NULL AFTER client_name");
        }
        if (!in_array('customer_rating', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN customer_rating INT NULL AFTER signature_path");
        }
        if (!in_array('customer_feedback', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN customer_feedback TEXT NULL AFTER customer_rating");
        }
        if (!in_array('package_image', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN package_image VARCHAR(255) NULL AFTER package_description");
        }
        if (!in_array('invoice_file', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN invoice_file VARCHAR(255) NULL AFTER package_image");
        }
        if (!in_array('rejection_reason', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN rejection_reason TEXT NULL AFTER status");
        }
        if (!in_array('created_by', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN created_by INT NULL");
        }
        if (!in_array('delivery_note_id', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN delivery_note_id INT NULL AFTER trip_id");
            try {
                $pdo->exec("ALTER TABLE delivery_orders ADD CONSTRAINT fk_delivery_orders_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes(id) ON DELETE SET NULL");
            } catch (Exception $e) {}
        }
        
        // Add Verification Hash Columns (Merged from duplicate)
        if (!in_array('verification_hash', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN verification_hash VARCHAR(64) NULL UNIQUE AFTER status");
        }
        if (!in_array('verification_hash_created_at', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN verification_hash_created_at DATETIME NULL AFTER verification_hash");
        }

        // Add Customer Review Columns
        if (!in_array('customer_rating', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN customer_rating INT NULL AFTER signature_path");
        }
        if (!in_array('customer_feedback', $cols)) {
            $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN customer_feedback TEXT NULL AFTER customer_rating");
        }
        
        // Update Status Enum if needed (MariaDB/MySQL doesn't support IF NOT EXISTS in MODIFY, so ignore error or check)
        // We'll trust the user to manually run the update script if enum errors occur, or relying on string casting.
        // But let's try to extend the ENUM safely.
        try {
            $pdo->exec("ALTER TABLE delivery_orders MODIFY COLUMN status ENUM('request_pending', 'accepted', 'rejected', 'pending', 'loading', 'in_transit', 'delivered', 'partial', 'returned', 'failed') DEFAULT 'request_pending'");
        } catch (Exception $e) {}

        // Make trip_id nullable if it isn't
        try {
            $pdo->exec("ALTER TABLE delivery_orders MODIFY COLUMN trip_id INT NULL");
        } catch (Exception $e) {}


        // 3. Delivery Items (Line Items)
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_order_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            item_sku VARCHAR(50) NULL,
            batch_number VARCHAR(100) NULL,
            quantity_ordered INT NOT NULL DEFAULT 0,
            quantity_delivered INT NOT NULL DEFAULT 0,
            quantity_rejected INT NOT NULL DEFAULT 0,
            rejection_reason VARCHAR(100) NULL,
            status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
            FOREIGN KEY (delivery_order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE
        )");

        // 4. Delivery Evidence (Photos)
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_evidence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_order_id INT NOT NULL,
            type ENUM('photo_drop', 'photo_issue') NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (delivery_order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE
        )");

    } catch (PDOException $e) {
        error_log("Deliveries Schema Error: " . $e->getMessage());
    }
    $ensured = true;
}

// -------------- Notification System --------------
function ensureNotificationsTable()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) NULL,
            type VARCHAR(20) DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $ensured = true;
    } catch (PDOException $e) {
        error_log("Notifications Schema Error: " . $e->getMessage());
    }
}

function createSystemNotification($userId, $title, $message, $link = null, $type = 'info')
{
    global $pdo;
    ensureNotificationsTable();
    try {
        $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $title, $message, $link, $type]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function getUnreadNotifications($userId)
{
    global $pdo;
    ensureNotificationsTable();
    $stmt = $pdo->prepare("SELECT * FROM system_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function markNotificationRead($id)
{
    global $pdo;
    ensureNotificationsTable();
    if (!isLoggedIn()) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE system_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $id, (int) $_SESSION['user_id']]);
}

// Ensure Delivery Notes Schema
function ensureDeliveryNotesSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            note_number VARCHAR(50) NOT NULL UNIQUE,
            customer_name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100) NULL,
            phone_number VARCHAR(50) NULL,
            customer_phone VARCHAR(50) NULL,
            delivery_address TEXT NULL,
            delivery_date DATE NOT NULL,
            items_json JSON NOT NULL, -- Stores array of {description, qty, unit}
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $ensured = true;
        // Update Schema for QR & Signatures
        $cols = $pdo->query("DESCRIBE delivery_notes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('authorized_signature_path', $cols)) {
            $pdo->exec("ALTER TABLE delivery_notes ADD COLUMN authorized_signature_path VARCHAR(255) NULL AFTER notes");
        }
        if (!in_array('receiver_signature_path', $cols)) {
            $pdo->exec("ALTER TABLE delivery_notes ADD COLUMN receiver_signature_path VARCHAR(255) NULL AFTER authorized_signature_path");
        }
        if (!in_array('customer_phone', $cols)) {
            $pdo->exec("ALTER TABLE delivery_notes ADD COLUMN customer_phone VARCHAR(50) NULL AFTER delivery_address");
        }
        if (!in_array('order_id', $cols)) {
            $pdo->exec("ALTER TABLE delivery_notes ADD COLUMN order_id INT NULL AFTER receiver_signature_path");
            $pdo->exec("CREATE INDEX idx_dn_order_id ON delivery_notes(order_id)");
        }
    } catch (PDOException $e) {
        error_log("Delivery Notes Schema Error: " . $e->getMessage());
    }
}





function ensureOrderVerificationSchema() {
    ensureDeliveriesSchema();
}



// Verification Helpers
function generateOrderVerificationHash($orderId) {
    global $pdo;
    ensureOrderVerificationSchema();
    
    // Check if exists and check expiry
    $stmt = $pdo->prepare("SELECT verification_hash, verification_hash_created_at FROM delivery_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hash = $row['verification_hash'] ?? null;
    $createdAt = $row['verification_hash_created_at'] ?? null;
    $shouldGenerate = false;

    if (!$hash) {
        $shouldGenerate = true;
    } elseif ($createdAt) {
        // Check if expired (> 1 hour)
        $expiryTime = strtotime($createdAt) + 3600; // 1 hour
        if (time() > $expiryTime) {
            $shouldGenerate = true;
        }
    } else {
        // Hash exists but no timestamp (migration case), regenerate to be safe/fresh or just set timestamp? 
        // Let's regenerate to ensure strict 1 hour from now.
        $shouldGenerate = true;
    }
    
    if ($shouldGenerate) {
        $hash = bin2hex(random_bytes(32));
        $up = $pdo->prepare("UPDATE delivery_orders SET verification_hash = ?, verification_hash_created_at = NOW() WHERE id = ?");
        $up->execute([$hash, $orderId]);
    }
    return $hash;
}

function getOrderByVerificationHash($hash) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT o.*, t.trip_ref, u.full_name as driver_name 
                           FROM delivery_orders o 
                           LEFT JOIN delivery_trips t ON o.trip_id = t.id 
                           LEFT JOIN users u ON t.driver_id = u.id
                           WHERE o.verification_hash = ?");
    $stmt->execute([$hash]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        // Enforce Expiration
        $createdAt = $order['verification_hash_created_at'] ?? null;
        if ($createdAt) {
            $expiryTime = strtotime($createdAt) + 3600; // 1 hour limit
            if (time() > $expiryTime) {
                return null; // Expired
            }
        } else {
            // No timestamp? Treat as expired or valid? 
            // For security, if we require checks, we should treat null as expired OR invalid schema.
            // But for backward compat during migration, maybe allow? 
            // User requested security: expire after 1 hour.
            // If no timestamp, we can't verify age. Let's return null to force regeneration via admin panel if needed.
            // Or better: effectively "expired".
            return null; 
        }
    }

    return $order;
}

// Smart Pricing Module Schema (Refactored to Tracking)
function ensureSmartPricingSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    try {
        // 1. Upgrade stocks_items (Catalog Pricing)
        $cols = $pdo->query("DESCRIBE stocks_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('buying_price', $cols)) {
            $pdo->exec("ALTER TABLE stocks_items ADD COLUMN buying_price DECIMAL(10,2) DEFAULT 0.00 AFTER stock_quantity");
        }
        if (!in_array('landed_cost', $cols)) {
            $pdo->exec("ALTER TABLE stocks_items ADD COLUMN landed_cost DECIMAL(10,2) DEFAULT 0.00 AFTER buying_price");
        }
        if (!in_array('min_selling_price', $cols)) {
            $pdo->exec("ALTER TABLE stocks_items ADD COLUMN min_selling_price DECIMAL(10,2) DEFAULT 0.00 AFTER landed_cost");
        }
        if (!in_array('target_selling_price', $cols)) {
            $pdo->exec("ALTER TABLE stocks_items ADD COLUMN target_selling_price DECIMAL(10,2) DEFAULT 0.00 AFTER min_selling_price");
        }

        // 2. Upgrade order_tracking (Transactional Pricing)
        $colsT = $pdo->query("DESCRIBE order_tracking")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('buying_price', $colsT)) {
            $pdo->exec("ALTER TABLE order_tracking ADD COLUMN buying_price DECIMAL(10,2) DEFAULT 0.00 AFTER total_value");
        }
        if (!in_array('landed_cost', $colsT)) {
            $pdo->exec("ALTER TABLE order_tracking ADD COLUMN landed_cost DECIMAL(10,2) DEFAULT 0.00 AFTER buying_price");
        }
        if (!in_array('min_selling_price', $colsT)) {
            $pdo->exec("ALTER TABLE order_tracking ADD COLUMN min_selling_price DECIMAL(10,2) DEFAULT 0.00 AFTER landed_cost");
        }
        if (!in_array('margin_percent', $colsT)) {
            $pdo->exec("ALTER TABLE order_tracking ADD COLUMN margin_percent DECIMAL(5,2) DEFAULT 0.00 AFTER min_selling_price");
        }
        
        $ensured = true;
    } catch (PDOException $e) {
        error_log("Smart Pricing Refactor Error: " . $e->getMessage());
    }
}

// Clean up ERP Products from previous implementation
function cleanErpProductsSchema() {
    global $pdo;
    try {
        $cols = $pdo->query("DESCRIBE erp_products")->fetchAll(PDO::FETCH_COLUMN);
        $toDrop = ['buying_price', 'landed_cost', 'min_selling_price', 'target_selling_price'];
        foreach ($toDrop as $col) {
            if (in_array($col, $cols)) {
                $pdo->exec("ALTER TABLE erp_products DROP COLUMN $col");
            }
        }
    } catch (PDOException $e) {
        error_log("ERP Cleanup Error: " . $e->getMessage());
    }
}

/**
 * attendance_settings must be a single row (id=1). Legacy installs lack PRIMARY KEY on id,
 * so INSERT IGNORE and ON DUPLICATE KEY UPDATE keep appending duplicate default rows.
 */
function repairAttendanceSettingsTable(PDO $pdo): void
{
    static $repairedConnections = [];
    $connKey = spl_object_hash($pdo);
    if (isset($repairedConnections[$connKey])) {
        return;
    }

    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'attendance_settings'");
        if (!$tableCheck || !$tableCheck->fetchColumn()) {
            $repairedConnections[$connKey] = true;
            return;
        }

        $hasPrimaryKey = false;
        $indexStmt = $pdo->query("SHOW INDEX FROM attendance_settings WHERE Key_name = 'PRIMARY'");
        if ($indexStmt) {
            $hasPrimaryKey = (bool) $indexStmt->fetch(PDO::FETCH_ASSOC);
        }

        $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM attendance_settings WHERE id = 1')->fetchColumn();

        if ($rowCount > 1 || !$hasPrimaryKey) {
            $orderBy = "CHAR_LENGTH(COALESCE(office_ip_address, '')) DESC";
            if (function_exists('columnExists') && columnExists('attendance_settings', 'updated_at', $pdo)) {
                $orderBy .= ', updated_at DESC';
            }

            $bestStmt = $pdo->query("
                SELECT * FROM attendance_settings
                WHERE id = 1
                ORDER BY
                    CASE
                        WHEN office_ip_address IS NULL OR TRIM(office_ip_address) = '' THEN 2
                        WHEN office_ip_address IN ('127.0.0.1', '127.0.0.1,::1,0.0.0.0') THEN 1
                        ELSE 0
                    END,
                    {$orderBy}
                LIMIT 1
            ");
            $best = $bestStmt ? $bestStmt->fetch(PDO::FETCH_ASSOC) : false;

            $pdo->exec('DELETE FROM attendance_settings WHERE id = 1');

            if ($best && is_array($best)) {
                $insertCols = 'id, start_time, end_time, grace_period_minutes, office_ip_address, latitude, longitude, radius_meters';
                $insertVals = '1, ?, ?, ?, ?, ?, ?, ?';
                $insertParams = [
                    $best['start_time'] ?? '09:00:00',
                    $best['end_time'] ?? '17:00:00',
                    (int) ($best['grace_period_minutes'] ?? 15),
                    (string) ($best['office_ip_address'] ?? '127.0.0.1,::1,0.0.0.0'),
                    isset($best['latitude']) && $best['latitude'] !== '' ? (float) $best['latitude'] : null,
                    isset($best['longitude']) && $best['longitude'] !== '' ? (float) $best['longitude'] : null,
                    (int) ($best['radius_meters'] ?? 100),
                ];
                if (function_exists('columnExists') && columnExists('attendance_settings', 'geofence_enabled', $pdo)) {
                    $insertCols .= ', geofence_enabled';
                    $insertVals .= ', ?';
                    $insertParams[] = (int) ($best['geofence_enabled'] ?? 1);
                }
                $stmt = $pdo->prepare("INSERT INTO attendance_settings ({$insertCols}) VALUES ({$insertVals})");
                $stmt->execute($insertParams);
            }
        }

        if (!$hasPrimaryKey) {
            try {
                $pdo->exec('ALTER TABLE attendance_settings ADD PRIMARY KEY (id)');
            } catch (PDOException $ePk) {
                error_log('repairAttendanceSettingsTable(PK): ' . $ePk->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('repairAttendanceSettingsTable: ' . $e->getMessage());
    }

    $repairedConnections[$connKey] = true;
}

/**
 * Clock-in / daily attendance module (staff/attendance) â€” settings + per-day records.
 * Separate from legacy GPS "attendance" table created by ensureAttendanceSchemaFix().
 */
function ensureAttendanceClockModuleSchema()
{
    global $pdo;
    static $settingsReady = false;
    static $auxReady = false;
    if (!($pdo instanceof PDO)) {
        return;
    }
    if (!$settingsReady) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_settings (
                id INT NOT NULL PRIMARY KEY,
                start_time TIME NOT NULL DEFAULT '09:00:00',
                end_time TIME NOT NULL DEFAULT '17:00:00',
                grace_period_minutes INT NOT NULL DEFAULT 15,
                office_ip_address TEXT,
                latitude DECIMAL(10, 8) NULL,
                longitude DECIMAL(11, 8) NULL,
                radius_meters INT NOT NULL DEFAULT 100
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try {
                $pdo->exec("ALTER TABLE attendance_settings MODIFY office_ip_address TEXT NULL");
            } catch (PDOException $eAlter) {}
            try {
                $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN latitude DECIMAL(10, 8) NULL");
            } catch (PDOException $eAdd) {}
            try {
                $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN longitude DECIMAL(11, 8) NULL");
            } catch (PDOException $eAdd) {}
            try {
                $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN radius_meters INT NOT NULL DEFAULT 100");
            } catch (PDOException $eAdd) {}
            try {
                $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN geofence_enabled TINYINT(1) NOT NULL DEFAULT 1");
            } catch (PDOException $eAdd) {}
            repairAttendanceSettingsTable($pdo);
            $settingsCount = (int) $pdo->query('SELECT COUNT(*) FROM attendance_settings WHERE id = 1')->fetchColumn();
            if ($settingsCount === 0) {
                $pdo->exec("INSERT INTO attendance_settings (id, start_time, end_time, grace_period_minutes, office_ip_address, latitude, longitude, radius_meters)
                    VALUES (1, '09:00:00', '17:00:00', 15, '127.0.0.1,::1,0.0.0.0', NULL, NULL, 100)");
            }
            $settingsReady = true;
        } catch (PDOException $e) {
            error_log('ensureAttendanceClockModuleSchema(settings): ' . $e->getMessage());
        }
    }
    if ($auxReady) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            date DATE NOT NULL,
            time_in TIME NOT NULL,
            time_out TIME NULL,
            status VARCHAR(50) NULL,
            signature_image TEXT NULL,
            ip_address VARCHAR(45) NULL,
            total_hours DECIMAL(10,2) NULL,
            overtime_hours DECIMAL(10,2) NULL,
            UNIQUE KEY user_date (user_id, date),
            INDEX idx_attendance_records_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('ensureAttendanceClockModuleSchema(records): ' . $e->getMessage());
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                date DATE NOT NULL,
                time_in TIME NOT NULL,
                time_out TIME NULL,
                status VARCHAR(50) NULL,
                signature_image TEXT NULL,
                ip_address VARCHAR(45) NULL,
                total_hours DECIMAL(10,2) NULL,
                overtime_hours DECIMAL(10,2) NULL,
                UNIQUE KEY user_date (user_id, date),
                INDEX idx_attendance_records_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e2) {
            error_log('ensureAttendanceClockModuleSchema(records-fallback): ' . $e2->getMessage());
        }
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            task_description TEXT NOT NULL,
            is_completed TINYINT(1) DEFAULT 0,
            task_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_tasks_user (user_id),
            INDEX idx_user_tasks_date (task_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('ensureAttendanceClockModuleSchema(user_tasks): ' . $e->getMessage());
    }
    $auxReady = true;
}

// Attendance Schema Fix (Auto-patching)
function ensureAttendanceSchemaFix()
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    // Check 1: sign_type
    try {
        $pdo->query("SELECT sign_type FROM attendance LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN sign_type ENUM('sign_in', 'sign_out') NOT NULL DEFAULT 'sign_in' AFTER user_id");
        } catch (Exception $ex) {}
    }

    // Check 2: device_info
    try {
        $pdo->query("SELECT device_info FROM attendance LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN device_info TEXT NULL AFTER sign_type");
        } catch (Exception $ex) {}
    }

    // Check 3: ip_address
    try {
        $pdo->query("SELECT ip_address FROM attendance LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN ip_address VARCHAR(45) NULL AFTER device_info");
        } catch (Exception $ex) {}
    }

    // Check 4: signed_at
    try {
        $pdo->query("SELECT signed_at FROM attendance LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN signed_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER ip_address");
        } catch (Exception $ex) {}
    }
    
    // Ensure table exists with correct schema if it was missing entirely
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sign_type ENUM('sign_in', 'sign_out') NOT NULL DEFAULT 'sign_in',
            signature_image LONGTEXT,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            distance_from_office DECIMAL(10, 2),
            device_info TEXT,
            ip_address VARCHAR(45),
            signed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    } catch (Exception $e) {
        // Ignore
    }

    $ensured = true;
}
ensureAttendanceSchemaFix();

// Expense Workflow Schema (Odoo-like)










// Ensure Expense Module Schema
function ensureExpenseWorkflowSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    // 1. Categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        account_code VARCHAR(50) NULL,
        parent_id INT NULL,
        FOREIGN KEY (parent_id) REFERENCES expenses_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Add parent_id column if missing (Migration)
    try {
        $pdo->query("SELECT parent_id FROM expenses_categories LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE expenses_categories ADD COLUMN parent_id INT NULL AFTER account_code");
        $pdo->exec("ALTER TABLE expenses_categories ADD CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES expenses_categories(id) ON DELETE CASCADE");
    }

    // 2. Requests/Expenses
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NOT NULL,
        description TEXT NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        date DATE NOT NULL,
        receipt_path VARCHAR(255) NULL,
        status ENUM('draft','reported','submitted','approved','posted','paid') DEFAULT 'draft',
        report_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (category_id) REFERENCES expenses_categories(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 3. Reports
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        report_title VARCHAR(255) NOT NULL,
        total_amount DECIMAL(15,2) DEFAULT 0,
        status ENUM('draft','submitted','approved','refused','posted','paid') DEFAULT 'draft',
        submitted_at DATETIME NULL,
        approved_at DATETIME NULL,
        employee_approver_id INT NULL,
        posted_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // 4. History/Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        user_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        comments TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    

    seedExpenseCategories();
    ensurePaymentWorkflowSchema();

    $ensured = true;
}

// Seed Categories
function seedExpenseCategories() {
    global $pdo;
    
    // Check if seeded
    $count = $pdo->query("SELECT COUNT(*) FROM expenses_categories")->fetchColumn();
    if ($count > 5) return; // Assume already seeded if we have data

    $structure = [
        'Operational Expenses' => [
            'Office Rent', 'Utilities (Electricity, Water, Internet)', 'Communication (Phone, Airtime, Data)', 
            'Transport & Fuel', 'Stationery & Office Supplies', 'Repairs & Maintenance', 'Cleaning & Sanitation', 'Licenses & Permits'
        ],
        'Procurement & Supplies' => [
            'Purchase of PPE & Safety Items', 'Purchase of Construction Materials', 'Freight & Clearing Charges', 
            'Customs Duties & Taxes', 'Supplier Payments', 'Warehouse Costs'
        ],
        'Employee Costs' => [
            'Salaries & Wages', 'Overtime', 'Allowances (Travel, Meal, Field)', 'Commissions', 'NSSF & WCF Contributions',
            'PAYE & Other Statutory Deductions', 'Staff Welfare', 'Training & Development'
        ],
        'Sales & Marketing' => [
            'Advertising & Promotion', 'Branding & Printing Materials', 'Client Gifts & CSR', 'Marketing Campaigns', 'Trade Shows / Exhibitions'
        ],
        'Logistics & Delivery' => [
            'Transport Hire / Delivery Charges', 'Vehicle Fuel & Maintenance', 'Insurance (Goods in Transit, Vehicle)', 
            'Freight Forwarding', 'Port Handling & Clearance Fees'
        ],
        'Administration & Management' => [
            'Directorsâ€™ Allowances', 'Professional Fees', 'Consultancy Fees', 'Subscription & Membership Fees', 'Software & IT Support', 'Bank Charges'
        ],
        'Projects & Capital Expenditure (CAPEX)' => [
            'Renovation & Construction Costs', 'Machinery & Equipment Purchase', 'Office Furniture & Fixtures', 'Vehicle Purchase', 'Computer & IT Equipment'
        ],
        'Financial Obligations' => [
            'Loan Repayments', 'Interest Payments', 'FDR Transfers / Withdrawals', 'Shareholder Withdrawals / Dividends', 'Insurance Premiums'
        ],
        'Tax & Compliance' => [
            'VAT Payments', 'Withholding Tax (WHT)', 'Income Tax', 'SDL (Skills Development Levy)', 'TRA Penalties & Fines'
        ],
        'Others / Miscellaneous' => [
            'Donations & Charity', 'Miscellaneous Expenses', 'Write-offs / Bad Debts', 'Exchange Rate Difference'
        ]
    ];

    foreach ($structure as $main => $subs) {
        // Insert Main
        $stmt = $pdo->prepare("INSERT INTO expenses_categories (name, parent_id) VALUES (?, NULL)");
        $stmt->execute([$main]);
        $mainId = $pdo->lastInsertId();
        
        // Insert Subs
        foreach ($subs as $sub) {
             $pdo->prepare("INSERT INTO expenses_categories (name, parent_id) VALUES (?, ?)")->execute([$sub, $mainId]);
        }
    }
}
// Ensure Payment Methods & Ledger Schema
function ensurePaymentWorkflowSchema() {
    global $pdo;
    static $ensured = false;
    if ($ensured) return;

    // 1. Payment Methods Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        type ENUM('bank','cash','mobile') NOT NULL DEFAULT 'bank',
        account_number VARCHAR(50) NULL,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Finance Transactions (General Ledger)
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        type ENUM('credit','debit') NOT NULL, 
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        category VARCHAR(100) NOT NULL,
        description TEXT NULL,
        payment_method_id INT NULL,
        expense_report_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payment_method_id) REFERENCES finance_payment_methods(id),
        FOREIGN KEY (expense_report_id) REFERENCES expenses_reports(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    seedPaymentMethods();

    $ensured = true;
}

function seedPaymentMethods() {
    global $pdo;
    $count = $pdo->query("SELECT COUNT(*) FROM finance_payment_methods")->fetchColumn();
    if ($count > 0) return;

    $methods = [
        ['Cash', 'cash', ''],
        ['CRDB Bank', 'bank', '0150XXXXXX'],
        ['NMB Bank', 'bank', '208XXXXXX'],
        ['M-Pesa', 'mobile', ''],
        ['Airtel Money', 'mobile', '']
    ];

    $stmt = $pdo->prepare("INSERT INTO finance_payment_methods (name, type, account_number) VALUES (?, ?, ?)");
    foreach ($methods as $m) {
        $stmt->execute($m);
    }
}

/**
 * Payment vouchers are kept separate from the expenses module.
 * Voucher payments are recorded in payment_vouchers / balances only.
 */
function ensureVoucherSyncToExpenses($voucher_id, $source_account_id, $category_id = null) {
    return true;
}

/**
 * Recent vouchers for admin dashboard (payment-vouchers module).
 *
 * @param array<string, mixed>|null $request Query params (defaults to $_GET).
 * @return array<string, mixed>
 */
function fetchDashboardRecentVouchers($request = null): array
{
    global $pdo;

    $emptyState = array(
        'recent_vouchers' => array(),
        'page' => 1,
        'per_page' => 7,
        'total_voucher_records' => 0,
        'total_pages' => 1,
        'voucherListFrom' => 0,
        'voucherListTo' => 0,
        'sort' => 'newest',
        'dateFrom' => '',
        'dateTo' => '',
    );

    if (function_exists('ensurePaymentVouchersCoreSchema')) {
        ensurePaymentVouchersCoreSchema($pdo);
    }
    if (!($pdo instanceof PDO) || !tableExists('payment_vouchers', $pdo)) {
        return $emptyState;
    }

    $request = $request ?? $_GET;

    $sort = isset($request['sort']) ? strtolower((string) $request['sort']) : 'newest';
    if (!in_array($sort, ['newest', 'asc', 'desc', 'voucher_no'], true)) {
        $sort = 'newest';
    }

    $dateFrom = trim((string) ($request['date_from'] ?? ''));
    $dateTo = trim((string) ($request['date_to'] ?? ''));
    $dateWhere = '';
    $queryParams = [];

    if ($dateFrom !== '') {
        $dateWhere .= ' AND DATE(pv.date_created) >= ?';
        $queryParams[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $dateWhere .= ' AND DATE(pv.date_created) <= ?';
        $queryParams[] = $dateTo;
    }

    $companyWhere = '';
    if (function_exists('companyScopeSql')) {
        list($companyFrag, $companyParams) = companyScopeSql('payment_vouchers', 'pv');
        $companyWhere = $companyFrag;
        foreach ($companyParams as $cp) {
            $queryParams[] = $cp;
        }
    }

    $page = max(1, (int) ($request['page'] ?? 1));
    $per_page = 7;
    $voucherListFrom = 0;
    $voucherListTo = 0;
    $total_voucher_records = 0;
    $total_pages = 1;

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_vouchers pv WHERE 1=1 $dateWhere $companyWhere");
        $countStmt->execute($queryParams);
        $total_voucher_records = (int) $countStmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('fetchDashboardRecentVouchers count: ' . $e->getMessage());
        return $emptyState;
    }

    $total_pages = max(1, (int) ceil($total_voucher_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    if ($total_voucher_records > 0) {
        $voucherListFrom = $offset + 1;
        $voucherListTo = min($offset + $per_page, $total_voucher_records);
    }

    try {
    $stmt = $pdo->prepare("
        SELECT
            pv.id, pv.voucher_no, pv.payee_name, pv.description, pv.total_amount, pv.status, pv.date_created,
            pv.currency, pv.prepared_by, IFNULL(pv.is_paid,0) AS is_paid, IFNULL(pv.is_posted,0) AS is_posted,
            pv.supporting_documents, pv.approved_by, pv.created_at,
            (SELECT COUNT(*) FROM voucher_items vi WHERE vi.voucher_id = pv.id) AS item_count,
            (SELECT COUNT(*) FROM voucher_attachments va WHERE va.voucher_id = pv.id) AS attachment_count,
            u.full_name AS creator_name, u.department,
            (SELECT role FROM users WHERE id = pv.approved_by LIMIT 1) AS approver_role
        FROM payment_vouchers pv
        LEFT JOIN users u ON pv.created_by = u.id
        WHERE 1=1 $dateWhere $companyWhere
        ORDER BY
            CASE WHEN pv.status = 'confirming' THEN 0 WHEN pv.status = 'pending' THEN 1 ELSE 2 END,
            pv.id DESC
        LIMIT " . (int) $per_page . ' OFFSET ' . (int) $offset . '
    ');
    $stmt->execute($queryParams);
    $recent_vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('fetchDashboardRecentVouchers list: ' . $e->getMessage());
        return $emptyState;
    }

    return [
        'recent_vouchers' => $recent_vouchers,
        'page' => $page,
        'per_page' => $per_page,
        'total_voucher_records' => $total_voucher_records,
        'total_pages' => $total_pages,
        'voucherListFrom' => $voucherListFrom,
        'voucherListTo' => $voucherListTo,
        'sort' => $sort,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
    ];
}

/**
 * Render dashboard voucher list fragments for AJAX or full page.
 *
 * @return array{tbody: string, pagination: string}
 */
function renderDashboardVoucherListFragments(array $state): array
{
    $recent_vouchers = $state['recent_vouchers'];
    $page = (int) $state['page'];
    $total_pages = (int) $state['total_pages'];
    $total_voucher_records = (int) $state['total_voucher_records'];
    $voucherListFrom = (int) $state['voucherListFrom'];
    $voucherListTo = (int) $state['voucherListTo'];

    $partialDir = dirname(__DIR__) . '/admin/partials';

    ob_start();
    include $partialDir . '/dashboard-voucher-tbody.php';
    $tbody = ob_get_clean() ?: '';

    $pagination = '';
    if ($total_voucher_records > 0) {
        ob_start();
        include $partialDir . '/dashboard-voucher-pagination.php';
        $pagination = ob_get_clean() ?: '';
    }

    return ['tbody' => $tbody, 'pagination' => $pagination];
}

/**
 * Page numbers to show in a numbered pagination bar (e.g. 1–10 when total is large).
 */
function paginationPageWindow(int $currentPage, int $totalPages, int $maxButtons = 10): array
{
    if ($totalPages <= 1) {
        return $totalPages >= 1 ? [1] : [];
    }
    $maxButtons = max(1, $maxButtons);
    if ($totalPages <= $maxButtons) {
        return range(1, $totalPages);
    }
    $start = max(1, $currentPage - (int) floor($maxButtons / 2));
    $end = $start + $maxButtons - 1;
    if ($end > $totalPages) {
        $end = $totalPages;
        $start = max(1, $end - $maxButtons + 1);
    }
    return range($start, $end);
}

/**
 * Build a same-page URL preserving current query string with a given page.
 */
function paginationUrlForPage(int $page): string
{
    $params = $_GET;
    $params['page'] = max(1, $page);
    $qs = http_build_query($params);
    return $qs !== '' ? '?' . $qs : '?page=' . $page;
}

function ensureCoreErpSchema()
{
    global $control_pdo, $pdo;
    static $booted = false;
    if ($booted) {
        return;
    }
    $usePdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : $pdo;
    if (!($usePdo instanceof PDO)) {
        return;
    }
    if (function_exists('ensureMultiCompanyControlSchema')) {
        ensureMultiCompanyControlSchema();
    }
    if (function_exists('ensurePaymentVouchersCoreSchema')) {
        ensurePaymentVouchersCoreSchema($usePdo);
    }
    if (function_exists('ensureNotificationsSchema')) {
        ensureNotificationsSchema();
    }
    if (function_exists('ensureAttendanceClockModuleSchema')) {
        ensureAttendanceClockModuleSchema();
    }
    $booted = true;
}

// Bootstrap critical tables once per request (login, headers, module pages).
if (isset($control_pdo) && $control_pdo instanceof PDO) {
    ensureCoreErpSchema();
}

// Pages that include functions.php without config.php (e.g. login) still get global font injection.
if (function_exists('erp_bootstrap_system_font_output_buffer')) {
    erp_bootstrap_system_font_output_buffer();
}
