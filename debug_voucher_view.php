<?php
/**
 * Live debug: payment voucher view / approvals table (remove after fixing).
 *
 * Ping (no login):  /ultimate/debug_voucher_view.php?ping=1
 * Full report:      /ultimate/debug_voucher_view.php?id=437
 * Plain text:       /ultimate/debug_voucher_view.php?id=437&format=text
 */
define('DEBUG_VOUCHER_VIEW_BUILD', '20260523d');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
$asText = ($format === 'text' || $format === 'plain');
$isPing = isset($_GET['ping']) && (string) $_GET['ping'] === '1';

if ($isPing) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "DEBUG_VOUCHER_VIEW_BUILD=" . DEBUG_VOUCHER_VIEW_BUILD . "\n";
    echo "php=" . PHP_VERSION . "\n";
    echo "file=" . __FILE__ . "\n";
    echo "exists=" . (is_file(__FILE__) ? 'yes' : 'no') . "\n";
    echo "OK\n";
    exit;
}

$lines = array();
$rendered = false;

function dvv_line($type, $msg)
{
    global $lines;
    $lines[] = array('type' => $type, 'msg' => $msg);
}

function dvv_out($text)
{
    global $lines;
    $lines[] = array('type' => 'raw', 'msg' => $text);
}

function dvv_render()
{
    global $lines, $asText, $rendered;
    if ($rendered) {
        return;
    }
    $rendered = true;

    if ($asText) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "DEBUG_VOUCHER_VIEW_BUILD=" . DEBUG_VOUCHER_VIEW_BUILD . "\n";
        echo "time=" . date('c') . "\n\n";
        foreach ($lines as $row) {
            $t = isset($row['type']) ? strtoupper($row['type']) : 'INFO';
            $m = isset($row['msg']) ? $row['msg'] : '';
            if ($t === 'RAW') {
                echo $m . "\n";
            } else {
                echo '[' . $t . '] ' . $m . "\n";
            }
        }
        return;
    }

    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(200);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Voucher debug</title>';
    echo '<style>body{font-family:system-ui,sans-serif;margin:24px;background:#f8fafc;color:#0f172a}';
    echo '.box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin:0 0 10px}';
    echo '.ok{color:#047857}.warn{color:#b45309}.fail{color:#b91c1c;font-weight:700}.info{color:#1d4ed8}';
    echo 'pre{background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow:auto;font-size:12px}</style></head><body>';
    echo '<h1>Voucher view debug</h1>';
    echo '<p>BUILD=' . htmlspecialchars(DEBUG_VOUCHER_VIEW_BUILD, ENT_QUOTES, 'UTF-8') . ' &middot; ' . htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8') . '</p>';
    foreach ($lines as $row) {
        $t = isset($row['type']) ? $row['type'] : 'info';
        $m = isset($row['msg']) ? $row['msg'] : '';
        if ($t === 'raw') {
            echo '<div class="box"><pre>' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</pre></div>';
            continue;
        }
        echo '<div class="box ' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '<p>Delete <code>debug_voucher_view.php</code> when finished.</p></body></html>';
}

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }
    dvv_line('fail', 'Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']);
    dvv_render();
});

