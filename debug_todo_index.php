<?php
/**
 * Live debug for HTTP 500 on /{company_slug}/todo/index (e.g. /ultimate/todo/index).
 * DELETE this file after troubleshooting.
 *
 * Open:
 *   https://ultitech.io/debug_todo_index.php
 *   https://ultitech.io/ultimate/debug_todo_index.php?company_slug=ultimate
 * Optional: ?probe=1 to buffer-include todo/index.php
 *           ?slug=ultimate to test tenant resolution (default: ultimate)
 */

define('ULTITECH_DIAGNOSTIC_SCRIPT', true);

// Make this script impossible to fail silently: no buffering, errors visible, flush as we go.
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
@error_reporting(E_ALL);
while (ob_get_level() > 0) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(200);
}

// Surface anything that would otherwise blank-500 the page.
set_exception_handler(static function ($e) {
    echo '<div style="background:#7f1d1d;color:#fff;padding:12px;margin:12px 0;font-family:monospace;">'
        . '<strong>UNCAUGHT EXCEPTION:</strong> ' . htmlspecialchars((string) $e->getMessage(), ENT_QUOTES, 'UTF-8')
        . ' @ ' . htmlspecialchars((string) $e->getFile(), ENT_QUOTES, 'UTF-8') . ':' . (int) $e->getLine() . '</div>';
});
set_error_handler(static function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    echo '<div style="color:#fca5a5;font-family:monospace;font-size:12px;">[php] '
        . htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8') . ' @ '
        . htmlspecialchars((string) $file, ENT_QUOTES, 'UTF-8') . ':' . (int) $line . '</div>';
    return true;
});

$root = __DIR__;
$testSlug = strtolower(trim((string) ($_GET['slug'] ?? $_GET['company_slug'] ?? 'ultimate')));
$runProbe = isset($_GET['probe']) && (string) $_GET['probe'] !== '0';

function dbg_line(string $label, $value = null): void
{
    if ($value === null) {
        echo '<div class="line"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        return;
    }
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    } elseif (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    echo '<div class="line"><span class="k">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span> '
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</div>';
}

function dbg_section(string $title): void
{
    echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
}

function dbg_path(string $path): void
{
    $path = (string) $path;
    dbg_line($path, sprintf(
        'exists=%s readable=%s size=%s',
        file_exists($path) ? 'yes' : 'no',
        is_readable($path) ? 'yes' : 'no',
        file_exists($path) ? (string) filesize($path) : 'n/a'
    ));
}

