<?php
/**
 * Debug: why live Liquidity Dashboard (React) is not updating.
 * DELETE this file after troubleshooting.
 *
 * Upload to live /public_html (same folder as login.php), then open:
 *   https://ultitech.io/ultimate/modules/balances/debug_balances_react.php
 * Also: https://ultitech.io/debug_balances_react.php
 * Optional: ?probe=1
 */
declare(strict_types=1);

define('ULTITECH_DIAGNOSTIC_SCRIPT', true);

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function db_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function db_row(string $label, string $value, string $ok = ''): void
{
    $color = $ok === 'ok' ? '#166534' : ($ok === 'bad' ? '#991b1b' : '#111');
    $bg = $ok === 'ok' ? '#dcfce7' : ($ok === 'bad' ? '#fee2e2' : '#f8fafc');
    echo '<tr><th style="text-align:left;vertical-align:top;padding:6px 10px;white-space:nowrap;">'
        . db_h($label) . '</th><td style="padding:6px 10px;background:' . $bg
        . ';color:' . $color . ';font-family:ui-monospace,Consolas,monospace;word-break:break-all;">'
        . nl2br(db_h($value)) . '</td></tr>';
}

function db_check_file(string $abs): array
{
    if (!is_file($abs)) {
        return ['exists' => false, 'mtime' => null, 'size' => null, 'snip' => ''];
    }
    $snip = '';
    $fh = @fopen($abs, 'rb');
    if ($fh) {
        $snip = (string) fread($fh, 400);
        fclose($fh);
    }
    return [
        'exists' => true,
        'mtime' => date('c', (int) filemtime($abs)),
        'size' => (int) filesize($abs),
        'snip' => $snip,
    ];
}

function db_looks_react(string $snip): bool
{
    return str_contains($snip, 'Liquidity Dashboard')
        || str_contains($snip, 'liquidity-dashboard-ui')
        || str_contains($snip, '__LD_API_BASE__')
        || str_contains($snip, 'ld-lib.php');
}

function db_looks_legacy(string $snip): bool
{
    return str_contains($snip, 'index_legacy')
        || (str_contains($snip, 'balances') && !db_looks_react($snip) && str_contains($snip, 'DataTables'));
}

$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$here = realpath(__DIR__) ?: __DIR__;
// Works from /debug_balances_react.php OR /modules/balances/debug_balances_react.php
$siteRoot = $here;
if (basename($here) === 'balances' && basename(dirname($here)) === 'modules') {
    $siteRoot = dirname($here, 2);
}
$siteRoot = realpath($siteRoot) ?: $siteRoot;
$scriptDir = $siteRoot;
$roots = array_values(array_unique(array_filter([
    $siteRoot,
    $docRoot,
    $docRoot !== '' ? rtrim($docRoot, '/\\') . DIRECTORY_SEPARATOR . 'ultimate' : '',
    $siteRoot . DIRECTORY_SEPARATOR . 'ultimate',
])));

