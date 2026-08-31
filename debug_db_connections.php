<?php
/**
 * Database connectivity diagnostic (control DB + tenant DBs).
 * Delete this file after troubleshooting.
 */

@ini_set('display_errors', '1');
@error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');

function dbg_row($label, $value = null)
{
    if ($value === null) {
        echo '<tr><th colspan="2">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th></tr>';
        return;
    }
    echo '<tr><th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th><td>'
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
}

function dbg_status($ok, $label, $detail = '')
{
    $badge = $ok ? 'ok' : 'fail';
    $text = $ok ? 'OK' : 'FAIL';
    echo '<tr><th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th><td>';
    echo '<span class="badge ' . $badge . '">' . $text . '</span>';
    if ($detail !== '') {
        echo ' ' . htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8');
    }
    echo '</td></tr>';
}

function dbg_mask($value)
{
    $value = (string) $value;
    $len = strlen($value);
    if ($len <= 2) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 1) . str_repeat('*', max(0, $len - 2)) . substr($value, -1);
}

function dbg_table_exists(PDO $pdo, $table)
{
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([(string) $table]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function dbg_try_connect($host, $dbName, $user, $pass)
{
    $host = trim((string) $host);
    $dbName = trim((string) $dbName);
    if ($host === '' || $dbName === '') {
        return array('ok' => false, 'pdo' => null, 'host' => $host, 'message' => 'Missing host or database name');
    }
    try {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string) $user, (string) $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ));
        $pdo->exec("SET time_zone = '+03:00'");
        return array('ok' => true, 'pdo' => $pdo, 'host' => $host, 'message' => 'Connected');
    } catch (Exception $e) {
        return array('ok' => false, 'pdo' => null, 'host' => $host, 'message' => $e->getMessage());
    }
}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>DB Connection Debug</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:24px;background:#f8fafc;color:#111827}';
echo 'table{border-collapse:collapse;width:100%;max-width:1100px;background:#fff;margin:12px 0;box-shadow:0 1px 3px rgba(0,0,0,.08)}';
echo 'th,td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top}th{background:#f3f4f6;width:240px}';
echo '.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}';
echo '.ok{background:#dcfce7;color:#166534}.fail{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}';
echo 'h1{margin:0 0 8px}p{color:#4b5563}</style></head><body>';
echo '<h1>ULTITECH ? Database Connection Test</h1>';
echo '<p>Tests control database and each company tenant database. Remove this file when finished.</p>';

echo '<table>';
dbg_row('Environment');
dbg_row('Time', date('c'));
dbg_row('PHP', PHP_VERSION);
dbg_row('Host', $_SERVER['HTTP_HOST'] ?? '');
dbg_row('Request URI', $_SERVER['REQUEST_URI'] ?? '');
dbg_row('Script', __FILE__);
echo '</table>';

$bootstrapOk = false;
$bootstrapError = '';
$controlPdo = null;
$companies = array();

try {
    require_once __DIR__ . '/includes/functions.php';
    $bootstrapOk = true;
    $controlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);
} catch (Exception $e) {
    $bootstrapError = $e->getMessage();
} catch (Throwable $e) {
    $bootstrapError = $e->getMessage();
}

echo '<table>';
dbg_row('Bootstrap');
dbg_status($bootstrapOk, 'includes/functions.php', $bootstrapOk ? 'loaded' : $bootstrapError);
if ($bootstrapOk) {
    dbg_row('APP_BASE_PATH', defined('APP_BASE_PATH') ? APP_BASE_PATH : '(not defined)');
    dbg_row('APP_ENV', defined('APP_ENV') ? APP_ENV : '(not defined)');
    dbg_row('DB_HOST', defined('DB_HOST') ? DB_HOST : '(not defined)');
    dbg_row('DB_NAME (control)', defined('DB_NAME') ? DB_NAME : '(not defined)');
    dbg_row('DB_USER', defined('DB_USER') ? DB_USER : '(not defined)');
    dbg_row('DB_PASS', defined('DB_PASS') ? dbg_mask(DB_PASS) : '(not defined)');
    if (function_exists('getRequestedCompanySlug')) {
        dbg_row('Detected company slug (this URL)', getRequestedCompanySlug() !== '' ? getRequestedCompanySlug() : '(none)');
    }
}
echo '</table>';