set_exception_handler(function ($e) {
    dvv_line('fail', 'Exception: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    dvv_render();
    exit;
});

// --- Bootstrap ---
try {
    if (!is_file(__DIR__ . '/includes/functions.php')) {
        dvv_line('fail', 'Missing includes/functions.php');
        dvv_render();
        exit;
    }
    require_once __DIR__ . '/includes/functions.php';
    dvv_line('ok', 'includes/functions.php loaded');
} catch (Exception $e) {
    dvv_line('fail', 'Bootstrap failed: ' . $e->getMessage());
    dvv_render();
    exit;
} catch (Throwable $e) {
    dvv_line('fail', 'Bootstrap failed: ' . $e->getMessage());
    dvv_render();
    exit;
}

// --- Auth (safe: never call missing helpers) ---
$probeOk = false;
$probeToken = trim((string) ($_GET['probe'] ?? ''));
$expectedProbe = '';
if (is_file(__DIR__ . '/env.local.php')) {
    $envLocal = @include __DIR__ . '/env.local.php';
    if (is_array($envLocal) && !empty($envLocal['DEBUG_VOUCHER_PROBE'])) {
        $expectedProbe = (string) $envLocal['DEBUG_VOUCHER_PROBE'];
    }
}
if ($expectedProbe !== '' && $probeToken !== '' && function_exists('hash_equals') && hash_equals($expectedProbe, $probeToken)) {
    $probeOk = true;
}

if (!$probeOk) {
    if (!function_exists('requireLogin')) {
        dvv_line('fail', 'requireLogin() not found');
        dvv_render();
        exit;
    }
    try {
        requireLogin();
    } catch (Exception $e) {
        dvv_line('fail', 'requireLogin failed: ' . $e->getMessage());
        dvv_render();
        exit;
    } catch (Throwable $e) {
        dvv_line('fail', 'requireLogin failed: ' . $e->getMessage());
        dvv_render();
        exit;
    }

    $allowed = false;
    $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($sessionUserId > 0) {
        $allowed = true;
    }
    if (function_exists('isAdmin') && isAdmin()) {
        $allowed = true;
    }
    if (!$allowed && function_exists('isFinance') && isFinance()) {
        $allowed = true;
    }
    if (!$allowed) {
        dvv_line('fail', 'Not logged in. Open this URL in the same browser where you are signed in to Ultitech, or use ?ping=1, or set DEBUG_VOUCHER_PROBE in env.local.php');
        dvv_render();
        exit;
    }
    $roleLabel = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'unknown';
    dvv_line('info', 'Logged in as user_id=' . $sessionUserId . ' role=' . $roleLabel);
}

$voucherId = max(0, (int) ($_GET['id'] ?? 437));
if ($voucherId < 1) {
    dvv_line('fail', 'Invalid voucher id');
    dvv_render();
    exit;
}

dvv_line('info', 'BUILD=' . DEBUG_VOUCHER_VIEW_BUILD);
dvv_line('info', 'PHP ' . PHP_VERSION);
dvv_line('info', 'Voucher ID ' . $voucherId);
dvv_line('info', 'Auth: ' . ($probeOk ? 'probe' : 'session'));

// --- Files ---
$paths = array(
    'view-voucher.php' => __DIR__ . '/view-voucher.php',
    'includes/voucher-approvals-table.php' => __DIR__ . '/includes/voucher-approvals-table.php',
    'includes/voucher-approval-flow-data.php' => __DIR__ . '/includes/voucher-approval-flow-data.php',
);

dvv_out('--- FILES ---');
foreach ($paths as $label => $path) {
    if (!is_file($path)) {
        dvv_line('fail', 'Missing ' . $label);
        continue;
    }
    dvv_line('ok', $label . ' | ' . (int) filesize($path) . ' bytes | ' . date('Y-m-d H:i:s', (int) filemtime($path)));
}

$rootView = $paths['view-voucher.php'];
if (is_file($rootView)) {
    $src = (string) @file_get_contents($rootView);
    $posApprovals = strpos($src, 'voucher-approvals-table.php');
    $posFlow = strpos($src, 'voucher-approval-flow-data.php');
    if ($posApprovals === false || $posFlow === false) {
        dvv_line('warn', 'view-voucher.php: include paths not found');
    } elseif ($posFlow > $posApprovals) {
        dvv_line('fail', 'view-voucher.php: approval-flow-data is AFTER approvals-table (wrong order  swap requires)');
    } else {
        dvv_line('ok', 'view-voucher.php: approval-flow-data before approvals-table (correct)');
    }
}

// --- DB ---
global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    dvv_line('fail', 'PDO not available');
    dvv_render();
    exit;
}

try {
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    dvv_line('ok', 'Database: ' . $dbName);
} catch (Exception $e) {
    dvv_line('warn', 'DATABASE() failed: ' . $e->getMessage());
}

