<?php

/**
 * Online deployment debug script for HTTP 500 issues.
 * Remove this file after troubleshooting.
 */

header('Content-Type: text/plain; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

function out($line = '')
{
    echo $line . PHP_EOL;
}

function maskSecret($value)
{
    $value = (string) $value;
    $len = strlen($value);
    if ($len <= 2) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
}

function checkPath($path)
{
    $path = (string) $path;
    out(sprintf(
        '[PATH] %s | exists=%s | readable=%s | writable=%s',
        $path,
        file_exists($path) ? 'yes' : 'no',
        is_readable($path) ? 'yes' : 'no',
        is_writable($path) ? 'yes' : 'no'
    ));
}

out('=== ULTITECH ONLINE DEBUG ===');
out('Time: ' . date('c'));
out('PHP: ' . PHP_VERSION);
out('SAPI: ' . PHP_SAPI);
out('Host: ' . ($_SERVER['HTTP_HOST'] ?? 'n/a'));
out('Document Root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a'));
out('Script: ' . __FILE__);
out();

$root = __DIR__;
$envRoot = $root . '/env.php';
$envIncludes = $root . '/includes/env.php';
$envLocal = $root . '/env.local.php';
$configIncludes = $root . '/includes/config.php';
$storageDir = $root . '/storage';

checkPath($envRoot);
checkPath($envIncludes);
checkPath($envLocal);
checkPath($configIncludes);
checkPath($storageDir);
out();

$DB_HOST = '';
$DB_NAME = '';
$DB_USER = '';
$DB_PASS = '';
$APP_ENV = '';
$SITE_URL = '';

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = in_array($host, ['localhost', '127.0.0.1'], true) || (strlen($host) >= 6 && substr($host, -6) === '.local');
$envCandidates = $isLocalHost
    ? [$envLocal, $root . '/includes/env.local.php', $envRoot, $envIncludes]
    : [$envRoot, $envIncludes, $envLocal, $root . '/includes/env.local.php'];

out('=== ENV LOAD ORDER ===');
foreach ($envCandidates as $candidate) {
    out('- ' . $candidate);
}
out();

$loadedEnv = null;
foreach ($envCandidates as $candidate) {
    if (file_exists($candidate)) {
        /** @noinspection PhpIncludeInspection */
        include $candidate;
        $loadedEnv = $candidate;
        if (isset($DB_HOST, $DB_NAME, $DB_USER)) {
            break;
        }
    }
}

out('=== LOADED ENV ===');
out('Loaded file: ' . ($loadedEnv ?? 'none'));
out('DB_HOST: ' . ($DB_HOST !== '' ? $DB_HOST : '(empty)'));
out('DB_NAME: ' . ($DB_NAME !== '' ? $DB_NAME : '(empty)'));
out('DB_USER: ' . ($DB_USER !== '' ? $DB_USER : '(empty)'));
out('DB_PASS: ' . ($DB_PASS !== '' ? maskSecret((string) $DB_PASS) : '(empty)'));
out('APP_ENV: ' . ($APP_ENV !== '' ? $APP_ENV : '(empty)'));
out('SITE_URL: ' . ($SITE_URL !== '' ? $SITE_URL : '(empty)'));
out();

out('=== DB CONNECTION TESTS ===');
$hostsToTry = array_values(array_unique(array_filter(array(
    (string) $DB_HOST,
    'localhost',
    '127.0.0.1',
) , function ($h) {
    return trim((string) $h) !== '';
})));

$connected = false;
$pdo = null;
foreach ($hostsToTry as $h) {
    try {
        $dsn = 'mysql:host=' . $h . ';dbname=' . $DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string) $DB_USER, (string) $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        out('[OK] Connected using host=' . $h);
        $connected = true;
        break;
    } catch (Exception $e) {
        out('[FAIL] host=' . $h . ' | ' . $e->getMessage());
    }
}

if ($connected && $pdo instanceof PDO) {
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        out('[OK] Active DB: ' . (string) $db);
    } catch (Exception $e) {
        out('[WARN] Could not read active DB: ' . $e->getMessage());
    }

    try {
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'companies'")->fetchColumn();
        out('[OK] companies table exists: ' . ($exists > 0 ? 'yes' : 'no'));
        if ($exists > 0) {
            $rows = $pdo->query("SELECT id, company_slug, db_name FROM companies ORDER BY id ASC LIMIT 10")->fetchAll();
            out('companies sample:');
            foreach ($rows as $r) {
                out(sprintf('  - id=%s slug=%s db_name=%s', (string) $r['id'], (string) $r['company_slug'], (string) $r['db_name']));
            }
        }
    } catch (Exception $e) {
        out('[WARN] companies query failed: ' . $e->getMessage());
    }
} else {
    out('[ERROR] No database host worked.');
}

out();
out('=== NEXT STEPS ===');
out('1) Ensure env.php has correct DB host/name/user/pass.');
out('2) Ensure DB user has ALL privileges on control DB.');
out('3) Ensure companies.db_name points to valid tenant DB names.');
out('4) Delete this file after debugging.');
out();
out('=== BOOTSTRAP TEST ===');
try {
    require_once __DIR__ . '/includes/config.php';
    out('[OK] includes/config.php loaded');
} catch (Throwable $e) {
    out('[FAIL] includes/config.php exception: ' . $e->getMessage());
}
try {
    require_once __DIR__ . '/includes/functions.php';
    out('[OK] includes/functions.php loaded');
} catch (Throwable $e) {
    out('[FAIL] includes/functions.php exception: ' . $e->getMessage());
}
if (function_exists('ensureMultiCompanyControlSchema')) {
    try {
        ensureMultiCompanyControlSchema();
        out('[OK] ensureMultiCompanyControlSchema() executed');
    } catch (Throwable $e) {
        out('[FAIL] ensureMultiCompanyControlSchema() exception: ' . $e->getMessage());
    }
} else {
    out('[WARN] ensureMultiCompanyControlSchema() not found');
}
out('=== END ===');

