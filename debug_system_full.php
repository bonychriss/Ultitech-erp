<?php
/**
 * Full-system diagnostic for HTTP 500 / connectivity / routing issues.
 * Upload to site root, open in browser, then DELETE when finished.
 *
 * Access: https://your-domain/debug_system_full.php?key=ultitech-debug
 * Optional: set $DEBUG_KEY in env.php to override the default key.
 */

declare(strict_types=1);

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// StackCP file-manager paths are not public URLs ù send to domain root
$dsysReqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#^/home/sites/#i', $dsysReqPath)) {
    $dsysKeyEarly = isset($_GET['key']) ? (string) $_GET['key'] : 'ultitech-debug';
    $dsysHostEarly = (string) ($_SERVER['HTTP_HOST'] ?? 'ultitech.io');
    $dsysSchemeEarly = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Location: ' . $dsysSchemeEarly . '://' . $dsysHostEarly . '/debug_system_full.php?key=' . rawurlencode($dsysKeyEarly), true, 302);
    exit;
}
unset($_GET['company_slug']);

if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}
$dsysPhpOk = (PHP_VERSION_ID >= 70100);
$dsysPhpRecommended = (PHP_VERSION_ID >= 70400);

const DSYS_DEFAULT_KEY = 'ultitech-debug';
const DSYS_VERSION = '1.0.0';

// ---------------------------------------------------------------------------
// Access gate (skip on CLI)
// ---------------------------------------------------------------------------
$dsysKeyRequired = (PHP_SAPI !== 'cli');
$dsysProvidedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$dsysExpectedKey = DSYS_DEFAULT_KEY;

