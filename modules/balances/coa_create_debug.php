<?php
/**
 * Debug: diagnose blank / broken coa_create.php page.
 * DELETE after troubleshooting.
 *
 * Open:
 *   https://ultitech.io/ultimate/modules/balances/coa_create_debug.php
 *   https://ultitech.io/ultimate/modules/balances/coa_create_debug.php?format=text
 */
declare(strict_types=1);

define('ULTITECH_DIAGNOSTIC_SCRIPT', true);

if (isset($_GET['preview']) && (string) $_GET['preview'] === 'error') {
    require_once __DIR__ . '/includes/balances-error-page.php';
    balances_render_error_page("The page you're looking for doesn't exist or has been moved.", [
        'title' => 'Page unavailable',
        'headline' => 'Oops! Page unavailable',
        'home_url' => 'accounts.php?module=balances',
        'home_label' => 'Go Home',
        'retry_url' => '#back',
        'back_label' => 'Go Back',
        'error_code' => '500',
        'log_context' => 'coa_create_debug preview',
    ]);
}

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
$isText = ($format === 'text' || $format === 'plain');

if ($isText) {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: text/html; charset=utf-8');
}
header('Cache-Control: no-store, no-cache, must-revalidate');

$root = str_replace('\\', '/', dirname(__DIR__, 2));
$docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

$rows = [];
$verdicts = [];

function coa_dbg_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function coa_dbg_row(string $label, string $value, string $status = ''): void
{
    global $rows;
    $rows[] = ['label' => $label, 'value' => $value, 'status' => $status];
}

function coa_dbg_file_info(string $absPath): array
{
    if (!is_file($absPath)) {
        return [
            'exists' => false,
            'size' => null,
            'mtime' => null,
            'bom' => false,
            'first_bytes' => '',
            'head' => '',
        ];
    }

    $bytes = (string) @file_get_contents($absPath, false, null, 0, 16);
    $bom = (strlen($bytes) >= 3 && $bytes[0] === "\xEF" && $bytes[1] === "\xBB" && $bytes[2] === "\xBF");
    $firstBytes = '';
    for ($i = 0, $len = min(8, strlen($bytes)); $i < $len; $i++) {
        $firstBytes .= sprintf('%02X ', ord($bytes[$i]));
    }

    $head = (string) @file_get_contents($absPath, false, null, 0, 400);
    if ($bom) {
        $head = substr($head, 3);
    }

    return [
        'exists' => true,
        'size' => (int) filesize($absPath),
        'mtime' => (int) filemtime($absPath),
        'bom' => $bom,
        'first_bytes' => trim($firstBytes),
        'head' => $head,
    ];
}

function coa_dbg_status(bool $ok, bool $warn = false): string
{
    if ($ok) {
        return 'ok';
    }
    return $warn ? 'warn' : 'bad';
}

// ------------------------------------------------------------------
// Environment
// ------------------------------------------------------------------
coa_dbg_row('PHP version', PHP_VERSION, 'ok');
coa_dbg_row('__DIR__', __DIR__, 'ok');
coa_dbg_row('Computed app root', $root, 'ok');
coa_dbg_row('DOCUMENT_ROOT', $docRoot !== '' ? $docRoot : '(empty)', $docRoot !== '' ? 'ok' : 'warn');
coa_dbg_row('SCRIPT_NAME', $scriptName, 'ok');
coa_dbg_row('REQUEST_URI', $requestUri, 'ok');
coa_dbg_row('headers_sent (before bootstrap)', headers_sent() ? 'YES' : 'no', headers_sent() ? 'bad' : 'ok');

// ------------------------------------------------------------------
// Target files
// ------------------------------------------------------------------
$targets = [
    'coa_create.php' => __DIR__ . '/coa_create.php',
    'coa_edit.php' => __DIR__ . '/coa_edit.php',
    'accounts.php' => __DIR__ . '/accounts.php',
    'config/database.php' => __DIR__ . '/config/database.php',
    'includes/header.php' => __DIR__ . '/includes/header.php',
    'includes/footer.php' => __DIR__ . '/includes/footer.php',
];

foreach ($targets as $label => $abs) {
    $info = coa_dbg_file_info($abs);
    if (!$info['exists']) {
        coa_dbg_row($label, 'MISSING: ' . $abs, 'bad');
        continue;
    }

    $line = 'OK size=' . $info['size'] . ' mtime=' . date('Y-m-d H:i:s', $info['mtime']);
    $line .= ' first_bytes=' . ($info['first_bytes'] !== '' ? $info['first_bytes'] : '(empty)');
    if ($info['bom']) {
        $line .= ' *** UTF-8 BOM DETECTED ***';
    }
    coa_dbg_row($label, $line, coa_dbg_status(!$info['bom'], $info['bom']));
}