if (!$bootstrapOk || !($controlPdo instanceof PDO)) {
    echo '<p><strong>Cannot continue:</strong> bootstrap or control PDO unavailable.</p></body></html>';
    exit;
}

$schemaBeforeCompanies = dbg_table_exists($controlPdo, 'companies');
$schemaBeforeUsers = dbg_table_exists($controlPdo, 'users');
$schemaRan = false;
$schemaOk = false;
$schemaError = '';
if (function_exists('ensureMultiCompanyControlSchema')) {
    $schemaRan = true;
    $schemaOk = (bool) ensureMultiCompanyControlSchema();
    if (function_exists('getLastControlSchemaError')) {
        $schemaError = getLastControlSchemaError();
    }
}

echo '<table>';
dbg_row('Schema migration');
dbg_status($schemaOk, 'ensureMultiCompanyControlSchema()', $schemaRan ? 'executed on this request' : 'function missing');
if ($schemaError !== '') {
    dbg_row('Schema error detail', $schemaError);
}
dbg_row('companies before run', $schemaBeforeCompanies ? 'yes' : 'no');
dbg_row('users before run', $schemaBeforeUsers ? 'yes' : 'no');
if (!$schemaBeforeCompanies || !$schemaBeforeUsers) {
    echo '<tr><th>Fix</th><td><a href="setup_multicompany_db.php">setup_multicompany_db.php</a> (one-time) or reload this page after uploading updated includes/functions.php</td></tr>';
}
echo '</table>';

echo '<table>';
dbg_row('Control database');
try {
    $activeDb = (string) $controlPdo->query('SELECT DATABASE()')->fetchColumn();
    dbg_status(true, 'Active database', $activeDb);
} catch (Exception $e) {
    dbg_status(false, 'Active database', $e->getMessage());
}

$requiredControlTables = array('companies', 'users');
foreach ($requiredControlTables as $table) {
    $exists = dbg_table_exists($controlPdo, $table);
    dbg_status($exists, 'Table: ' . $table, $exists ? 'present' : 'missing');
}

try {
    $hasDbUser = columnExists('companies', 'db_user', $controlPdo);
    $hasDbPass = columnExists('companies', 'db_pass', $controlPdo);
    $sql = 'SELECT id, company_name, company_slug, db_name, db_host'
        . ($hasDbUser ? ', db_user' : '')
        . ($hasDbPass ? ', db_pass' : '')
        . ', status FROM companies ORDER BY id ASC';
    $companies = $controlPdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: array();
    dbg_row('Companies found', (string) count($companies));
} catch (Exception $e) {
    dbg_status(false, 'Load companies', $e->getMessage());
}

try {
    $tables = $controlPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: array();
    dbg_row('Tables in control DB', implode(', ', array_map('strval', $tables)));
} catch (Exception $e) {
    dbg_status(false, 'SHOW TABLES', $e->getMessage());
}
echo '</table>';

$useUser = defined('DB_USER') ? DB_USER : 'root';
$usePass = defined('DB_PASS') ? DB_PASS : '';
$configuredHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$controlDbName = defined('DB_NAME') ? DB_NAME : '';
$hostsToTry = array_values(array_unique(array_filter(array(
    (string) $configuredHost,
    'localhost',
    '127.0.0.1',
), function ($h) {
    return trim((string) $h) !== '';
})));

echo '<table>';
dbg_row('Per-company tenant databases');