if ($dsysKeyRequired) {
    foreach ([__DIR__ . '/env.php', __DIR__ . '/includes/env.php', __DIR__ . '/env.local.php'] as $envProbe) {
        if (!is_file($envProbe)) {
            continue;
        }
        $DB_HOST = $DB_HOST ?? null;
        $DEBUG_KEY = $DEBUG_KEY ?? null;
        include $envProbe;
        if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
            $dsysExpectedKey = trim((string) $DEBUG_KEY);
            break;
        }
    }
    if ($dsysProvidedKey === '' || !hash_equals($dsysExpectedKey, $dsysProvidedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden. Use ?key=YOUR_DEBUG_KEY (default: " . DSYS_DEFAULT_KEY . ")\n";
        echo "Set \$DEBUG_KEY in env.php to customize.\n";
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function dsys_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function dsys_mask(string $value): string
{
    $len = strlen($value);
    if ($len <= 2) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 1) . str_repeat('*', max(0, $len - 2)) . substr($value, -1);
}

function dsys_badge(bool $ok, string $label = ''): string
{
    $cls = $ok ? 'ok' : 'fail';
    $txt = $ok ? 'OK' : 'FAIL';
    $extra = $label !== '' ? ' ' . dsys_h($label) : '';
    return '<span class="badge ' . $cls . '">' . $txt . '</span>' . $extra;
}

function dsys_section(string $title)
{
    echo '<h2>' . dsys_h($title) . '</h2><table>';
}

function dsys_end_section()
{
    echo '</table>';
}

function dsys_row(string $label, $value = null)
{
    if ($value === null) {
        echo '<tr><th colspan="2">' . dsys_h($label) . '</th></tr>';
        return;
    }
    echo '<tr><th>' . dsys_h($label) . '</th><td>' . dsys_h((string) $value) . '</td></tr>';
}

function dsys_status(bool $ok, string $label, string $detail = '')
{
    echo '<tr><th>' . dsys_h($label) . '</th><td>' . dsys_badge($ok);
    if ($detail !== '') {
        echo ' <span class="detail">' . dsys_h($detail) . '</span>';
    }
    echo '</td></tr>';
}

function dsys_path_row(string $path)
{
    $exists = file_exists($path);
    dsys_status($exists && is_readable($path), basename($path), $path
        . ' | exists=' . ($exists ? 'yes' : 'no')
        . ' | writable=' . (is_writable($path) ? 'yes' : 'no'));
}

function dsys_php_lint(string $file): array
{
    if (!is_file($file)) {
        return ['ok' => false, 'message' => 'File not found'];
    }
    $sapi = strtolower((string) PHP_SAPI);
    $canExecLint = function_exists('exec')
        && strpos($sapi, 'fpm') === false
        && strpos($sapi, 'cgi') === false
        && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    if ($canExecLint) {
        $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        if (stripos($phpBin, 'fpm') !== false) {
            $canExecLint = false;
        }
    }
    if ($canExecLint) {
        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        return ['ok' => $code === 0, 'message' => implode("\n", $out) ?: '(no output)'];
    }
    $src = @file_get_contents($file);
    if ($src === false) {
        return ['ok' => false, 'message' => 'Cannot read file'];
    }
    $old = error_reporting(0);
    $tokens = token_get_all($src);
    error_reporting($old);
    if ($tokens === false) {
        return ['ok' => false, 'message' => 'Parse error (token_get_all failed)'];
    }
    return ['ok' => true, 'message' => 'token_get_all OK (PHP-FPM cannot run php -l; upgrade PHP in StackCP if bootstrap fails)'];
}

function dsys_try_pdo(string $host, string $db, string $user, string $pass): array
{
    $host = trim($host);
    $db = trim($db);
    if ($host === '' || $db === '') {
        return ['ok' => false, 'pdo' => null, 'host' => $host, 'message' => 'Missing host or database'];
    }
    try {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $pdo->exec("SET time_zone = '+03:00'");
        return ['ok' => true, 'pdo' => $pdo, 'host' => $host, 'message' => 'Connected'];
    } catch (Throwable $e) {
        return ['ok' => false, 'pdo' => null, 'host' => $host, 'message' => $e->getMessage()];
    }
}

function dsys_http_probe(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'Empty URL'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'UltitechSystemDebug/1.0',
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $err ?: 'curl failed'];
        }
        $body = $headerSize > 0 ? substr((string) $raw, $headerSize) : (string) $raw;
        $snippet = preg_replace('/\s+/', ' ', substr(strip_tags((string) $body), 0, 200));
        $ok = $code > 0 && $code < 500;
        return ['ok' => $ok, 'code' => $code, 'body' => (string) $snippet, 'error' => $ok ? '' : 'HTTP ' . $code];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: UltitechSystemDebug/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $m)) {
        $code = (int) $m[0];
    }
    if ($body === false) {
        return ['ok' => false, 'code' => $code, 'body' => '', 'error' => 'file_get_contents failed (enable curl if possible)'];
    }
    $snippet = preg_replace('/\s+/', ' ', substr(strip_tags((string) $body), 0, 200));
    $ok = $code > 0 && $code < 500;
    return ['ok' => $ok, 'code' => $code, 'body' => (string) $snippet, 'error' => $ok ? '' : 'HTTP ' . $code];
}

function dsys_build_url(string $path, array $query = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = rtrim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
    $path = '/' . ltrim($path, '/');
    if ($base !== '' && strpos($path, $base . '/') !== 0 && $path !== $base) {
        $path = $base . $path;
    }
    $url = $scheme . '://' . $host . $path;
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    return $url;
}