$coaInfo = coa_dbg_file_info(__DIR__ . '/coa_create.php');
if ($coaInfo['exists']) {
    $srcHead = (string) @file_get_contents(__DIR__ . '/coa_create.php', false, null, 0, 16000);
    $hasBuild = str_contains($srcHead, 'BALANCES_COA_CREATE_BUILD');
    coa_dbg_row('coa_create build marker', $hasBuild ? 'NEW (BALANCES_COA_CREATE_BUILD present)' : 'OLD (marker missing)', coa_dbg_status($hasBuild));
    coa_dbg_row('coa_create has requireLogin', str_contains($srcHead, 'requireLogin()') ? 'yes' : 'NO', coa_dbg_status(str_contains($srcHead, 'requireLogin()')));
    coa_dbg_row('coa_create has shutdown handler', str_contains($srcHead, 'register_shutdown_function') ? 'yes' : 'no', 'ok');
}

// ------------------------------------------------------------------
// BOM blank-page explanation
// ------------------------------------------------------------------
if ($coaInfo['exists'] && $coaInfo['bom']) {
    coa_dbg_row(
        'BOM impact',
        'coa_create.php emits invisible bytes before <?php. That marks headers as sent, so requireLogin() cannot redirect and exits with an empty 200 response.',
        'bad'
    );
    $verdicts[] = 'FAIL: coa_create.php has a UTF-8 BOM. Re-save/upload the file as UTF-8 without BOM.';
} elseif ($coaInfo['exists'] && !$coaInfo['bom']) {
    coa_dbg_row(
        'BOM impact',
        'No BOM on coa_create.php  login redirect should work.',
        'ok'
    );
}

// ------------------------------------------------------------------
// header_employee path resolution (same logic as includes/header.php)
// ------------------------------------------------------------------
$headerDir = __DIR__ . '/includes';
$candidateA = $headerDir . '/../../../includes/header_employee.php';
$candidateB = $headerDir . '/../../../../includes/header_employee.php';
$resolvedHeader = is_file($candidateA) ? $candidateA : (is_file($candidateB) ? $candidateB : '');

coa_dbg_row('header_employee candidate A', str_replace('\\', '/', $candidateA) . ' => ' . (is_file($candidateA) ? 'FOUND' : 'missing'), coa_dbg_status(is_file($candidateA)));
coa_dbg_row('header_employee candidate B', str_replace('\\', '/', $candidateB) . ' => ' . (is_file($candidateB) ? 'FOUND' : 'missing'), coa_dbg_status(is_file($candidateB)));
coa_dbg_row('header_employee resolved', $resolvedHeader !== '' ? str_replace('\\', '/', $resolvedHeader) : 'NOT FOUND', coa_dbg_status($resolvedHeader !== ''));

if ($resolvedHeader === '') {
    $verdicts[] = 'FAIL: includes/header_employee.php not found from balances/includes/. Header shell will be incomplete.';
}

// ------------------------------------------------------------------
// Bootstrap + auth (does not call requireLogin  diagnostic only)
// ------------------------------------------------------------------
$bootstrapOk = false;
$pdoOk = false;
$bootstrapError = '';