function dbg_try(string $label, callable $fn): void
{
    try {
        $fn();
        dbg_line('[OK] ' . $label);
    } catch (Throwable $e) {
        dbg_line('[FAIL] ' . $label, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}

// Fatal-safe shutdown: flush any open buffers and print the real fatal instead of a blank 500.
register_shutdown_function(static function () {
    $err = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!$err || !in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    // Drain nested output buffers opened during the probe so partial output is not lost.
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<div style="background:#7f1d1d;color:#fff;border:2px solid #f87171;padding:14px;'
        . 'border-radius:8px;margin:16px 0;font-family:Consolas,monospace;font-size:13px;">';
    echo '<h2 style="margin:0 0 8px;color:#fecaca;">FATAL ERROR CAPTURED</h2>';
    echo '<div><strong>Message:</strong> ' . htmlspecialchars((string) ($err['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div><strong>File:</strong> ' . htmlspecialchars((string) ($err['file'] ?? ''), ENT_QUOTES, 'UTF-8')
        . ':' . htmlspecialchars((string) ($err['line'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div><strong>Type:</strong> ' . htmlspecialchars((string) ($err['type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<p style="margin:8px 0 0;color:#fecaca;">This is the error crashing /ultimate/todo/index. Fix it, then retest.</p>';
    echo '</div></body></html>';
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Todo Index Debug</title>
    <style>
        body { font-family: Consolas, Monaco, monospace; background: #0f172a; color: #e2e8f0; margin: 0; padding: 16px; font-size: 13px; }
        h1 { color: #a5b4fc; font-size: 18px; margin: 0 0 8px; }
        h2 { color: #94a3b8; font-size: 14px; margin: 20px 0 8px; border-bottom: 1px solid #334155; padding-bottom: 4px; }
        .warn { background: #451a03; border: 1px solid #ea580c; padding: 10px; border-radius: 8px; margin-bottom: 16px; }
        .line { margin: 3px 0; word-break: break-all; }
        .k { color: #7dd3fc; }
        a { color: #93c5fd; }
        pre { background: #1e293b; padding: 10px; border-radius: 8px; overflow: auto; }
    </style>
</head>
<body>
<h1>Todo index - live debug</h1>
<div class="warn">
    <strong>Remove <code>debug_todo_index.php</code> after fixing production.</strong>
    Add <code>?probe=1</code> to simulate loading <code>todo/index.php</code> (may redirect if not logged in).
</div>

<?php
@flush();
dbg_section('Request');
dbg_line('Time', date('c'));
dbg_line('PHP', PHP_VERSION);
dbg_line('Host', $_SERVER['HTTP_HOST'] ?? 'n/a');
dbg_line('REQUEST_URI', $_SERVER['REQUEST_URI'] ?? 'n/a');
dbg_line('SCRIPT_NAME', $_SERVER['SCRIPT_NAME'] ?? 'n/a');
dbg_line('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'] ?? 'n/a');
dbg_line('Test company slug', $testSlug);
dbg_line('Session id', session_id() !== '' ? session_id() : '(none yet)');

dbg_section('Expected rewrite for /ultimate/todo/index');
dbg_line('Rule target', 'todo/index.php?module=todo&company_slug=ultimate');
dbg_line('Alias file', 'ultimate/todo/index.php ? requires todo/index.php');

dbg_section('Entry files');
dbg_path($root . '/todo/index.php');
dbg_path($root . '/ultimate/todo/index.php');
dbg_path($root . '/todo/includes/weekly_mission_helpers.php');
dbg_path($root . '/includes/functions.php');
dbg_path($root . '/includes/config.php');
dbg_path($root . '/includes/header_employee.php');
dbg_path($root . '/includes/header_admin.php');
dbg_path($root . '/sidebar.php');

dbg_section('Bootstrap');
$configOk = false;
$functionsOk = false;
dbg_try('includes/config.php', static function () use ($root, &$configOk, &$functionsOk) {
    require_once $root . '/includes/config.php';
    $configOk = true;
    if (function_exists('isLoggedIn')) {
        $functionsOk = true;
    }
});
if (!$functionsOk) {
    dbg_try('includes/functions.php', static function () use ($root, &$functionsOk) {
        require_once $root . '/includes/functions.php';
        $functionsOk = true;
    });
}

if ($functionsOk) {
    dbg_section('App helpers');
    dbg_line('APP_BASE_PATH', defined('APP_BASE_PATH') ? APP_BASE_PATH : '(undefined)');
    dbg_line('APP_ENV', defined('APP_ENV') ? APP_ENV : '(undefined)');
    dbg_line('isLoggedIn()', function_exists('isLoggedIn') ? (isLoggedIn() ? 'yes' : 'no') : 'n/a');
    dbg_line('currentCompanyId()', function_exists('currentCompanyId') ? (string) currentCompanyId() : 'n/a');
    dbg_line('getRequestedCompanySlug()', function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : 'n/a');

    dbg_section('Company slug "' . $testSlug . '"');
    dbg_try('lookup companies row', static function () use ($testSlug) {
        global $control_pdo, $pdo;
        if (!isset($control_pdo) || !($control_pdo instanceof PDO)) {
            dbg_line('control_pdo', 'missing');
            return;
        }
        $st = $control_pdo->prepare('SELECT id, company_slug, db_name, status FROM companies WHERE LOWER(company_slug) = ? LIMIT 1');
        $st->execute([$testSlug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            dbg_line('[WARN]', 'No companies row for slug "' . $testSlug . '" � /ultimate/todo/index will 404 at bootstrap');
            return;
        }
        dbg_line('company', json_encode($row));
        if ($pdo instanceof PDO) {
            try {
                dbg_line('current PDO database', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
            } catch (Throwable $e) {
                dbg_line('PDO query failed', $e->getMessage());
            }
        }
    });

    dbg_section('Todo dependencies');
    dbg_try('weekly_mission_helpers.php', static function () use ($root) {
        require_once $root . '/todo/includes/weekly_mission_helpers.php';
        $b = wm_get_week_bounds();
        dbg_line('wm_get_week_bounds', json_encode($b));
        if (function_exists('wm_ensure_tables') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            dbg_line('wm_ensure_tables', wm_ensure_tables($GLOBALS['pdo']) ? 'ok' : 'failed');
        }
    });

    dbg_section('Auth state');
    $loggedIn = function_exists('isLoggedIn') ? isLoggedIn() : false;
    dbg_line('isLoggedIn()', $loggedIn);
    if (!$loggedIn) {
        dbg_line('[NOTE]', 'Not logged in. requireLogin() would header()+exit here; the header/probe sections need an authenticated session to run fully. Log into ' . ($_SERVER['HTTP_HOST'] ?? 'the site') . ' first, then reload this page.');
    }

    dbg_section('Header + sidebar include probe');
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
    $headerPath = $isAdmin
        ? $root . '/includes/header_admin.php'
        : $root . '/includes/header_employee.php';
    dbg_line('header chosen', $headerPath);
    if ($loggedIn) {
        dbg_try('buffer include header', static function () use ($headerPath) {
            ob_start();
            $rootPath = '../';
            $logoBase = '../';
            $employeeHeaderTitle = 'Debug';
            $page_title = 'Debug';
            include $headerPath;
            $out = ob_get_clean();
            dbg_line('output bytes', (string) strlen((string) $out));
        });
    } else {
        dbg_line('Skipped', 'header includes requireLogin(); log in first to test it.');
    }
}

if ($runProbe && $functionsOk) {
    dbg_section('PROBE: include todo/index.php (buffered)');
    if (empty($loggedIn)) {
        dbg_line('Skipped', 'Probe needs an authenticated session (todo/index.php calls requireLogin() and would redirect/exit). Log into the site, then reload with ?probe=1. A FATAL ERROR box will appear below if the page itself crashes.');
    } else {
        $_GET['module'] = 'todo';
        $_GET['company_slug'] = $testSlug;
        $todoFile = $root . '/todo/index.php';
        dbg_line('Including', $todoFile);
        dbg_try('todo/index.php probe', static function () use ($todoFile) {
            ob_start();
            $included = include $todoFile;
            $buf = (string) ob_get_clean();
            dbg_line('include return', is_bool($included) ? ($included ? 'true' : 'false') : gettype($included));
            dbg_line('buffer length', (string) strlen($buf));
            if (strlen($buf) < 800) {
                dbg_line('buffer preview', substr($buf, 0, 700));
            }
            foreach (headers_list() as $h) {
                if (stripos($h, 'Location:') === 0) {
                    dbg_line('redirect header', $h);
                }
            }
        });
    }
} else {
    dbg_section('Full page probe');
    dbg_line('Skipped', 'Add ?probe=1 to run buffered include of todo/index.php');
}

dbg_section('Done');
dbg_line('Next', 'Fix the first [FAIL] or shutdown fatal above, then retest https://ultitech.io/ultimate/todo/index');
?>
</body>
</html>