// ---------------------------------------------------------------------------
// HTML head
// ---------------------------------------------------------------------------
echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>ULTITECH ù Full System Debug</title>';
echo '<style>';
echo 'body{font-family:Segoe UI,Arial,sans-serif;margin:20px;background:#f1f5f9;color:#0f172a;line-height:1.45}';
echo 'h1{margin:0 0 6px;font-size:1.5rem}h2{margin:28px 0 8px;font-size:1.1rem;color:#1e40af;border-bottom:2px solid #93c5fd;padding-bottom:4px}';
echo 'table{border-collapse:collapse;width:100%;max-width:1200px;background:#fff;margin:8px 0 20px;box-shadow:0 1px 3px rgba(0,0,0,.08)}';
echo 'th,td{border:1px solid #e2e8f0;padding:8px 10px;text-align:left;vertical-align:top;font-size:13px}th{background:#f8fafc;width:260px;font-weight:600}';
echo '.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}.ok{background:#dcfce7;color:#166534}.fail{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}';
echo '.detail{color:#64748b;font-size:12px}.intro{color:#475569;margin:0 0 16px}.warnbox{background:#fffbeb;border:1px solid #fcd34d;padding:10px 14px;border-radius:8px;margin-bottom:16px;max-width:1200px}';
echo 'code{background:#f1f5f9;padding:1px 4px;border-radius:3px;font-size:12px}pre{white-space:pre-wrap;word-break:break-word;font-size:12px;background:#f8fafc;padding:8px;border-radius:6px;margin:4px 0}';
echo 'a{color:#2563eb}</style></head><body>';
echo '<h1>ULTITECH ù Full System Diagnostic</h1>';
echo '<p class="intro">Version ' . dsys_h(DSYS_VERSION) . ' ù Remove <code>debug_system_full.php</code> after troubleshooting.</p>';
$dsysScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$dsysHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$dsysBase = rtrim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
$dsysKeyForUrl = $dsysProvidedKey !== '' ? $dsysProvidedKey : $dsysExpectedKey;
$dsysCorrectUrl = $dsysScheme . '://' . $dsysHost . ($dsysBase !== '' ? $dsysBase : '') . '/debug_system_full.php?key=' . rawurlencode($dsysKeyForUrl);
$dsysShortUrl = $dsysScheme . '://' . $dsysHost . ($dsysBase !== '' ? $dsysBase : '') . '/hc.php?key=' . rawurlencode($dsysKeyForUrl);
echo '<div class="warnbox"><strong>Correct production URL</strong> (no <code>/public_html/</code> in path):<br>';
echo '<a href="' . dsys_h($dsysCorrectUrl) . '">' . dsys_h($dsysCorrectUrl) . '</a><br>';
echo 'Short: <a href="' . dsys_h($dsysShortUrl) . '">' . dsys_h($dsysShortUrl) . '</a><br>';
echo '<span class="detail">Do not use StackCP paths like /home/sites/.../public_html/ ù only the links above.</span></div>';
echo '<div class="warnbox"><strong>Security:</strong> This page exposes environment details. Do not leave it online.</div>';
if (!$dsysPhpOk) {
    echo '<div class="warnbox" style="background:#fee2e2;border-color:#fca5a5"><strong>Action required: upgrade PHP</strong><br>';
    echo 'Server PHP: <strong>' . dsys_h(PHP_VERSION) . '</strong>. Required: <strong>7.4+</strong> (7.1 minimum).<br>';
    echo 'StackCP: <strong>Websites</strong> &rarr; <strong>ultitech.io</strong> &rarr; <strong>PHP Version</strong> &rarr; <strong>7.4</strong> or <strong>8.1</strong> &rarr; Save.<br>';
    echo 'Login and all modules stay broken on PHP 7.0 until you upgrade.</div>';
}

// ---------------------------------------------------------------------------
// 1. Server / PHP
// ---------------------------------------------------------------------------
dsys_section('1. Server & PHP');
dsys_row('Time', date('c'));
dsys_row('PHP version', PHP_VERSION . (PHP_VERSION_ID < 70400 ? ' (UPGRADE to 7.4+ recommended)' : ''));
dsys_row('SAPI', PHP_SAPI);
dsys_row('OS', defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS);
dsys_row('Memory limit', ini_get('memory_limit') ?: 'n/a');
dsys_row('Max execution', ini_get('max_execution_time') ?: 'n/a');
dsys_row('display_errors', ini_get('display_errors') ?: 'n/a');
dsys_row('log_errors', ini_get('log_errors') ?: 'n/a');
dsys_row('error_log', ini_get('error_log') ?: '(default)');
dsys_row('HTTP Host', $_SERVER['HTTP_HOST'] ?? '');
dsys_row('HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no');
dsys_row('Document root', $_SERVER['DOCUMENT_ROOT'] ?? '');
dsys_row('Script', __FILE__);
dsys_row('Request URI', $_SERVER['REQUEST_URI'] ?? '');
dsys_end_section();

$extensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'curl', 'openssl', 'fileinfo', 'gd', 'zip'];
dsys_section('1b. PHP extensions');
foreach ($extensions as $ext) {
    dsys_status(extension_loaded($ext), $ext, extension_loaded($ext) ? 'loaded' : 'missing ù may cause fatal errors');
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 2. Paths & env files
// ---------------------------------------------------------------------------
$root = __DIR__;
$paths = [
    'env.php' => $root . '/env.php',
    'env.local.php' => $root . '/env.local.php',
    'includes/env.php' => $root . '/includes/env.php',
    'includes/config.php' => $root . '/includes/config.php',
    'includes/functions.php' => $root . '/includes/functions.php',
    'select-module.php' => $root . '/select-module.php',
    'login.php' => $root . '/login.php',
    '.htaccess' => $root . '/.htaccess',
    'storage/' => $root . '/storage',
    'logs/' => $root . '/logs',
];

dsys_section('2. Critical paths');
foreach ($paths as $label => $path) {
    dsys_path_row($path);
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 3. Env load (standalone)
// ---------------------------------------------------------------------------
$hostLower = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = in_array($hostLower, ['localhost', '127.0.0.1'], true)
    || (strlen($hostLower) >= 6 && substr($hostLower, -6) === '.local');
$envCandidates = $isLocal
    ? [$root . '/env.local.php', $root . '/includes/env.local.php', $root . '/env.php', $root . '/includes/env.php']
    : [$root . '/env.php', $root . '/includes/env.php', $root . '/env.local.php', $root . '/includes/env.local.php'];

$DB_HOST = $DB_NAME = $DB_USER = $DB_PASS = $APP_ENV = $SITE_URL = $DATA_DB_NAME = $SALES_DB_NAME = '';
$loadedEnvFile = null;

dsys_section('3. Environment files');
foreach ($envCandidates as $candidate) {
    $found = is_file($candidate);
    dsys_status($found, basename(dirname($candidate)) . '/' . basename($candidate), $candidate);
    if ($found && $loadedEnvFile === null) {
        include $candidate;
        if (isset($DB_USER) && (string) $DB_USER !== '') {
            $loadedEnvFile = $candidate;
        }
    }
}
dsys_row('Loaded env file', $loadedEnvFile ?? '(none ù DB credentials missing)');
dsys_row('DB_HOST', $DB_HOST !== '' ? $DB_HOST : '(empty)');
dsys_row('DB_NAME', $DB_NAME !== '' ? $DB_NAME : '(empty)');
dsys_row('DB_USER', $DB_USER !== '' ? $DB_USER : '(empty)');
dsys_row('DB_PASS', $DB_PASS !== '' ? dsys_mask((string) $DB_PASS) : '(empty)');
dsys_row('DATA_DB_NAME', isset($DATA_DB_NAME) && $DATA_DB_NAME !== '' ? $DATA_DB_NAME : '(not set)');
dsys_row('SALES_DB_NAME', isset($SALES_DB_NAME) && $SALES_DB_NAME !== '' ? $SALES_DB_NAME : '(not set)');
dsys_row('APP_ENV', $APP_ENV !== '' ? $APP_ENV : '(not set)');
dsys_row('SITE_URL', $SITE_URL !== '' ? $SITE_URL : '(not set)');
dsys_end_section();

// ---------------------------------------------------------------------------
// 4. Direct DB (before bootstrap)
// ---------------------------------------------------------------------------
dsys_section('4. Control database (direct PDO)');
$controlPdo = null;
$hostsToTry = array_values(array_unique(array_filter([
    (string) $DB_HOST,
    'localhost',
    '127.0.0.1',
], static function ($h) {
    return trim((string) $h) !== '';
})));

if ($DB_NAME === '' || $DB_USER === '') {
    dsys_status(false, 'Credentials', 'DB_NAME or DB_USER empty ù fix env.php on server');
} else {
    foreach ($hostsToTry as $h) {
        $attempt = dsys_try_pdo($h, (string) $DB_NAME, (string) $DB_USER, (string) $DB_PASS);
        dsys_status($attempt['ok'], 'Connect host=' . $h, $attempt['message']);
        if ($attempt['ok'] && $attempt['pdo'] instanceof PDO) {
            $controlPdo = $attempt['pdo'];
            break;
        }
    }
    if ($controlPdo instanceof PDO) {
        try {
            $active = (string) $controlPdo->query('SELECT DATABASE()')->fetchColumn();
            dsys_row('Active database', $active);
            $tables = $controlPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            dsys_row('Table count', (string) count($tables));
            foreach (['companies', 'users', 'company_modules', 'user_company_index'] as $tbl) {
                $st = $controlPdo->prepare('SHOW TABLES LIKE ?');
                $st->execute([$tbl]);
                dsys_status((bool) $st->fetchColumn(), 'Table: ' . $tbl);
            }
            $companies = $controlPdo->query(
                'SELECT id, company_name, company_slug, db_name, db_host, status FROM companies ORDER BY id ASC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            dsys_row('Companies loaded', (string) count($companies));
        } catch (Throwable $e) {
            dsys_status(false, 'Post-connect queries', $e->getMessage());
            $companies = [];
        }
    } else {
        $companies = [];
    }
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 5. PHP syntax on critical files
// ---------------------------------------------------------------------------
$lintFiles = [
    $root . '/includes/config.php',
    $root . '/includes/functions.php',
    $root . '/select-module.php',
    $root . '/login.php',
];
dsys_section('5. PHP syntax check');
foreach ($lintFiles as $file) {
    $lint = dsys_php_lint($file);
    dsys_status($lint['ok'], basename($file), $lint['message']);
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 6. Bootstrap chain (step by step)
// ---------------------------------------------------------------------------
dsys_section('6. Bootstrap chain');
$configOk = false;
$configError = '';
$functionsOk = false;
$functionsError = '';
$schemaOk = false;
$schemaError = '';
$bootControlPdo = null;

if (!$dsysPhpOk) {
    dsys_status(false, 'PHP version', PHP_VERSION . ' ù upgrade to 7.4+ in StackCP before testing bootstrap');
    dsys_row('Fix', 'Websites ? ultitech.io ? PHP Version ? 7.4 or 8.1 ? Save, wait 1-2 min, reload this page');
} else {
try {
    require_once $root . '/includes/config.php';
    $configOk = true;
    $bootControlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);
} catch (Throwable $e) {
    $configError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
} catch (Exception $e) {
    $configError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

dsys_status($configOk, 'includes/config.php', $configOk ? 'loaded' : $configError);
if ($configOk) {
    dsys_row('APP_BASE_PATH', defined('APP_BASE_PATH') ? APP_BASE_PATH : '(undefined)');
    dsys_row('APP_ENV', defined('APP_ENV') ? APP_ENV : '(undefined)');
    dsys_row('DB_HOST const', defined('DB_HOST') ? DB_HOST : '(undefined)');
    dsys_row('DB_NAME const', defined('DB_NAME') ? DB_NAME : '(undefined)');
    dsys_status($bootControlPdo instanceof PDO, 'control_pdo after config');
}

if ($configOk) {
    try {
        require_once $root . '/includes/functions.php';
        $functionsOk = true;
    } catch (Throwable $e) {
        $functionsError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
}

dsys_status($functionsOk, 'includes/functions.php', $functionsOk ? 'loaded' : $functionsError);

if ($functionsOk && function_exists('ensureMultiCompanyControlSchema')) {
    try {
        $schemaOk = (bool) ensureMultiCompanyControlSchema();
        if (function_exists('getLastControlSchemaError')) {
            $schemaError = getLastControlSchemaError();
        } elseif (isset($GLOBALS['ultitech_last_schema_error'])) {
            $schemaError = (string) $GLOBALS['ultitech_last_schema_error'];
        }
    } catch (Throwable $e) {
        $schemaError = $e->getMessage();
    }
    dsys_status($schemaOk, 'ensureMultiCompanyControlSchema()', $schemaError !== '' ? $schemaError : 'executed');
}

if ($functionsOk && function_exists('getRequestedCompanySlug')) {
    dsys_row('Detected slug (URL)', getRequestedCompanySlug() !== '' ? getRequestedCompanySlug() : '(none)');
}
}

dsys_end_section();

// ---------------------------------------------------------------------------
// 7. Company "ultimate" + tenant DBs
// ---------------------------------------------------------------------------
dsys_section('7. Tenant databases');
$usePdo = $bootControlPdo instanceof PDO ? $bootControlPdo : $controlPdo;
$useUser = defined('DB_USER') ? DB_USER : (string) $DB_USER;
$usePass = defined('DB_PASS') ? DB_PASS : (string) $DB_PASS;
$configuredHost = defined('DB_HOST') ? DB_HOST : (string) $DB_HOST;

if (!($usePdo instanceof PDO)) {
    dsys_status(false, 'Skipped', 'No control PDO from bootstrap');
} else {
    $slugProbe = 'ultimate';
    try {
        $st = $usePdo->prepare('SELECT id, company_name, company_slug, db_name, db_host, status FROM companies WHERE company_slug = ? LIMIT 1');
        $st->execute([$slugProbe]);
        $ultimate = $st->fetch(PDO::FETCH_ASSOC);
        if ($ultimate) {
            dsys_status(true, 'Company slug "' . $slugProbe . '"', 'id=' . (int) ($ultimate['id'] ?? 0) . ' name=' . ($ultimate['company_name'] ?? ''));
            $tenantDb = trim((string) ($ultimate['db_name'] ?? ''));
            $tenantHost = trim((string) ($ultimate['db_host'] ?? '')) ?: $configuredHost;
            dsys_row('Tenant db_name', $tenantDb !== '' ? $tenantDb : '(empty ù uses control / DATA_DB_NAME)');
            dsys_row('Tenant db_host', $tenantHost);

            $effectiveDb = $tenantDb;
            if ($functionsOk && function_exists('resolveEffectiveTenantDbConnection')) {
                $eff = resolveEffectiveTenantDbConnection($tenantDb, $tenantHost, $useUser, $usePass);
                $effectiveDb = (string) ($eff['db_name'] ?? $tenantDb);
                dsys_row('resolveEffectiveTenantDbConnection', $effectiveDb);
            } elseif (defined('DATA_DB_NAME') && DATA_DB_NAME !== '') {
                if ($tenantDb === '' || $tenantDb === (defined('DB_NAME') ? DB_NAME : $DB_NAME)) {
                    $effectiveDb = DATA_DB_NAME;
                    dsys_row('Effective (DATA_DB_NAME fallback)', $effectiveDb);
                }
            }

            if ($effectiveDb !== '') {
                $tenantAttempt = dsys_try_pdo($tenantHost, $effectiveDb, $useUser, $usePass);
                if (!$tenantAttempt['ok']) {
                    foreach ($hostsToTry as $h) {
                        if ($h === $tenantHost) {
                            continue;
                        }
                        $tenantAttempt = dsys_try_pdo($h, $effectiveDb, $useUser, $usePass);
                        if ($tenantAttempt['ok']) {
                            break;
                        }
                    }
                }
                dsys_status($tenantAttempt['ok'], 'Tenant connection', $tenantAttempt['message']);
                if ($tenantAttempt['ok'] && $tenantAttempt['pdo'] instanceof PDO) {
                    foreach (['users', 'payment_vouchers', 'company_modules'] as $tbl) {
                        try {
                            $chk = $tenantAttempt['pdo']->prepare('SHOW TABLES LIKE ?');
                            $chk->execute([$tbl]);
                            dsys_status((bool) $chk->fetchColumn(), 'Tenant table: ' . $tbl);
                        } catch (Throwable $e) {
                            dsys_status(false, 'Tenant table: ' . $tbl, $e->getMessage());
                        }
                    }
                }
            }
        } else {
            dsys_status(false, 'Company slug "' . $slugProbe . '"', 'Not found in companies table ù /ultimate/* routes will 404 or fail');
        }
    } catch (Throwable $e) {
        dsys_status(false, 'ultimate lookup', $e->getMessage());
    }

    if (!empty($companies)) {
        dsys_row('All companies', '');
        foreach ($companies as $co) {
            $slug = (string) ($co['company_slug'] ?? '');
            $tdb = trim((string) ($co['db_name'] ?? ''));
            $line = '#' . (int) ($co['id'] ?? 0) . ' ' . ($co['company_name'] ?? '') . ' slug=' . $slug . ' db=' . ($tdb !== '' ? $tdb : '(control)');
            if ($tdb !== '') {
                $t = dsys_try_pdo($configuredHost, $tdb, $useUser, $usePass);
                if (!$t['ok']) {
                    foreach ($hostsToTry as $h) {
                        $t = dsys_try_pdo($h, $tdb, $useUser, $usePass);
                        if ($t['ok']) {
                            break;
                        }
                    }
                }
                $line .= ' ? ' . ($t['ok'] ? 'OK' : 'FAIL: ' . $t['message']);
            }
            echo '<tr><th>' . dsys_h($slug !== '' ? $slug : 'id-' . (int) ($co['id'] ?? 0)) . '</th><td>' . dsys_h($line) . '</td></tr>';
        }
    }
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 8. Session & mod_rewrite
// ---------------------------------------------------------------------------
dsys_section('8. Session & Apache');
$modRewrite = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', apache_get_modules(), true)
    : null;
if ($modRewrite === null) {
    dsys_row('mod_rewrite', 'Cannot detect (not Apache or apache_get_modules disabled)');
} else {
    dsys_status($modRewrite, 'mod_rewrite', $modRewrite ? 'enabled' : 'disabled ù company slug URLs will break');
}
dsys_status(is_file($root . '/.htaccess'), '.htaccess present');
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
dsys_status(session_status() === PHP_SESSION_ACTIVE, 'PHP session', 'status=' . session_status());
$sessionPath = session_save_path();
dsys_row('session.save_path', $sessionPath !== '' ? $sessionPath : '(default)');
dsys_end_section();

// ---------------------------------------------------------------------------
// 9. HTTP page probes (same host)
// ---------------------------------------------------------------------------
$probeKey = $dsysProvidedKey !== '' ? $dsysProvidedKey : $dsysExpectedKey;
$baseForProbes = defined('APP_BASE_PATH') ? (string) APP_BASE_PATH : '';

$pages = [
    'This debug script' => '/debug_system_full.php?key=' . rawurlencode($probeKey),
    'login.php' => '/login.php',
    'select-module.php' => '/select-module.php',
    'ultimate/select-module (rewrite)' => '/ultimate/select-module',
    'index.php' => '/index.php',
];

dsys_section('9. HTTP page probes');
echo '<tr><th colspan="2"><span class="badge warn">Note</span> 302/401 to login is normal. <strong>500</strong> indicates server/bootstrap failure.</th></tr>';

foreach ($pages as $label => $path) {
    $fullPath = $baseForProbes . $path;
    $url = dsys_build_url($fullPath);
    $probe = dsys_http_probe($url);
    $detail = 'HTTP ' . $probe['code'];
    if ($probe['error'] !== '') {
        $detail .= ' ù ' . $probe['error'];
    }
    if ($probe['body'] !== '') {
        $detail .= ' | ' . $probe['body'];
    }
    dsys_status($probe['ok'], $label, $url . ' ? ' . $detail);
}
dsys_end_section();

// ---------------------------------------------------------------------------
// 10. Recommendations
// ---------------------------------------------------------------------------
echo '<h2>10. Common fixes for HTTP 500</h2>';
echo '<div class="warnbox" style="max-width:1200px"><ol style="margin:8px 0 0 18px;padding:0">';
echo '<li>Upload <code>env.php</code> to the server root with correct <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> (StackCP / InfinityFree values).</li>';
echo '<li>Ensure <code>DATA_DB_NAME</code> points to the tenant DB that has vouchers/sales data if control DB is metadata-only.</li>';
echo '<li>Fix any <strong>FAIL</strong> on <code>includes/functions.php</code> syntax or bootstrap ù that file runs on every page.</li>';
echo '<li>Grant MySQL user ALL PRIVILEGES on control + each <code>companies.db_name</code> database.</li>';
echo '<li>Check host error log (section 1) for the exact fatal error line.</li>';
echo '<li>Run <a href="debug_db_connections.php?key=' . dsys_h($probeKey) . '">debug_db_connections.php</a> for detailed per-company DB tests.</li>';
echo '</ol></div>';

echo '<p style="margin-top:24px"><span class="badge warn">Delete</span> Remove <code>debug_system_full.php</code>, <code>debug_online.php</code>, and <code>debug_db_connections.php</code> after fixing production.</p>';
echo '</body></html>';