$voucher = null;
try {
    $vStmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
    $vStmt->execute(array($voucherId));
    $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    dvv_line('fail', 'payment_vouchers: ' . $e->getMessage());
    dvv_render();
    exit;
}

if (!$voucher) {
    dvv_line('fail', 'Voucher not found id=' . $voucherId);
    dvv_render();
    exit;
}

dvv_line('ok', 'Voucher ' . (isset($voucher['voucher_no']) ? $voucher['voucher_no'] : '') . ' status=' . (isset($voucher['status']) ? $voucher['status'] : ''));

dvv_out('--- HEADER FIELDS ---');
foreach (array('applicant', 'checked_by', 'department_manager', 'general_manager') as $f) {
    $val = trim((string) (isset($voucher[$f]) ? $voucher[$f] : ''));
    dvv_line($val === '' ? 'warn' : 'info', $f . ': ' . ($val !== '' ? $val : '(empty)'));
}

$approvals = array();
try {
    $aStmt = $pdo->prepare('SELECT id, approver_name, role, status, approved_at FROM approvals WHERE voucher_id = ? ORDER BY id');
    $aStmt->execute(array($voucherId));
    $approvals = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($approvals)) {
        $approvals = array();
    }
} catch (Exception $e) {
    dvv_line('fail', 'approvals: ' . $e->getMessage());
}

dvv_out('--- APPROVALS (' . count($approvals) . ' rows) ---');
foreach ($approvals as $ar) {
    dvv_line('info', sprintf(
        'id=%s role=%s status=%s name=%s',
        isset($ar['id']) ? $ar['id'] : '',
        isset($ar['role']) ? $ar['role'] : '',
        isset($ar['status']) ? $ar['status'] : '',
        isset($ar['approver_name']) ? $ar['approver_name'] : ''
    ));
}

$roleStatusMap = array();
foreach ($approvals as $ap) {
    $r = strtolower(trim((string) (isset($ap['role']) ? $ap['role'] : '')));
    if ($r !== '') {
        $roleStatusMap[$r] = strtolower(trim((string) (isset($ap['status']) ? $ap['status'] : '')));
    }
}

$voucher_id = $voucherId;
$allStages = array();
try {
    require __DIR__ . '/includes/voucher-approval-flow-data.php';
    dvv_line('ok', 'allStages count=' . count($allStages));
} catch (Exception $e) {
    dvv_line('fail', 'voucher-approval-flow-data: ' . $e->getMessage());
} catch (Throwable $e) {
    dvv_line('fail', 'voucher-approval-flow-data: ' . $e->getMessage());
}

dvv_out('--- DRY-RUN TABLE ---');
$gmDisplay = trim((string) (isset($voucher['general_manager']) ? $voucher['general_manager'] : ''));
$statusLower = strtolower((string) (isset($voucher['status']) ? $voucher['status'] : ''));
$gmSigRel = null;
$signaturesByName = array();
$phonesByName = array();
$userPhotos = array();
$normalizePersonName = function ($name) {
    return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
};
$resolveProfilePhotoUrl = function ($photo) {
    return function_exists('mediaUrlFromPath') ? mediaUrlFromPath((string) $photo) : '';
};
if (!function_exists('renderWaLink')) {
    function renderWaLink($name, $phones)
    {
        return '';
    }
}

ob_start();
try {
    require __DIR__ . '/includes/voucher-approvals-table.php';
    $html = (string) ob_get_clean();
    dvv_line(strlen($html) > 50 ? 'ok' : 'fail', 'Approvals HTML length=' . strlen($html));
    if (!$asText && strlen($html) > 0) {
        dvv_out($html);
    }
} catch (Exception $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    dvv_line('fail', 'voucher-approvals-table: ' . $e->getMessage());
} catch (Throwable $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    dvv_line('fail', 'voucher-approvals-table: ' . $e->getMessage());
}

dvv_render();