if (empty($companies)) {
    dbg_status(false, 'No companies', 'companies table empty or unreadable');
} else {
    foreach ($companies as $company) {
        $id = (int) ($company['id'] ?? 0);
        $name = (string) ($company['company_name'] ?? '');
        $slug = strtolower(trim((string) ($company['company_slug'] ?? '')));
        $tenantDb = trim((string) ($company['db_name'] ?? ''));
        $tenantHost = trim((string) ($company['db_host'] ?? ''));
        $status = strtolower(trim((string) ($company['status'] ?? '')));
        $tenantUser = trim((string) ($company['db_user'] ?? ''));
        $tenantPass = $usePass;
        if (array_key_exists('db_pass', $company)) {
            $rawPass = (string) ($company['db_pass'] ?? '');
            if (trim($rawPass) !== '') {
                $tenantPass = $rawPass;
            }
        }
        if (function_exists('useGlobalDbCredentialsForTenants') && useGlobalDbCredentialsForTenants()) {
            $tenantUser = $useUser;
            $tenantPass = $usePass;
        } elseif ($tenantUser === '') {
            $tenantUser = $useUser;
        }

        echo '<tr><th colspan="2">Company #' . $id . ' ? ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            . ' (slug: ' . htmlspecialchars($slug !== '' ? $slug : '(none)', ENT_QUOTES, 'UTF-8')
            . ', status: ' . htmlspecialchars($status !== '' ? $status : 'unknown', ENT_QUOTES, 'UTF-8') . ')</th></tr>';
        dbg_row('db_host', $tenantHost !== '' ? $tenantHost : '(default: ' . $configuredHost . ')');
        dbg_row('db_user', $tenantUser !== '' ? $tenantUser : '(from env.php)');

        if ($tenantDb === '') {
            dbg_status(false, 'db_name', 'empty ? uses control DB only');
            if ($controlDbName !== '') {
                $shared = dbg_try_connect($configuredHost, $controlDbName, $useUser, $usePass);
                if (!$shared['ok']) {
                    foreach ($hostsToTry as $hostTry) {
                        if ($hostTry === $configuredHost) {
                            continue;
                        }
                        $shared = dbg_try_connect($hostTry, $controlDbName, $useUser, $usePass);
                        if ($shared['ok']) {
                            break;
                        }
                    }
                }
                dbg_status($shared['ok'], 'Shared control connection', $shared['message']);
                if ($shared['ok'] && $shared['pdo'] instanceof PDO) {
                    $userCount = dbg_table_exists($shared['pdo'], 'users')
                        ? (int) $shared['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn()
                        : 0;
                    dbg_row('users row count (control)', (string) $userCount);
                }
            }
            continue;
        }

        $connected = false;
        $usedHost = '';
        $lastError = '';
        $tenantPdo = null;
        $tenantHosts = array($tenantHost);
        foreach ($hostsToTry as $hostTry) {
            if ($hostTry !== '' && !in_array($hostTry, $tenantHosts, true)) {
                $tenantHosts[] = $hostTry;
            }
        }
        $tenantHosts = array_values(array_filter($tenantHosts, function ($h) {
            return trim((string) $h) !== '';
        }));

        foreach ($tenantHosts as $hostTry) {
            $attempt = dbg_try_connect($hostTry, $tenantDb, $tenantUser, $tenantPass);
            if ($attempt['ok']) {
                $connected = true;
                $usedHost = (string) $attempt['host'];
                $tenantPdo = $attempt['pdo'];
                $lastError = '';
                break;
            }
            $lastError = (string) $attempt['message'];
        }

        dbg_status($connected, 'Tenant DB: ' . $tenantDb, $connected ? ('host=' . $usedHost) : $lastError);

        if ($connected && $tenantPdo instanceof PDO) {
            try {
                $tenantActive = (string) $tenantPdo->query('SELECT DATABASE()')->fetchColumn();
                dbg_row('Tenant active DB', $tenantActive);
            } catch (Exception $e) {
                dbg_status(false, 'Tenant active DB', $e->getMessage());
            }

            $tenantHasUsers = dbg_table_exists($tenantPdo, 'users');
            dbg_status($tenantHasUsers, 'Tenant users table', $tenantHasUsers ? 'present' : 'missing');
            if ($tenantHasUsers) {
                try {
                    $count = (int) $tenantPdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                    dbg_row('Tenant users count', (string) $count);
                } catch (Exception $e) {
                    dbg_status(false, 'Tenant users count', $e->getMessage());
                }
            }
        }
    }
}
echo '</table>';

echo '<table>';
dbg_row('Login path hints');
dbg_row('Generic login URL', function_exists('app_url') ? app_url('/login.php?next=my-account.php') : '/login.php?next=my-account.php');
if (!empty($companies)) {
    foreach ($companies as $company) {
        $slug = strtolower(trim((string) ($company['company_slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $url = function_exists('app_url') ? app_url('/' . $slug . '/login') : ('/' . $slug . '/login');
        dbg_row('Workspace login (' . $slug . ')', $url);
    }
}
echo '</table>';

echo '<p><span class="badge warn">Security</span> Delete <code>debug_db_connections.php</code> after use.</p>';
echo '</body></html>';