$relPaths = [
    'modules/balances/index.php',
    'modules/balances/index_legacy.php',
    'modules/balances/includes/header.php',
    'modules/balances/liquidity-dashboard-ui/ld-lib.php',
    'modules/balances/liquidity-dashboard-ui/api/index.php',
    'modules/balances/liquidity-dashboard-ui/dist/index.html',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Debug Balances React</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 16px; background: #fff; color: #111; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        h2 { font-size: 1.05rem; margin: 22px 0 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        table { border-collapse: collapse; width: 100%; max-width: 1100px; margin-bottom: 8px; }
        th, td { border: 1px solid #e2e8f0; }
        .verdict { padding: 12px 14px; border-radius: 8px; margin: 12px 0; font-weight: 600; }
        .ok { background: #dcfce7; color: #14532d; }
        .bad { background: #fee2e2; color: #7f1d1d; }
        .warn { background: #fef9c3; color: #713f12; }
        .muted { color: #64748b; font-size: 0.9rem; }
        pre { background: #0f172a; color: #e2e8f0; padding: 10px; overflow: auto; font-size: 12px; }
        code { background: #f1f5f9; padding: 1px 4px; }
    </style>
</head>
<body>
<h1>Debug: Balances React deploy</h1>
<p class="muted">Generated <?= db_h(date('c')) ?> ù Delete <code>debug_balances_react.php</code> when done.</p>

<h2>Request / paths</h2>
<table>
<?php
db_row('HTTP_HOST', (string) ($_SERVER['HTTP_HOST'] ?? ''));
db_row('REQUEST_URI', (string) ($_SERVER['REQUEST_URI'] ?? ''));
db_row('SCRIPT_NAME', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
db_row('SCRIPT_FILENAME', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
db_row('DOCUMENT_ROOT', $docRoot);
db_row('__DIR__ (this script)', $scriptDir);
db_row('PHP', PHP_VERSION);
db_row('GET company_slug', (string) ($_GET['company_slug'] ?? '(none)'));
?>
</table>

<?php
$targetUrl = '/ultimate/modules/balances/index';
$possibleFs = [];
foreach ([$docRoot, $scriptDir] as $base) {
    if ($base === '') {
        continue;
    }
    $possibleFs[] = rtrim($base, '/\\') . '/modules/balances/index.php';
    $possibleFs[] = rtrim($base, '/\\') . '/ultimate/modules/balances/index.php';
}
$possibleFs = array_values(array_unique($possibleFs));
?>

<h2>What the live URL should load</h2>
<p class="muted">
    Browser URL <code><?= db_h($targetUrl) ?></code> is company-slug rewrite:
    <code>/ultimate/...</code> ? root file <code>modules/balances/index.php?company_slug=ultimate</code>
    (unless a physical file/dir under <code>/ultimate/...</code> exists and blocks rewrite).
</p>

<h2>File checks (all candidate roots)</h2>
<?php
$reactHits = [];
$legacyHits = [];
$missingRoot = [];

foreach ($roots as $root) {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $label = $root;
    echo '<h3 style="font-size:0.95rem;margin:14px 0 6px;">Root: <code>' . db_h($label) . '</code></h3>';
    echo '<table>';

    $indexAbs = $root . '/modules/balances/index.php';
    $info = db_check_file($indexAbs);
    if ($info['exists']) {
        $isReact = db_looks_react($info['snip']);
        $isLegacy = !$isReact;
        $ok = $isReact ? 'ok' : 'bad';
        $kind = $isReact ? 'REACT shell' : 'NOT React (legacy / old?)';
        db_row('index.php', "EXISTS | {$kind}\nmtime={$info['mtime']} size={$info['size']}", $ok);
        db_row('index.php head', substr($info['snip'], 0, 280), $ok);
        if ($isReact) {
            $reactHits[] = $indexAbs;
        } else {
            $legacyHits[] = $indexAbs;
        }
    } else {
        db_row('index.php', 'MISSING', 'bad');
        if (str_ends_with($root, '/ultimate') || str_ends_with($root, '\\ultimate')) {
            // ignore
        } else {
            $missingRoot[] = $indexAbs;
        }
    }

    foreach ($relPaths as $rel) {
        if ($rel === 'modules/balances/index.php') {
            continue;
        }
        $abs = $root . '/' . $rel;
        $info = db_check_file($abs);
        if ($info['exists']) {
            db_row($rel, "EXISTS mtime={$info['mtime']} size={$info['size']}", 'ok');
        } else {
            db_row($rel, 'MISSING', 'bad');
        }
    }

    // Dist assets referenced by dist/index.html
    $distHtml = $root . '/modules/balances/liquidity-dashboard-ui/dist/index.html';
    if (is_file($distHtml)) {
        $html = (string) file_get_contents($distHtml);
        preg_match('/src="\.\/assets\/([^"]+\.js)"/', $html, $jm);
        preg_match('/href="\.\/assets\/([^"]+\.css)"/', $html, $cm);
        $js = $jm[1] ?? '';
        $css = $cm[1] ?? '';
        db_row('dist/index.html refs', "js={$js}\ncss={$css}", ($js && $css) ? 'ok' : 'bad');
        if ($js !== '') {
            $jsAbs = $root . '/modules/balances/liquidity-dashboard-ui/dist/assets/' . $js;
            db_row('dist asset JS', is_file($jsAbs) ? 'EXISTS ' . $jsAbs : 'MISSING ' . $jsAbs, is_file($jsAbs) ? 'ok' : 'bad');
        }
        if ($css !== '') {
            $cssAbs = $root . '/modules/balances/liquidity-dashboard-ui/dist/assets/' . $css;
            db_row('dist asset CSS', is_file($cssAbs) ? 'EXISTS ' . $cssAbs : 'MISSING ' . $cssAbs, is_file($cssAbs) ? 'ok' : 'bad');
        }
    }

    // Header inject hooks
    $headerAbs = $root . '/modules/balances/includes/header.php';
    if (is_file($headerAbs)) {
        $h = (string) file_get_contents($headerAbs);
        $hasLd = str_contains($h, 'ldHeadMarkup');
        db_row('header.php ldHeadMarkup', $hasLd ? 'present' : 'MISSING inject hook', $hasLd ? 'ok' : 'bad');
    }

    echo '</table>';
}
?>

<h2>Rewrite / collision check</h2>
<table>
<?php
foreach ($possibleFs as $p) {
    $pNorm = str_replace('\\', '/', $p);
    $exists = is_file($p);
    db_row($pNorm, $exists ? 'FILE EXISTS (may be served if request hits this path)' : 'not present', $exists ? 'warn' : 'ok');
}
$ultimateDir = rtrim(str_replace('\\', '/', $docRoot !== '' ? $docRoot : $scriptDir), '/') . '/ultimate';
$ultimateIsDir = is_dir($ultimateDir);
db_row('DOCUMENT_ROOT/ultimate is dir?', $ultimateIsDir ? "YES ù {$ultimateDir}" : 'no', $ultimateIsDir ? 'warn' : 'ok');
?>
</table>

<?php
// Verdict
$primaryIndex = rtrim(str_replace('\\', '/', $scriptDir), '/') . '/modules/balances/index.php';
if (!is_file($primaryIndex) && $docRoot !== '') {
    $primaryIndex = rtrim(str_replace('\\', '/', $docRoot), '/') . '/modules/balances/index.php';
}
$primaryInfo = db_check_file($primaryIndex);
$verdictClass = 'bad';
$verdict = '';

if (!$primaryInfo['exists']) {
    $verdict = 'FAIL: modules/balances/index.php missing under this script root. Unzip balances-react-deploy.zip into /public_html/ (site root), not /public_html/ultimate/.';
} elseif (!db_looks_react($primaryInfo['snip'])) {
    $verdict = 'FAIL: Site-root index.php is NOT the React shell. Upload did not overwrite the live modules/balances/index.php (wrong folder or old file kept).';
} else {
    $distOk = is_file(dirname($primaryIndex) . '/liquidity-dashboard-ui/dist/index.html');
    $ldOk = is_file(dirname($primaryIndex) . '/liquidity-dashboard-ui/ld-lib.php');
    $header = (string) @file_get_contents(dirname($primaryIndex) . '/includes/header.php');
    $headOk = str_contains($header, 'ldHeadMarkup');
    if (!$distOk || !$ldOk || !$headOk) {
        $verdict = 'PARTIAL: React index.php found, but missing pieces ù '
            . (!$ldOk ? 'ld-lib.php ' : '')
            . (!$distOk ? 'dist/index.html ' : '')
            . (!$headOk ? 'header ldHeadMarkup ' : '')
            . '. Re-upload full zip to /public_html/.';
    } else {
        $verdictClass = 'ok';
        $verdict = 'OK on disk: React shell + dist + ld-lib + header hook are present under site root. If the browser still shows old UI: hard-refresh, confirm you are logged into Ultimate, or clear CDN/cache. Optional: open with ?probe=1.';
    }
}
?>
<div class="verdict <?= db_h($verdictClass) ?>"><?= db_h($verdict) ?></div>

<?php if (!empty($reactHits)): ?>
<p class="muted">React index found at:<br><?php foreach ($reactHits as $h) { echo 'ù ' . db_h($h) . '<br>'; } ?></p>
<?php endif; ?>
<?php if (!empty($legacyHits)): ?>
<p class="muted" style="color:#991b1b;">Non-React index found at:<br><?php foreach ($legacyHits as $h) { echo 'ù ' . db_h($h) . '<br>'; } ?></p>
<?php endif; ?>

<h2>URL helper probe (ld-lib)</h2>
<?php
$ldLib = rtrim(str_replace('\\', '/', $scriptDir), '/') . '/modules/balances/liquidity-dashboard-ui/ld-lib.php';
if (!is_file($ldLib) && $docRoot !== '') {
    $ldLib = rtrim(str_replace('\\', '/', $docRoot), '/') . '/modules/balances/liquidity-dashboard-ui/ld-lib.php';
}
echo '<table>';
if (!is_file($ldLib)) {
    db_row('ld-lib.php', 'MISSING ù cannot probe URLs', 'bad');
} else {
    try {
        // Fake shell script name like the live balances page
        $_SERVER['SCRIPT_NAME'] = '/ultimate/modules/balances/index.php';
        require_once $ldLib;
        $asset = ldDeskPublicUrl('liquidity-dashboard-ui/dist/assets/');
        $api = ldDeskPublicUrl('liquidity-dashboard-ui/api/index.php');
        db_row('ldDeskPublicUrl assets', $asset, 'ok');
        db_row('ldDeskPublicUrl api', $api, 'ok');
    } catch (Throwable $e) {
        db_row('ld-lib error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'bad');
    }
}
echo '</table>';
?>

<?php if (!empty($_GET['probe'])): ?>
<h2>Include probe (modules/balances/index.php)</h2>
<?php
$probe = rtrim(str_replace('\\', '/', $scriptDir), '/') . '/modules/balances/index.php';
if (!is_file($probe) && $docRoot !== '') {
    $probe = rtrim(str_replace('\\', '/', $docRoot), '/') . '/modules/balances/index.php';
}
echo '<p class="muted">Including <code>' . db_h($probe) . '</code> (may redirect to login).</p>';
if (!is_file($probe)) {
    echo '<div class="verdict bad">Cannot probe ù index.php missing.</div>';
} else {
    ob_start();
    $err = null;
    try {
        include $probe;
    } catch (Throwable $e) {
        $err = $e;
    }
    $out = ob_get_clean();
    if ($err) {
        echo '<div class="verdict bad">' . db_h($err->getMessage()) . '</div>';
    }
    $hasLd = str_contains($out, '__LD_API_BASE__') || str_contains($out, 'page-ld-desk') || str_contains($out, 'id="root"');
    echo '<div class="verdict ' . ($hasLd ? 'ok' : 'warn') . '">'
        . ($hasLd ? 'Probe output looks like React desk markup.' : 'Probe output does not clearly show React desk markers (login redirect / old page / error).')
        . '</div>';
    echo '<pre>' . db_h(substr($out, 0, 4000)) . "</pre>";
}
?>
<?php else: ?>
<p class="muted">Add <code>?probe=1</code> to try including the balances index (login may redirect).</p>
<?php endif; ?>

<h2>Fix checklist</h2>
<ol>
    <li>Upload <code>balances-react-deploy.zip</code> to <strong>/public_html/</strong> (same folder as <code>login.php</code>), not <code>/public_html/ultimate/</code>.</li>
    <li>Unzip with overwrite.</li>
    <li>Confirm this page shows React index under the non-ultimate root.</li>
    <li>Hard-refresh <code>https://ultitech.io/ultimate/modules/balances/index</code>.</li>
</ol>
</body>
</html>
