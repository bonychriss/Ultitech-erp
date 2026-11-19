<?php
/**
 * Dashboard session / API probe for the "Session expired" Payment Vouchers bug.
 *
 * Open while logged in (same browser as the broken dashboard):
 *   https://ultitech.io/ultimate/debug_dashboard_session.php
 *   http://localhost/public_html/ultimate/debug_dashboard_session.php
 *
 * Optional: ?key=ultitech-dash-debug  (allows viewing when not logged in)
 */
declare(strict_types=1);

@ini_set('display_errors', '1');
@error_reporting(E_ALL);

if (empty($_GET['company_slug']) && empty($_GET['company'])) {
    $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/(?:public_html/)?([a-z0-9][a-z0-9_-]*)/#i', $uriPath, $m)) {
        $cand = strtolower($m[1]);
        if (!in_array($cand, ['admin', 'api', 'assets', 'employee', 'modules', 'public_html'], true)) {
            $_GET['company_slug'] = $cand;
        }
    }
}

require_once __DIR__ . '/includes/config.php';

$debugKeyOk = isset($_GET['key']) && hash_equals('ultitech-dash-debug', (string) $_GET['key']);
$loggedIn = function_exists('isLoggedIn') ? isLoggedIn() : !empty($_SESSION['user_id']);
if (!$loggedIn && !$debugKeyOk) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Login required (open this URL in the same browser where the dashboard shows Session expired),\n";
    echo "or append ?key=ultitech-dash-debug\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function dbg_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function dbg_bool($v): string
{
    return $v ? 'YES' : 'NO';
}

function dbg_probe_url(string $url, array $headers = []): array
{
    $cookieHeader = '';
    foreach ($_COOKIE as $name => $value) {
        $cookieHeader .= ($cookieHeader === '' ? '' : '; ') . $name . '=' . $value;
    }

    $hdrs = array_merge([
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ], $headers);
    if ($cookieHeader !== '') {
        $hdrs[] = 'Cookie: ' . $cookieHeader;
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http' => 0,
            'error' => 'curl extension missing',
            'body' => '',
            'headers' => '',
            'url' => $url,
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = (string) curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $respHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'ok' => $err === '' && $code > 0,
        'http' => $code,
        'error' => $err,
        'body' => $body,
        'headers' => $respHeaders,
        'url' => $url,
    ];
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$origin = $scheme . '://' . $host;

$slug = function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : (string) ($_GET['company_slug'] ?? '');
if ($slug === '') {
    $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? 'ultimate')));
}

require_once __DIR__ . '/admin/dashboard-ui/lib.php';
$assets = function_exists('adminDashboardUiLoadReactAssets') ? adminDashboardUiLoadReactAssets() : null;
$apiBase = is_array($assets) ? (string) ($assets['apiUrl'] ?? '') : '';
if ($apiBase === '' && function_exists('company_url')) {
    $apiBase = rtrim((string) company_url('admin/dashboard-ui/api', $slug), '/');
}

$apiPaths = array_values(array_unique(array_filter([
    $apiBase !== '' ? rtrim($apiBase, '/') . '/init.php' : '',
    function_exists('company_url') ? rtrim((string) company_url('admin/dashboard-ui/api/init.php', $slug), '/') : '',
    function_exists('app_url') ? rtrim((string) app_url('/' . $slug . '/admin/dashboard-ui/api/init.php'), '/') : '',
    function_exists('app_url') ? rtrim((string) app_url('/admin/dashboard-ui/api/init.php'), '/') : '',
    '/' . $slug . '/admin/dashboard-ui/api/init.php',
    '/admin/dashboard-ui/api/init.php',
])));