try {
    require_once __DIR__ . '/config/database.php';
    $bootstrapOk = true;
} catch (Throwable $e) {
    $bootstrapError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

coa_dbg_row('database bootstrap', $bootstrapOk ? 'OK' : ('FAIL: ' . $bootstrapError), coa_dbg_status($bootstrapOk));

global $pdo;
if ($bootstrapOk && $pdo instanceof PDO) {
    $pdoOk = true;
    try {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        coa_dbg_row('PDO database', $dbName, 'ok');
    } catch (Throwable $e) {
        coa_dbg_row('PDO database', 'unknown (' . $e->getMessage() . ')', 'warn');
    }
    coa_dbg_row(
        'financial_accounts table',
        function_exists('balances_connection_has_financial_accounts') && balances_connection_has_financial_accounts($pdo) ? 'yes' : 'no',
        coa_dbg_status(function_exists('balances_connection_has_financial_accounts') && balances_connection_has_financial_accounts($pdo))
    );
} else {
    coa_dbg_row('PDO', 'missing after bootstrap', 'bad');
    $verdicts[] = 'FAIL: database bootstrap did not yield a PDO connection.';
}

coa_dbg_row('session_status', (string) session_status() . ' (1=none, 2=active)', 'ok');
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
coa_dbg_row('headers_sent (after session_start)', headers_sent() ? 'YES' : 'no', headers_sent() ? 'bad' : 'ok');

$loggedIn = function_exists('isLoggedIn') && isLoggedIn();
coa_dbg_row('isLoggedIn', $loggedIn ? 'yes' : 'no', coa_dbg_status($loggedIn));

if ($loggedIn) {
    $role = (string) ($_SESSION['role'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    coa_dbg_row('session user_id', (string) $userId, 'ok');
    coa_dbg_row('session role', $role !== '' ? $role : '(empty)', 'ok');
    coa_dbg_row('session company_id', (string) $companyId, 'ok');

    $isAdmin = function_exists('isAdmin') && isAdmin();
    $isFinance = function_exists('isFinance') && isFinance();
    coa_dbg_row('isAdmin()', $isAdmin ? 'yes' : 'no', 'ok');
    coa_dbg_row('isFinance()', $isFinance ? 'yes' : 'no', 'ok');
    coa_dbg_row(
        'coa_create access',
        ($isAdmin || $isFinance) ? 'allowed' : 'DENIED (would redirect to accounts.php)',
        coa_dbg_status($isAdmin || $isFinance)
    );

    if (!$isAdmin && !$isFinance) {
        $verdicts[] = 'WARN: logged in but not admin/finance  coa_create redirects away with access denied.';
    }
} else {
    coa_dbg_row('coa_create access', 'not logged in  requireLogin() should redirect to login', 'warn');
    $verdicts[] = 'INFO: not logged in. coa_create should redirect to login (unless BOM/pre-output blocks headers).';
}

// ------------------------------------------------------------------
// Simulate pre-output blocking redirect (BOM scenario)
// ------------------------------------------------------------------
$simOut = '';
if ($coaInfo['exists'] && $coaInfo['bom']) {
    $simOut = "\xEF\xBB\xBF";
}
$simHeadersBlocked = ($simOut !== '');
coa_dbg_row(
    'redirect simulation',
    $simHeadersBlocked
        ? 'If coa_create emitted BOM first, Location header would FAIL and page would be blank (200, 0 bytes).'
        : 'No pre-output detected  Location redirect should succeed for guests.',
    coa_dbg_status(!$simHeadersBlocked, $simHeadersBlocked)
);

// ------------------------------------------------------------------
// Compare with accounts.php (known-good reference)
// ------------------------------------------------------------------
$accountsInfo = coa_dbg_file_info(__DIR__ . '/accounts.php');
if ($accountsInfo['exists']) {
    coa_dbg_row(
        'accounts.php reference',
        'exists, BOM=' . ($accountsInfo['bom'] ? 'yes' : 'no') . '  use this as the working auth redirect baseline',
        coa_dbg_status(!$accountsInfo['bom'])
    );
}

// ------------------------------------------------------------------
// Final verdict
// ------------------------------------------------------------------
if ($verdicts === []) {
    if ($loggedIn && $pdoOk && $resolvedHeader !== '' && !$coaInfo['bom']) {
        $verdicts[] = 'OK on disk: coa_create prerequisites look healthy. If the page is still blank in browser: hard-refresh, confirm you are admin/finance, check server error_log for coa_create fatal entries.';
    } elseif (!$loggedIn && !$coaInfo['bom']) {
        $verdicts[] = 'OK on disk: guest requests should redirect to login. Log in and retry coa_create.php.';
    } else {
        $verdicts[] = 'Review warnings above.';
    }
}

// ------------------------------------------------------------------
// Output
// ------------------------------------------------------------------
if ($isText) {
    echo "COA_CREATE_DEBUG\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($rows as $row) {
        $flag = $row['status'] !== '' && $row['status'] !== 'ok' ? ' [' . strtoupper($row['status']) . ']' : '';
        echo $row['label'] . ': ' . $row['value'] . $flag . "\n";
    }
    echo str_repeat('=', 60) . "\n";
    echo "VERDICT\n";
    foreach ($verdicts as $v) {
        echo '- ' . $v . "\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>coa_create.php debug</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem; background: #f8fafc; color: #0f172a; }
        h1 { margin: 0 0 0.25rem; font-size: 1.35rem; }
        p.meta { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        th, td { text-align: left; vertical-align: top; padding: 0.55rem 0.75rem; border-bottom: 1px solid #eef2f7; font-size: 0.92rem; }
        th { width: 240px; white-space: nowrap; background: #f1f5f9; font-weight: 600; }
        td { font-family: ui-monospace, Consolas, monospace; word-break: break-word; }
        tr.ok td { background: #f0fdf4; }
        tr.warn td { background: #fffbeb; }
        tr.bad td { background: #fef2f2; }
        .verdict { margin-top: 1rem; padding: 1rem; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; }
        .verdict h2 { margin: 0 0 0.5rem; font-size: 1rem; }
        .verdict ul { margin: 0; padding-left: 1.2rem; }
        .verdict li { margin: 0.25rem 0; }
        a { color: #2563eb; }
        code { background: #e2e8f0; padding: 0.1rem 0.35rem; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>coa_create.php debug</h1>
    <p class="meta">
        Plain text: <a href="?format=text">?format=text</a>
        &middot; Delete <code>coa_create_debug.php</code> after troubleshooting.
    </p>

    <table>
        <?php foreach ($rows as $row): ?>
            <tr class="<?= coa_dbg_h($row['status']) ?>">
                <th><?= coa_dbg_h($row['label']) ?></th>
                <td><?= coa_dbg_h($row['value']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="verdict">
        <h2>Verdict</h2>
        <ul>
            <?php foreach ($verdicts as $v): ?>
                <li><?= coa_dbg_h($v) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
