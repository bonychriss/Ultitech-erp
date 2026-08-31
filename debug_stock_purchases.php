<?php
/**
 * Stock Purchases index diagnostic (blank page).
 * DELETE after troubleshooting.
 *
 * https://ultitech.io/debug_stock_purchases.php?key=ultitech-debug&company_slug=ultimate
 * Fix BOM: add &fix_bom=1
 */

@ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}

const DSP_KEY = 'ultitech-debug';

$dspKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$dspExpected = DSP_KEY;
foreach ([__DIR__ . '/env.php', __DIR__ . '/includes/env.php'] as $ep) {
    if (!is_file($ep)) {
        continue;
    }
    $DEBUG_KEY = $DEBUG_KEY ?? null;
    include $ep;
    if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
        $dspExpected = trim((string) $DEBUG_KEY);
        break;
    }
}
if (PHP_SAPI !== 'cli' && ($dspKey === '' || !hash_equals($dspExpected, $dspKey))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Use ?key=" . DSP_KEY . "\n";
    exit;
}

$slug = isset($_GET['company_slug']) ? strtolower(trim((string) $_GET['company_slug'])) : 'ultimate';
if ($slug === '') {
    $slug = 'ultimate';
}
$_GET['company_slug'] = $slug;

function dsp_has_bom(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $b = @file_get_contents($path, false, null, 0, 3);
    return $b === "\xEF\xBB\xBF";
}

function dsp_strip_bom(string $path): string
{
    if (!is_file($path) || !is_writable($path)) {
        return 'skip';
    }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) < 3 || substr($raw, 0, 3) !== "\xEF\xBB\xBF") {
        return 'clean';
    }
    file_put_contents($path, substr($raw, 3));
    return 'fixed';
}

$root = __DIR__;
$targets = [
    'stock/modules/purchases/index.php' => $root . '/stock/modules/purchases/index.php',
    'stock/modules/purchases/domestic_create.php' => $root . '/stock/modules/purchases/domestic_create.php',
    'stock/modules/purchases/domestic_receive.php' => $root . '/stock/modules/purchases/domestic_receive.php',
    'stock/includes/breadcrumbs.inc.php' => $root . '/stock/includes/breadcrumbs.inc.php',
];

if (isset($_GET['fix_bom']) && (string) $_GET['fix_bom'] === '1') {
    foreach ($targets as $abs) {
        dsp_strip_bom($abs);
    }
    $qs = http_build_query(['key' => $dspKey, 'company_slug' => $slug, 'bom_fixed' => '1']);
    header('Location: /debug_stock_purchases.php?' . $qs, true, 302);
    exit;
}

$targetUrl = '/' . rawurlencode($slug) . '/stock/modules/purchases/index.php';

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Stock Purchases Debug</title>';
echo '<style>body{font-family:Segoe UI,Arial,sans-serif;margin:20px}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:8px}.bad{color:#991b1b;font-weight:bold}.ok{color:#166534}</style></head><body>';
echo '<h1>Stock Purchases Debug</h1>';
echo '<p>Company: <strong>' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</strong></p>';
echo '<p>Target: <a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';

$anyBom = false;
echo '<table><tr><th>File</th><th>UTF-8 BOM</th></tr>';
foreach ($targets as $label => $abs) {
    $bom = dsp_has_bom($abs);
    if ($bom) {
        $anyBom = true;
    }
    echo '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td class="' . ($bom ? 'bad' : 'ok') . '">' . ($bom ? 'YES (blank page)' : 'no') . '</td></tr>';
}
echo '</table>';

if ($anyBom) {
    $fixUrl = '/debug_stock_purchases.php?' . http_build_query(['key' => $dspKey, 'company_slug' => $slug, 'fix_bom' => '1']);
    echo '<p class="bad">BOM causes blank pages. <a href="' . htmlspecialchars($fixUrl, ENT_QUOTES, 'UTF-8') . '">Remove BOM on server</a></p>';
} elseif (isset($_GET['bom_fixed'])) {
    echo '<p class="ok">BOM fix applied. Reload the purchases page.</p>';
}

echo '<p><small>Delete <code>debug_stock_purchases.php</code> when done.</small></p></body></html>';