$absoluteApis = [];
foreach ($apiPaths as $path) {
    if (preg_match('#^https?://#i', $path)) {
        $absoluteApis[] = $path;
    } else {
        $absoluteApis[] = $origin . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}

$probes = [];
foreach ($absoluteApis as $url) {
    $probes[] = dbg_probe_url($url);
}

$sessionSafe = $_SESSION;
foreach (['password', 'csrf_token', 'remember_token'] as $hide) {
    if (isset($sessionSafe[$hide])) {
        $sessionSafe[$hide] = '[redacted]';
    }
}

$cookieNames = array_keys($_COOKIE);
sort($cookieNames);

$jsFile = is_array($assets) ? (string) ($assets['jsFile'] ?? '') : '';
$jsVersion = is_array($assets) ? (string) ($assets['jsVersion'] ?? '') : '';
$assetBase = is_array($assets) ? (string) ($assets['assetBase'] ?? '') : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Session Debug</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 24px; background: #0f172a; color: #e2e8f0; line-height: 1.45; }
        h1, h2 { color: #f8fafc; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 16px 18px; margin: 14px 0; }
        .ok { color: #4ade80; font-weight: 700; }
        .bad { color: #f87171; font-weight: 700; }
        .warn { color: #fbbf24; font-weight: 700; }
        code, pre { background: #020617; color: #cbd5e1; border-radius: 8px; }
        code { padding: 2px 6px; }
        pre { padding: 12px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #334155; vertical-align: top; }
        a { color: #93c5fd; }
        .btn { display: inline-block; margin: 4px 8px 4px 0; padding: 8px 12px; border-radius: 8px; background: #6366f1; color: #fff; text-decoration: none; border: 0; cursor: pointer; }
    </style>
</head>
<body>
<h1>Dashboard Session Debug</h1>
<p>Use this on the same browser tab session as the broken Payment Vouchers page.</p>

<div class="card">
    <h2>1. Request</h2>
    <table>
        <tr><th>Time</th><td><?= dbg_h(date('c')) ?></td></tr>
        <tr><th>Host</th><td><?= dbg_h($host) ?></td></tr>
        <tr><th>HTTPS</th><td><?= dbg_h(dbg_bool(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ?></td></tr>
        <tr><th>REQUEST_URI</th><td><code><?= dbg_h((string) ($_SERVER['REQUEST_URI'] ?? '')) ?></code></td></tr>
        <tr><th>SCRIPT_NAME</th><td><code><?= dbg_h((string) ($_SERVER['SCRIPT_NAME'] ?? '')) ?></code></td></tr>
        <tr><th>APP_BASE_PATH</th><td><code><?= dbg_h(defined('APP_BASE_PATH') ? (string) APP_BASE_PATH : '(undef)') ?></code></td></tr>
        <tr><th>Company slug</th><td><code><?= dbg_h($slug) ?></code></td></tr>
    </table>
</div>

<div class="card">
    <h2>2. PHP Session (this HTML request)</h2>
    <table>
        <tr><th>session_name</th><td><code><?= dbg_h(session_name()) ?></code></td></tr>
        <tr><th>session_id</th><td><code><?= dbg_h(session_id()) ?></code></td></tr>
        <tr><th>cookie params</th><td><pre><?= dbg_h(json_encode(session_get_cookie_params(), JSON_PRETTY_PRINT)) ?></pre></td></tr>
        <tr><th>isLoggedIn()</th><td class="<?= $loggedIn ? 'ok' : 'bad' ?>"><?= dbg_h(dbg_bool($loggedIn)) ?></td></tr>
        <tr><th>isAdmin()</th><td><?= dbg_h(function_exists('isAdmin') ? dbg_bool(isAdmin()) : 'n/a') ?></td></tr>
        <tr><th>user_id</th><td><?= dbg_h((string) ($_SESSION['user_id'] ?? '')) ?></td></tr>
        <tr><th>full_name</th><td><?= dbg_h((string) ($_SESSION['full_name'] ?? '')) ?></td></tr>
        <tr><th>role</th><td><?= dbg_h((string) ($_SESSION['role'] ?? '')) ?></td></tr>
        <tr><th>company_id</th><td><?= dbg_h((string) ($_SESSION['company_id'] ?? '')) ?></td></tr>
        <tr><th>company_slug</th><td><?= dbg_h((string) ($_SESSION['company_slug'] ?? '')) ?></td></tr>
        <tr><th>Cookie names present</th><td><code><?= dbg_h(implode(', ', $cookieNames)) ?></code></td></tr>
        <tr><th>PVSSESSID cookie?</th><td class="<?= isset($_COOKIE['PVSSESSID']) ? 'ok' : 'bad' ?>"><?= dbg_h(dbg_bool(isset($_COOKIE['PVSSESSID']))) ?></td></tr>
    </table>
    <pre><?= dbg_h(json_encode($sessionSafe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>

<div class="card">
    <h2>3. Dashboard asset / API resolution (same helpers as dashboard.php)</h2>
    <table>
        <tr><th>apiUrl</th><td><code><?= dbg_h($apiBase !== '' ? $apiBase : '(empty)') ?></code></td></tr>
        <tr><th>assetBase</th><td><code><?= dbg_h($assetBase !== '' ? $assetBase : '(empty)') ?></code></td></tr>
        <tr><th>jsFile</th><td><code><?= dbg_h($jsFile !== '' ? $jsFile . '?v=' . $jsVersion : '(missing build)') ?></code></td></tr>
    </table>
</div>

<div class="card">
    <h2>4. Server-side API probes (forwards your browser cookies)</h2>
    <?php foreach ($probes as $i => $p): ?>
        <?php
        $http = (int) ($p['http'] ?? 0);
        $cls = ($http >= 200 && $http < 300) ? 'ok' : 'bad';
        $snippet = substr(preg_replace('/\s+/', ' ', (string) ($p['body'] ?? '')), 0, 500);
        ?>
        <h3>#<?= (int) $i + 1 ?> <span class="<?= $cls ?>">HTTP <?= dbg_h((string) $http) ?></span></h3>
        <div><code><?= dbg_h((string) $p['url']) ?></code></div>
        <?php if (!empty($p['error'])): ?>
            <div class="bad">curl error: <?= dbg_h((string) $p['error']) ?></div>
        <?php endif; ?>
        <pre><?= dbg_h($snippet !== '' ? $snippet : '(empty body)') ?></pre>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>5. Browser-side fetch (what React actually does)</h2>
    <p>This runs in your browser with <code>credentials: 'include'</code> against the same API base the dashboard injects.</p>
    <button class="btn" type="button" id="btn-run">Run browser fetch now</button>
    <a class="btn" href="<?= dbg_h(function_exists('company_url') ? company_url('admin/dashboard.php', $slug) . '?module=voucher' : '/ultimate/admin/dashboard.php?module=voucher') ?>">Open Payment Vouchers</a>
    <pre id="browser-out">Click “Run browser fetch now”…</pre>
</div>

<script>
window.__DASHBOARD_API_BASE__ = <?= json_encode($apiBase, JSON_UNESCAPED_SLASHES) ?>;
window.__DBG_API_CANDIDATES__ = <?= json_encode($apiPaths, JSON_UNESCAPED_SLASHES) ?>;

async function probeOne(base) {
  const url = String(base).replace(/\/$/, '') + (String(base).includes('init.php') ? '' : '/init.php');
  const started = performance.now();
  try {
    const res = await fetch(url, {
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    return {
      url,
      http: res.status,
      ms: Math.round(performance.now() - started),
      contentType: res.headers.get('content-type'),
      body: text.slice(0, 400),
      message: json && (json.message || json.error) ? (json.message || json.error) : null,
      statsTotal: json && json.stats ? json.stats.total : null,
    };
  } catch (err) {
    return { url, http: 0, ms: Math.round(performance.now() - started), error: String(err && err.message ? err.message : err) };
  }
}

async function runBrowserProbes() {
  const out = document.getElementById('browser-out');
  out.textContent = 'Running…';
  const bases = [];
  if (window.__DASHBOARD_API_BASE__) bases.push(window.__DASHBOARD_API_BASE__);
  (window.__DBG_API_CANDIDATES__ || []).forEach((u) => bases.push(u));
  // Relative fallback like an old/broken bundle might use
  bases.push('./dashboard-ui/api');
  bases.push('../admin/dashboard-ui/api');

  const uniq = [...new Set(bases.filter(Boolean).map((b) => String(b)))];
  const results = [];
  for (const b of uniq) {
    results.push(await probeOne(b));
  }
  out.textContent = JSON.stringify({
    location: window.location.href,
    cookiePresentGuess: document.cookie.includes('PVSSESSID') || document.cookie.length > 0,
    documentCookieLength: document.cookie.length,
    note: 'HttpOnly session cookie will NOT appear in document.cookie (that is normal).',
    injectedApiBase: window.__DASHBOARD_API_BASE__ || null,
    results,
  }, null, 2);
}

document.getElementById('btn-run').addEventListener('click', runBrowserProbes);
// Auto-run once so a screenshot/copy already has the answer.
runBrowserProbes();
</script>

<div class="card">
    <h2>How to read this</h2>
    <ul>
        <li><span class="ok">Section 2 logged in YES</span> + <span class="bad">section 4/5 HTTP 401</span> = cookie/path/host mismatch between HTML and API.</li>
        <li>Section 5 <code>message: Session expired…</code> means the React desk is getting the same failure.</li>
        <li>Section 5 HTTP 200 with <code>statsTotal</code> &gt; 0 means API is fine from the browser; hard-refresh the dashboard.</li>
        <li>Copy the full page text / browser-out JSON back for fixing.</li>
    </ul>
</div>
</body>
</html>
