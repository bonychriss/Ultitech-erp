<?php
/**
 * Create Voucher page diagnostic (blank page / HTTP 500).
 * DELETE after troubleshooting.
 *
 * https://ultitech.io/debug_create_voucher.php?key=ultitech-debug
 * Optional company: &company_slug=ultimate
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$dcvReqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#^/home/sites/#i', $dcvReqPath)) {
    $k = isset($_GET['key']) ? (string) $_GET['key'] : 'ultitech-debug';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'ultitech.io');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Location: ' . $scheme . '://' . $host . '/debug_create_voucher.php?key=' . rawurlencode($k), true, 302);
    exit;
}

if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}

const DCV_KEY = 'ultitech-debug';
const DCV_VERSION = '1.2';

$dcvKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$dcvExpected = DCV_KEY;
foreach ([__DIR__ . '/env.php', __DIR__ . '/includes/env.php'] as $ep) {
    if (!is_file($ep)) {
        continue;
    }
    $DEBUG_KEY = $DEBUG_KEY ?? null;
    include $ep;
    if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
        $dcvExpected = trim((string) $DEBUG_KEY);
        break;
    }
}
if (PHP_SAPI !== 'cli' && ($dcvKey === '' || !hash_equals($dcvExpected, $dcvKey))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Use ?key=" . DCV_KEY . "\n";
    exit;
}

$dcvCompanySlug = isset($_GET['company_slug']) ? strtolower(trim((string) $_GET['company_slug'])) : 'ultimate';
if ($dcvCompanySlug === '') {
    $dcvCompanySlug = 'ultimate';
}
$_GET['company_slug'] = $dcvCompanySlug;
$_GET['module'] = isset($_GET['module']) ? (string) $_GET['module'] : 'voucher';

function dcv_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function dcv_badge($ok, $detail = '')
{
    $cls = $ok ? 'ok' : 'fail';
    $txt = $ok ? 'OK' : 'FAIL';
    $out = '<span class="badge ' . $cls . '">' . $txt . '</span>';
    if ($detail !== '') {
        $out .= ' <span class="detail">' . dcv_h($detail) . '</span>';
    }
    return $out;
}

function dcv_row($label, $value = null)
{
    if ($value === null) {
        echo '<tr><th colspan="2">' . dcv_h($label) . '</th></tr>';
        return;
    }
    echo '<tr><th>' . dcv_h($label) . '</th><td>' . dcv_h($value) . '</td></tr>';
}

function dcv_section($title)
{
    echo '<h2>' . dcv_h($title) . '</h2><table>';
}

function dcv_end()
{
    echo '</table>';
}

function dcv_probe_url($path, $query = array())
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

function dcv_http_get($url)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'code' => 0, 'snippet' => '', 'error' => 'curl not available');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIE => session_name() . '=' . session_id(),
        CURLOPT_USERAGENT => 'UltitechCreateVoucherDebug/1.0',
        CURLOPT_HEADER => true,
    ));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) {
        return array('ok' => false, 'code' => 0, 'snippet' => '', 'error' => $err ?: 'curl failed');
    }
    $body = $hs > 0 ? substr((string) $raw, $hs) : (string) $raw;
    $snippet = preg_replace('/\s+/', ' ', substr(strip_tags($body), 0, 300));
    $ok = $code >= 200 && $code < 400 && strlen(trim($body)) > 200;
    if ($code >= 400) {
        $ok = false;
    }
    if (strlen(trim($body)) < 50) {
        $ok = false;
    }
    return array(
        'ok' => $ok,
        'code' => $code,
        'len' => strlen($body),
        'snippet' => (string) $snippet,
        'error' => $ok ? '' : ('HTTP ' . $code . ', body ' . strlen($body) . ' bytes'),
    );
}

function dcv_file_has_bom($path)
{
    if (!is_file($path)) {
        return false;
    }
    $b = @file_get_contents($path, false, null, 0, 3);
    return $b === "\xEF\xBB\xBF";
}

function dcv_strip_bom_file($path)
{
    if (!is_file($path)) {
        return 'missing';
    }
    if (!is_writable($path)) {
        return 'not writable';
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return 'read failed';
    }
    if (strlen($bytes) >= 3 && substr($bytes, 0, 3) === "\xEF\xBB\xBF") {
        $bytes = substr($bytes, 3);
        if (@file_put_contents($path, $bytes) === false) {
            return 'write failed';
        }
        return 'BOM removed';
    }
    return 'already clean';
}

$root = __DIR__;
$createScript = $root . '/employee/create-voucher.php';
$editScript = $root . '/employee/edit-voucher.php';
$formInclude = $root . '/employee/includes/voucher-form-page.php';

// Bootstrap session before any HTML output (needed for HTTP probe cookie).
try {
    require_once $root . '/includes/config.php';
    if (is_file($root . '/includes/functions.php')) {
        require_once $root . '/includes/functions.php';
    }
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Bootstrap failed</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// One-click BOM repair on server (requires writable PHP files).
if (isset($_GET['fix_bom']) && (string) $_GET['fix_bom'] === '1') {
    foreach (array($createScript, $editScript, $formInclude) as $abs) {
        dcv_strip_bom_file($abs);
    }
    $qs = array('key' => $dcvKey, 'company_slug' => $dcvCompanySlug, 'bom_fixed' => '1');
    if (isset($_GET['module'])) {
        $qs['module'] = (string) $_GET['module'];
    }
    $redir = dcv_probe_url('/debug_create_voucher.php', $qs);
    if (!headers_sent()) {
        header('Location: ' . $redir, true, 302);
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Create Voucher Debug</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:20px;background:#f8fafc;color:#0f172a}';
echo 'table{border-collapse:collapse;width:100%;max-width:1100px;background:#fff;margin:8px 0 24px;box-shadow:0 1px 2px rgba(0,0,0,.06)}';
echo 'th,td{border:1px solid #e2e8f0;padding:8px 10px;text-align:left;font-size:13px;vertical-align:top}th{width:240px;background:#f1f5f9}';
echo 'h1{margin:0 0 8px}h2{margin:24px 0 8px;font-size:1.05rem;color:#1e40af}';
echo '.badge{padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}.ok{background:#dcfce7;color:#166534}.fail{background:#fee2e2;color:#991b1b}.warn{background:#fef3c7;color:#92400e}';
echo '.detail{color:#64748b;font-size:12px}.box{background:#fffbeb;border:1px solid #fcd34d;padding:12px;border-radius:8px;margin-bottom:16px;max-width:1100px}';
echo 'pre{background:#f1f5f9;padding:10px;overflow:auto;font-size:12px}a{color:#2563eb}</style></head><body>';
echo '<h1>Create Voucher Debug</h1>';
echo '<p class="detail">Version ' . dcv_h(DCV_VERSION) . ' &mdash; Delete <code>debug_create_voucher.php</code> when done.</p>';

$targetUrl = dcv_probe_url('/' . $dcvCompanySlug . '/employee/create-voucher.php', array(
    'module' => 'voucher',
    'company_slug' => $dcvCompanySlug,
));
echo '<div class="box"><strong>Target page:</strong> <a href="' . dcv_h($targetUrl) . '">' . dcv_h($targetUrl) . '</a><br>';
echo 'With debug on live page: add <code>&amp;debug=1</code> to create-voucher.php';
if (isset($_GET['bom_fixed']) && $_GET['bom_fixed'] === '1') {
    echo '<br><strong style="color:#166534">BOM fix applied.</strong> Reload section 6 below.';
}
$bomFiles = array();
foreach (array('create-voucher.php' => $createScript, 'edit-voucher.php' => $editScript, 'voucher-form-page.php' => $formInclude) as $lbl => $abs) {
    if (dcv_file_has_bom($abs)) {
        $bomFiles[] = $lbl;
    }
}
if (!empty($bomFiles)) {
    $fixUrl = dcv_probe_url('/debug_create_voucher.php', array(
        'key' => $dcvKey,
        'company_slug' => $dcvCompanySlug,
        'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'voucher',
        'fix_bom' => '1',
    ));
    echo '<br><strong style="color:#991b1b">UTF-8 BOM on: ' . dcv_h(implode(', ', $bomFiles)) . ' (causes blank create-voucher page).</strong> ';
    echo '<a href="' . dcv_h($fixUrl) . '">Click here to remove BOM on server</a> or upload UTF-8 <em>without BOM</em> copies.';
}
echo '</div>';

// --- 1. Environment ---
dcv_section('1. Server');
dcv_row('PHP', PHP_VERSION);
dcv_row('Time', date('c'));
dcv_row('Company slug (test)', $dcvCompanySlug);
dcv_row('create-voucher.php', is_file($createScript) ? 'exists' : 'MISSING');
dcv_row('voucher-form-page.php', is_file($formInclude) ? 'exists' : 'MISSING');
foreach (array(
    'create-voucher.php' => $createScript,
    'edit-voucher.php' => $editScript,
    'voucher-form-page.php' => $formInclude,
) as $label => $abs) {
    $bom = 'no';
    if (is_file($abs)) {
        $b = @file_get_contents($abs, false, null, 0, 3);
        if ($b === "\xEF\xBB\xBF") {
            $bom = 'YES (breaks redirects - re-save UTF-8 without BOM)';
        }
    }
    dcv_row('UTF-8 BOM ' . $label, $bom);
}
dcv_end();

// --- 2. Bootstrap ---
$configOk = isset($pdo) || isset($control_pdo);
$functionsOk = function_exists('isLoggedIn');
$pdoOk = (isset($pdo) && $pdo instanceof PDO) || (isset($control_pdo) && $control_pdo instanceof PDO);
$configErr = '';
$functionsErr = '';

dcv_section('2. Bootstrap');
echo '<tr><th>config.php</th><td>' . dcv_badge($configOk, $configOk ? '' : $configErr) . '</td></tr>';
echo '<tr><th>functions.php</th><td>' . dcv_badge($functionsOk, $functionsOk ? '' : $functionsErr) . '</td></tr>';
if ($functionsOk) {
    dcv_row('APP_BASE_PATH', defined('APP_BASE_PATH') ? APP_BASE_PATH : '(undefined)');
    dcv_row('Logged in', function_exists('isLoggedIn') && isLoggedIn() ? 'yes (user_id=' . (int) ($_SESSION['user_id'] ?? 0) . ')' : 'NO - open target URL in same browser after login');
    dcv_row('Session company_id', (string) (int) ($_SESSION['company_id'] ?? 0));
    dcv_row('Session company_slug', (string) ($_SESSION['company_slug'] ?? ''));
}
dcv_end();

$usePdo = null;
$erpPdo = null;
$voucherPdo = null;
if ($pdoOk) {
    $usePdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : $control_pdo;
    if ($functionsOk && function_exists('erp_data_pdo')) {
        $erpPdo = erp_data_pdo();
    }
    if ($functionsOk && function_exists('voucher_operational_pdo')) {
        $voucherPdo = voucher_operational_pdo();
    }
}

// --- 3. Tenant tables ---
dcv_section('3. Database tables (active PDO)');
if (!($usePdo instanceof PDO)) {
    echo '<tr><th colspan="2">' . dcv_badge(false, 'No PDO') . '</td></tr>';
} else {
    try {
        dcv_row('Active DB ($pdo)', (string) $usePdo->query('SELECT DATABASE()')->fetchColumn());
    } catch (Throwable $e) {
        dcv_row('Active DB ($pdo)', 'error: ' . $e->getMessage());
    }
    if ($erpPdo instanceof PDO && $erpPdo !== $usePdo) {
        try {
            dcv_row('erp_data_pdo()', (string) $erpPdo->query('SELECT DATABASE()')->fetchColumn());
        } catch (Throwable $e) {
            dcv_row('erp_data_pdo()', $e->getMessage());
        }
    }
    if ($voucherPdo instanceof PDO) {
        try {
            $vdb = (string) $voucherPdo->query('SELECT DATABASE()')->fetchColumn();
            $same = ($voucherPdo === $usePdo) ? 'same as $pdo' : 'different from $pdo';
            dcv_row('voucher_operational_pdo()', $vdb . ' (' . $same . ')');
        } catch (Throwable $e) {
            dcv_row('voucher_operational_pdo()', $e->getMessage());
        }
    }
    if ($functionsOk && function_exists('erp_connection_has_table')) {
        dcv_row('products on $pdo', erp_connection_has_table($usePdo, 'products') ? 'yes' : 'no');
        dcv_row('payees on $pdo', erp_connection_has_table($usePdo, 'payees') ? 'yes' : 'no');
    }
    foreach (array('users', 'payees', 'payment_vouchers', 'sales_orders', 'customers', 'voucher_items') as $tbl) {
        try {
            $st = $usePdo->prepare('SHOW TABLES LIKE ?');
            $st->execute(array($tbl));
            $exists = (bool) $st->fetchColumn();
            $extra = '';
            if ($exists) {
                $cnt = (int) $usePdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tbl) . '`')->fetchColumn();
                $extra = 'rows=' . $cnt;
            }
            echo '<tr><th>Table ' . dcv_h($tbl) . '</th><td>' . dcv_badge($exists, $extra) . '</td></tr>';
        } catch (Throwable $e) {
            echo '<tr><th>Table ' . dcv_h($tbl) . '</th><td>' . dcv_badge(false, $e->getMessage()) . '</td></tr>';
        }
    }
    if ($functionsOk && function_exists('columnExists')) {
        dcv_row('users.department', columnExists('users', 'department', $usePdo) ? 'yes' : 'MISSING (finance filter may be empty)');
    }
}
dcv_end();

// --- 4. Simulate create-voucher data load ---
dcv_section('4. Simulate create-voucher.php data queries');
$simOk = true;
$simNotes = array();
$simPdo = ($voucherPdo instanceof PDO) ? $voucherPdo : $usePdo;
if ($functionsOk && function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
    if (isset($pdo) && $pdo instanceof PDO) {
        $simPdo = $pdo;
    }
}
if (!($simPdo instanceof PDO)) {
    $simOk = false;
    $simNotes[] = 'No PDO';
} else {
    try {
        dcv_row('Simulate using DB', (string) $simPdo->query('SELECT DATABASE()')->fetchColumn());
    } catch (Throwable $e) {
        dcv_row('Simulate using DB', $e->getMessage());
    }
    try {
        $usersStmt = $simPdo->query('SELECT full_name, department, role FROM users WHERE is_active = 1 ORDER BY full_name LIMIT 5');
        $sample = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
        dcv_row('users query', 'OK sample=' . count($sample));
    } catch (Throwable $e) {
        $simOk = false;
        echo '<tr><th>users query</th><td>' . dcv_badge(false, $e->getMessage()) . '</td></tr>';
    }
    try {
        $payees = $simPdo->query('SELECT COUNT(*) FROM payees WHERE is_active = 1')->fetchColumn();
        dcv_row('payees', 'OK count=' . (int) $payees);
    } catch (Throwable $e) {
        $simOk = false;
        echo '<tr><th>payees</th><td>' . dcv_badge(false, $e->getMessage()) . '</td></tr>';
    }
    try {
        $so = $simPdo->query('SELECT COUNT(*) FROM sales_orders')->fetchColumn();
        dcv_row('sales_orders', 'OK count=' . (int) $so);
    } catch (Throwable $e) {
        echo '<tr><th>sales_orders</th><td>' . dcv_badge(false, $e->getMessage() . ' (optional)') . '</td></tr>';
    }
    if ($functionsOk && function_exists('normalizePaymentVoucherPurpose')) {
        dcv_row('normalizePaymentVoucherPurpose', dcv_badge(true, normalizePaymentVoucherPurpose('general')));
    } else {
        $simOk = false;
        dcv_row('normalizePaymentVoucherPurpose', dcv_badge(false, 'function missing'));
    }
}
dcv_end();

// --- 5. Include probe (dry run) ---
dcv_section('5. Render probe (voucher-form-page include)');
$renderOk = false;
$renderErr = '';
$renderLen = 0;
if (!is_file($formInclude)) {
    echo '<tr><th colspan="2">' . dcv_badge(false, 'voucher-form-page.php not found') . '</td></tr>';
} elseif (!$functionsOk) {
    echo '<tr><th colspan="2">' . dcv_badge(false, 'Bootstrap failed') . '</td></tr>';
} elseif (!function_exists('isLoggedIn') || !isLoggedIn()) {
    echo '<tr><th colspan="2">' . dcv_badge(false, 'Not logged in - log in first, then reload this debug page in the same browser') . '</td></tr>';
} else {
    $vfMode = 'create';
    $error = '';
    $success = '';
    $voucherCreateSuccess = null;
    $voucherModuleQs = '?module=voucher';
    $payees = array();
    $salesOrders = array();
    $allUsers = array();
    $financeUsers = array();
    $GLOBALS['_ultitech_skip_nc_feed_in_header'] = true;
    ob_start();
    try {
        include $formInclude;
        $renderBuf = ob_get_clean();
        $renderLen = strlen((string) $renderBuf);
        $renderOk = $renderLen > 500;
        if (!$renderOk) {
            $renderErr = 'Output only ' . $renderLen . ' bytes (expected HTML form)';
            if ($renderLen > 0 && $renderLen < 500) {
                $renderErr .= ' - ' . substr(strip_tags($renderBuf), 0, 200);
            }
        }
    } catch (Throwable $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        $renderErr = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    } catch (Exception $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        $renderErr = $e->getMessage();
    }
    unset($GLOBALS['_ultitech_skip_nc_feed_in_header']);
    echo '<tr><th>Include voucher-form-page</th><td>' . dcv_badge($renderOk, $renderOk ? ('HTML length ' . $renderLen) : $renderErr) . '</td></tr>';
    if ($renderOk && isset($_GET['preview']) && $_GET['preview'] === '1') {
        echo '</table><div class="box"><strong>HTML preview (first 4000 chars)</strong><pre>' . dcv_h(substr($renderBuf, 0, 4000)) . '</pre></div><table>';
    }
}
dcv_end();

// --- 6. HTTP probe (same session cookie) ---
dcv_section('6. HTTP probe (uses your session cookie)');
if (session_status() !== PHP_SESSION_ACTIVE) {
    dcv_row('Session', 'not active - probe may show login page');
} else {
    dcv_row('Session id', session_id());
}
$probe = dcv_http_get($targetUrl);
$probeDebug = dcv_http_get($targetUrl . (strpos($targetUrl, '?') === false ? '?' : '&') . 'debug=1');
$probeNote = $probe['error'];
if ((int) $probe['code'] === 200 && (int) $probe['len'] <= 5 && dcv_file_has_bom($createScript)) {
    $probeNote .= ' (BOM only - use fix_bom link above)';
}
echo '<tr><th>GET create-voucher</th><td>' . dcv_badge($probe['ok'], 'HTTP ' . $probe['code'] . ', ' . $probe['len'] . ' bytes. ' . $probeNote) . '</td></tr>';
echo '<tr><th>GET create-voucher&amp;debug=1</th><td>' . dcv_badge($probeDebug['ok'], 'HTTP ' . $probeDebug['code'] . ', ' . $probeDebug['len'] . ' bytes. ' . $probeDebug['error']) . '</td></tr>';
if ($probeDebug['snippet'] !== '' && !$probeDebug['ok']) {
    dcv_row('debug=1 body preview', $probeDebug['snippet']);
}
if ($probe['snippet'] !== '') {
    dcv_row('Body preview', $probe['snippet']);
}
dcv_end();

// --- 7. Files & assets ---
dcv_section('7. Assets');
foreach (array(
    '/assets/css/voucher-form.css',
    '/assets/js/voucher-v5.v10.js',
    '/assets/css/style.css',
    '/includes/header_employee.php',
) as $rel) {
    $abs = $root . $rel;
    echo '<tr><th>' . dcv_h($rel) . '</th><td>' . dcv_badge(is_file($abs), is_file($abs) ? 'size=' . filesize($abs) : 'missing') . '</td></tr>';
}
dcv_end();

echo '<div class="box"><strong>Next steps</strong><ol>';
echo '<li>Log in at <a href="' . dcv_h(dcv_probe_url('/login.php')) . '">login.php</a>, then reload this page.</li>';
echo '<li>If UTF-8 BOM is YES, use the <strong>remove BOM on server</strong> link above or upload <code>employee/create-voucher.php</code> as UTF-8 without BOM.</li>';
echo '<li>If section 5 FAILs, the error message is the fix target.</li>';
echo '<li>If section 5 OK but live page blank, check browser console (F12) for JS/CSS errors.</li>';
echo '<li>Add <code>&amp;preview=1</code> to this URL to dump HTML preview when section 5 passes.</li>';
echo '</ol></div>';
echo '<p class="detail">Also see <a href="' . dcv_h(dcv_probe_url('/debug_system_full.php', array('key' => $dcvKey))) . '">debug_system_full.php</a></p>';
echo '</body></html>';
