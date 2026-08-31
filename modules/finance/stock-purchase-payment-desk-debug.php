<?php
/**
 * Stock Purchase Payment Desk - deployment / parity debug tool.
 *
 * Usage:
 *   HTML report:  .../stock-purchase-payment-desk-debug.php
 *   JSON export:  .../stock-purchase-payment-desk-debug.php?format=json
 *   Compare prod: .../stock-purchase-payment-desk-debug.php?remote=https://ultitech.io/ultimate
 */
declare(strict_types=1);

require_once __DIR__ . '/stock-purchase-payment-desk-ui/sppd-lib.php';

sppdRequireAccess();

const SPPD_DEBUG_VERSION = '1.0.0';

/** @return array<string, string> */
function sppd_debug_feature_markers(): array
{
    return [
        'mobile_header_flex' => 'employee-header--sppd-desk .header-left',
        'mobile_header_static' => 'position: static !important',
        'mobile_kpi_2col' => '@media (max-width: 767.98px)',
        'kpi_trace_dark' => 'KPI trace modal',
        'po_quick_dark' => 'PO quick-view modal',
        'pay_modal_dark' => 'Pay purchase modal',
        'kpi_visuals_js' => 'unpaidPurchaseOrders',
        'listed_now_kpi' => 'listedNow',
        'summary_traces_api' => 'summaryTraces',
    ];
}

function sppd_debug_finance_web_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($script, '/stock-purchase-payment-desk-debug.php')) {
        return $dir;
    }
    if (function_exists('app_url')) {
        return app_url('modules/finance');
    }

    return '/modules/finance';
}

function sppd_debug_public_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . sppd_debug_finance_web_base() . '/' . $relativePath;
}

/** @return array{exists:bool,size:?int,mtime:?int,mtime_iso:?string,sha256:?string,readable:bool} */
function sppd_debug_file_fingerprint(string $path): array
{
    if (!is_file($path)) {
        return [
            'exists' => false,
            'size' => null,
            'mtime' => null,
            'mtime_iso' => null,
            'sha256' => null,
            'readable' => false,
        ];
    }

    $mtime = filemtime($path);
    $hash = @hash_file('sha256', $path);

    return [
        'exists' => true,
        'size' => filesize($path) ?: 0,
        'mtime' => $mtime !== false ? $mtime : null,
        'mtime_iso' => $mtime !== false ? date('c', $mtime) : null,
        'sha256' => is_string($hash) ? $hash : null,
        'readable' => is_readable($path),
    ];
}

/** @return array{js:?string,css:?string,raw:?string,parse_error:?string} */
function sppd_debug_parse_dist_index(string $distIndexPath): array
{
    if (!is_file($distIndexPath)) {
        return ['js' => null, 'css' => null, 'raw' => null, 'parse_error' => 'dist/index.html missing'];
    }

    $raw = file_get_contents($distIndexPath);
    if (!is_string($raw)) {
        return ['js' => null, 'css' => null, 'raw' => null, 'parse_error' => 'Could not read dist/index.html'];
    }

    $js = null;
    $css = null;
    if (preg_match('/src="\.\/assets\/([^"]+\.js)"/', $raw, $m)) {
        $js = $m[1];
    }
    if (preg_match('/href="\.\/assets\/([^"]+\.css)"/', $raw, $m)) {
        $css = $m[1];
    }

    $parseError = null;
    if ($js === null || $css === null) {
        $parseError = 'dist/index.html does not reference built JS/CSS assets';
    }

    return ['js' => $js, 'css' => $css, 'raw' => $raw, 'parse_error' => $parseError];
}

/** @param array<string, string> $markers @return array<string, bool> */
function sppd_debug_scan_markers(string $content, array $markers): array
{
    $found = [];
    foreach ($markers as $key => $needle) {
        $found[$key] = $needle !== '' && str_contains($content, $needle);
    }

    return $found;
}

/** @return list<array{name:string,fingerprint:array<string,mixed>}> */
function sppd_debug_list_dist_assets(string $assetsDir): array
{
    if (!is_dir($assetsDir)) {
        return [];
    }

    $files = [];
    foreach (scandir($assetsDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $assetsDir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            continue;
        }
        $files[] = [
            'name' => $name,
            'fingerprint' => sppd_debug_file_fingerprint($path),
        ];
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $files;
}

/** @return array{ok:bool,status:?int,content_type:?string,size:?int,error:?string,body_snippet:?string} */
function sppd_debug_http_probe(string $url, int $timeoutSeconds = 12): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => 'User-Agent: SPPD-Deploy-Debug/' . SPPD_DEBUG_VERSION . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = null;
    $contentType = null;

    if ($headers !== []) {
        if (preg_match('/\d{3}/', (string) $headers[0], $m)) {
            $status = (int) $m[0];
        }
        foreach ($headers as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            }
        }
    }

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'content_type' => $contentType,
            'size' => null,
            'error' => 'HTTP request failed (timeout, SSL, or blocked)',
            'body_snippet' => null,
        ];
    }

    return [
        'ok' => $status !== null && $status >= 200 && $status < 300,
        'status' => $status,
        'content_type' => $contentType,
        'size' => strlen($body),
        'error' => ($status !== null && $status >= 400) ? 'HTTP ' . $status : null,
        'body_snippet' => substr($body, 0, 400),
    ];
}

/** @return array<string, mixed> */
function sppd_debug_collect_local_state(): array
{
    $financeDir = __DIR__;
    $uiDir = $financeDir . '/stock-purchase-payment-desk-ui';
    $distDir = $uiDir . '/dist';
    $assetsDir = $distDir . '/assets';
    $shellPath = $financeDir . '/stock-purchase-payment-desk.php';
    $distIndexPath = $distDir . '/index.html';

    $distParsed = sppd_debug_parse_dist_index($distIndexPath);
    $cssPath = $distParsed['css'] ? $assetsDir . '/' . $distParsed['css'] : null;
    $jsPath = $distParsed['js'] ? $assetsDir . '/' . $distParsed['js'] : null;

    $shellContent = is_file($shellPath) ? (string) file_get_contents($shellPath) : '';
    $cssContent = ($cssPath && is_file($cssPath)) ? (string) file_get_contents($cssPath) : '';
    $jsContent = ($jsPath && is_file($jsPath)) ? (string) file_get_contents($jsPath) : '';

    $markers = sppd_debug_feature_markers();
    $shellMarkers = sppd_debug_scan_markers($shellContent, array_slice($markers, 0, 2, true));
    $cssMarkers = sppd_debug_scan_markers($cssContent, array_slice($markers, 2, 5, true));
    $jsMarkers = sppd_debug_scan_markers($jsContent, array_slice($markers, 7, 2, true));

    $issues = [];

    if ($distParsed['parse_error']) {
        $issues[] = ['severity' => 'critical', 'code' => 'dist_missing', 'message' => $distParsed['parse_error']];
    }
    if ($cssPath && !is_file($cssPath)) {
        $issues[] = ['severity' => 'critical', 'code' => 'css_missing', 'message' => 'Referenced CSS not on disk: ' . $distParsed['css']];
    }
    if ($jsPath && !is_file($jsPath)) {
        $issues[] = ['severity' => 'critical', 'code' => 'js_missing', 'message' => 'Referenced JS not on disk: ' . $distParsed['js']];
    }

    $allAssetFiles = sppd_debug_list_dist_assets($assetsDir);
    $referenced = array_filter([$distParsed['css'], $distParsed['js']]);
    $staleAssets = [];
    foreach ($allAssetFiles as $asset) {
        if (!in_array($asset['name'], $referenced, true)) {
            $staleAssets[] = $asset['name'];
        }
    }
    if ($staleAssets !== []) {
        $issues[] = [
            'severity' => 'warning',
            'code' => 'stale_dist_assets',
            'message' => 'Old hashed assets still in dist/assets: ' . implode(', ', $staleAssets),
        ];
    }

    foreach (array_merge($shellMarkers, $cssMarkers, $jsMarkers) as $key => $present) {
        if (!$present) {
            $issues[] = [
                'severity' => 'high',
                'code' => 'feature_marker_missing_' . $key,
                'message' => 'Expected feature marker not found on this server: ' . $key,
            ];
        }
    }

    $apiShape = null;
    try {
        $pdo = sppdBootstrap();
        $traces = sppdBuildSummaryTraces($pdo);
        $apiShape = [
            'ok' => true,
            'unpaid_count' => sppdCountUnpaidPurchaseOrders($pdo),
            'has_summary_traces' => is_array($traces) && $traces !== [],
            'trace_keys' => is_array($traces) ? array_keys($traces) : [],
        ];
        if (!$apiShape['has_summary_traces']) {
            $issues[] = [
                'severity' => 'medium',
                'code' => 'api_no_summary_traces',
                'message' => 'Backend sppdBuildSummaryTraces() returned empty.',
            ];
        }
    } catch (Throwable $e) {
        $apiShape = ['ok' => false, 'error' => $e->getMessage()];
        $issues[] = ['severity' => 'high', 'code' => 'api_bootstrap_failed', 'message' => $e->getMessage()];
    }

    return [
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? 'unknown'),
        'generated_at' => date('c'),
        'debug_version' => SPPD_DEBUG_VERSION,
        'paths' => [
            'finance_dir' => $financeDir,
            'ui_dir' => $uiDir,
            'dist_dir' => $distDir,
            'shell_php' => $shellPath,
            'desk_url' => sppd_debug_public_url('stock-purchase-payment-desk.php?module=balances'),
            'api_url' => sppd_debug_public_url('stock-purchase-payment-desk-ui/api/index.php'),
        ],
        'environment' => [
            'php_version' => PHP_VERSION,
            'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        ],
        'files' => [
            'shell_php' => sppd_debug_file_fingerprint($shellPath),
            'dist_index_html' => sppd_debug_file_fingerprint($distIndexPath),
            'active_css' => $cssPath ? sppd_debug_file_fingerprint($cssPath) : null,
            'active_js' => $jsPath ? sppd_debug_file_fingerprint($jsPath) : null,
        ],
        'build' => [
            'referenced_js' => $distParsed['js'],
            'referenced_css' => $distParsed['css'],
            'asset_urls' => [
                'css' => $distParsed['css'] ? sppd_debug_public_url('stock-purchase-payment-desk-ui/dist/assets/' . $distParsed['css']) : null,
                'js' => $distParsed['js'] ? sppd_debug_public_url('stock-purchase-payment-desk-ui/dist/assets/' . $distParsed['js']) : null,
                'dist_index' => sppd_debug_public_url('stock-purchase-payment-desk-ui/dist/index.html'),
            ],
            'all_assets' => $allAssetFiles,
            'stale_assets' => $staleAssets,
        ],
        'feature_markers' => [
            'shell_php' => $shellMarkers,
            'built_css' => $cssMarkers,
            'built_js' => $jsMarkers,
        ],
        'api' => $apiShape,
        'issues' => $issues,
    ];
}

/** @param array<string, mixed> $local @return array<string, mixed> */
function sppd_debug_probe_remote(string $remoteBase, array $local): array
{
    $remoteBase = rtrim($remoteBase, '/');
    $financeBase = $remoteBase . '/modules/finance';

    $urls = [
        'dist_index' => $financeBase . '/stock-purchase-payment-desk-ui/dist/index.html',
        'shell_php' => $financeBase . '/stock-purchase-payment-desk.php?module=balances',
        'debug_json' => $financeBase . '/stock-purchase-payment-desk-debug.php?format=json',
    ];

    if (!empty($local['build']['referenced_css'])) {
        $urls['local_css_on_remote'] = $financeBase . '/stock-purchase-payment-desk-ui/dist/assets/' . $local['build']['referenced_css'];
    }
    if (!empty($local['build']['referenced_js'])) {
        $urls['local_js_on_remote'] = $financeBase . '/stock-purchase-payment-desk-ui/dist/assets/' . $local['build']['referenced_js'];
    }

    $probes = [];
    foreach ($urls as $key => $url) {
        $probes[$key] = array_merge(['url' => $url], sppd_debug_http_probe($url));
    }

    $remoteDist = sppd_debug_http_probe($urls['dist_index']);
    $remoteCssName = null;
    $remoteJsName = null;
    if ($remoteDist['ok'] && is_string($remoteDist['body_snippet'])) {
        if (preg_match('/src="\.\/assets\/([^"]+\.js)"/', $remoteDist['body_snippet'], $m)) {
            $remoteJsName = $m[1];
        }
        if (preg_match('/href="\.\/assets\/([^"]+\.css)"/', $remoteDist['body_snippet'], $m)) {
            $remoteCssName = $m[1];
        }
    }

    $remoteCssProbe = null;
    $remoteJsProbe = null;
    if ($remoteCssName) {
        $remoteCssUrl = $financeBase . '/stock-purchase-payment-desk-ui/dist/assets/' . $remoteCssName;
        $remoteCssProbe = array_merge(['url' => $remoteCssUrl, 'filename' => $remoteCssName], sppd_debug_http_probe($remoteCssUrl));
    }
    if ($remoteJsName) {
        $remoteJsUrl = $financeBase . '/stock-purchase-payment-desk-ui/dist/assets/' . $remoteJsName;
        $remoteJsProbe = array_merge(['url' => $remoteJsUrl, 'filename' => $remoteJsName], sppd_debug_http_probe($remoteJsUrl));
    }

    $comparison = [];
    $localCss = $local['build']['referenced_css'] ?? null;
    $localJs = $local['build']['referenced_js'] ?? null;

    if (!$remoteDist['ok']) {
        $comparison[] = [
            'severity' => 'critical',
            'code' => 'remote_dist_unreachable',
            'message' => 'Cannot read remote dist/index.html. The dist folder is likely not deployed or blocked.',
        ];
    } elseif ($remoteCssName !== $localCss || $remoteJsName !== $localJs) {
        $comparison[] = [
            'severity' => 'critical',
            'code' => 'build_hash_mismatch',
            'message' => sprintf(
                'Production build differs from this server. Local CSS/JS: %s / %s. Remote CSS/JS: %s / %s.',
                (string) $localCss,
                (string) $localJs,
                (string) $remoteCssName,
                (string) $remoteJsName
            ),
        ];
    }

    if ($remoteCssProbe && !($remoteCssProbe['ok'] ?? false)) {
        $comparison[] = [
            'severity' => 'critical',
            'code' => 'remote_active_css_missing',
            'message' => 'Remote active CSS file is not reachable (404 or blocked).',
        ];
    }
    if ($remoteJsProbe && !($remoteJsProbe['ok'] ?? false)) {
        $comparison[] = [
            'severity' => 'critical',
            'code' => 'remote_active_js_missing',
            'message' => 'Remote active JS file is not reachable (404 or blocked).',
        ];
    }

    if ($probes['local_css_on_remote']['ok'] ?? false) {
        $comparison[] = [
            'severity' => 'info',
            'code' => 'local_assets_on_remote',
            'message' => 'This server exact CSS hash exists on remote.',
        ];
    } elseif (!($probes['local_css_on_remote']['ok'] ?? false) && ($localCss !== null)) {
        $comparison[] = [
            'severity' => 'high',
            'code' => 'local_css_not_on_remote',
            'message' => 'Upload dist/assets/' . $localCss . ' and dist/index.html to production.',
        ];
    }

    $remoteMarkers = [];
    if ($remoteCssProbe && ($remoteCssProbe['ok'] ?? false)) {
        $raw = @file_get_contents((string) $remoteCssProbe['url'], false, stream_context_create([
            'http' => ['timeout' => 15, 'header' => "User-Agent: SPPD-Deploy-Debug\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]));
        if (is_string($raw)) {
            $remoteMarkers = sppd_debug_scan_markers($raw, array_slice(sppd_debug_feature_markers(), 2, 5, true));
            foreach ($remoteMarkers as $key => $present) {
                if (!$present) {
                    $comparison[] = [
                        'severity' => 'high',
                        'code' => 'remote_css_missing_' . $key,
                        'message' => 'Production CSS is missing feature: ' . $key,
                    ];
                }
            }
        }
    }

    return [
        'remote_base' => $remoteBase,
        'probes' => $probes,
        'remote_build' => [
            'referenced_css' => $remoteCssName,
            'referenced_js' => $remoteJsName,
        ],
        'remote_active_probes' => [
            'css' => $remoteCssProbe,
            'js' => $remoteJsProbe,
        ],
        'remote_css_markers' => $remoteMarkers,
        'comparison_issues' => $comparison,
    ];
}

/** @param array<string, mixed> $report */
function sppd_debug_render_html(array $report): void
{
    header('Content-Type: text/html; charset=utf-8');
    $local = $report['local'];
    $remote = $report['remote'] ?? null;
    $allIssues = $local['issues'];
    if ($remote) {
        $allIssues = array_merge($allIssues, $remote['comparison_issues'] ?? []);
    }

    $severityColor = static function (string $severity): string {
        return match ($severity) {
            'critical' => '#b91c1c',
            'high' => '#c2410c',
            'medium' => '#a16207',
            'warning' => '#a16207',
            default => '#0369a1',
        };
    };

    $na = 'n/a';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPPD Deploy Debug</title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; line-height: 1.5; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1.25rem; }
        h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
        .sub { color: #94a3b8; font-size: 0.875rem; margin-bottom: 1rem; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: 1rem; }
        h2 { font-size: 1rem; margin: 0 0 0.75rem; color: #f8fafc; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        th, td { text-align: left; padding: 0.45rem 0.5rem; border-bottom: 1px solid #334155; vertical-align: top; }
        th { color: #94a3b8; font-weight: 600; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
        pre { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 0.75rem; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        .ok { color: #4ade80; }
        .bad { color: #f87171; }
        .pill { display: inline-block; padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .actions a { color: #93c5fd; margin-right: 1rem; }
        ul.issues { margin: 0; padding-left: 1.1rem; }
        ul.issues li { margin-bottom: 0.35rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Stock Purchase Payment Desk - Deploy Debug</h1>
    <p class="sub">Host: <strong><?= htmlspecialchars((string) $local['host']) ?></strong> | Generated <?= htmlspecialchars((string) $local['generated_at']) ?></p>
    <p class="actions">
        <a href="?format=json<?= $remote ? '&remote=' . urlencode((string) $remote['remote_base']) : '' ?>">JSON export</a>
        <a href="?remote=https://ultitech.io/ultimate">Compare with ultitech.io</a>
        <a href="<?= htmlspecialchars((string) $local['paths']['desk_url']) ?>">Open desk</a>
    </p>

    <?php if ($allIssues !== []): ?>
    <div class="card">
        <h2>Issues (<?= count($allIssues) ?>)</h2>
        <ul class="issues">
            <?php foreach ($allIssues as $issue): ?>
                <li>
                    <span class="pill" style="background: <?= $severityColor((string) $issue['severity']) ?>22; color: <?= $severityColor((string) $issue['severity']) ?>">
                        <?= htmlspecialchars((string) $issue['severity']) ?>
                    </span>
                    <?= htmlspecialchars((string) $issue['message']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="card"><p class="ok">No issues detected on this host.</p></div>
    <?php endif; ?>

    <div class="card">
        <h2>Active build (this server)</h2>
        <table>
            <tr><th>CSS</th><td><code><?= htmlspecialchars((string) ($local['build']['referenced_css'] ?? $na)) ?></code></td></tr>
            <tr><th>JS</th><td><code><?= htmlspecialchars((string) ($local['build']['referenced_js'] ?? $na)) ?></code></td></tr>
            <tr><th>CSS SHA-256</th><td><code><?= htmlspecialchars((string) ($local['files']['active_css']['sha256'] ?? $na)) ?></code></td></tr>
            <tr><th>JS SHA-256</th><td><code><?= htmlspecialchars((string) ($local['files']['active_js']['sha256'] ?? $na)) ?></code></td></tr>
            <tr><th>Shell PHP mtime</th><td><?= htmlspecialchars((string) ($local['files']['shell_php']['mtime_iso'] ?? $na)) ?></td></tr>
            <tr><th>Desk URL</th><td><a href="<?= htmlspecialchars((string) $local['paths']['desk_url']) ?>" style="color:#93c5fd"><?= htmlspecialchars((string) $local['paths']['desk_url']) ?></a></td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Feature markers (this server)</h2>
        <table>
            <tr><th>Marker</th><th>Shell PHP</th><th>Built CSS</th><th>Built JS</th></tr>
            <?php
            foreach (array_keys(sppd_debug_feature_markers()) as $key):
                $inShell = $local['feature_markers']['shell_php'][$key] ?? null;
                $inCss = $local['feature_markers']['built_css'][$key] ?? null;
                $inJs = $local['feature_markers']['built_js'][$key] ?? null;
                $fmt = static function ($v) use ($na): string {
                    if ($v === null) {
                        return $na;
                    }

                    return $v ? '<span class="ok">yes</span>' : '<span class="bad">no</span>';
                };
                ?>
            <tr>
                <td><code><?= htmlspecialchars($key) ?></code></td>
                <td><?= $fmt($inShell) ?></td>
                <td><?= $fmt($inCss) ?></td>
                <td><?= $fmt($inJs) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($remote): ?>
    <div class="card">
        <h2>Remote probe: <?= htmlspecialchars((string) $remote['remote_base']) ?></h2>
        <table>
            <tr><th>Remote CSS (from dist/index)</th><td><code><?= htmlspecialchars((string) ($remote['remote_build']['referenced_css'] ?? $na)) ?></code></td></tr>
            <tr><th>Remote JS</th><td><code><?= htmlspecialchars((string) ($remote['remote_build']['referenced_js'] ?? $na)) ?></code></td></tr>
        </table>
        <h2 style="margin-top:1rem">HTTP probes</h2>
        <table>
            <tr><th>Target</th><th>Status</th><th>Result</th></tr>
            <?php foreach ($remote['probes'] as $name => $probe): ?>
            <tr>
                <td><code><?= htmlspecialchars((string) $name) ?></code></td>
                <td><?= htmlspecialchars((string) ($probe['status'] ?? $na)) ?></td>
                <td class="<?= ($probe['ok'] ?? false) ? 'ok' : 'bad' ?>"><?= htmlspecialchars((string) ($probe['error'] ?? 'OK')) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Deploy checklist</h2>
        <ol style="margin:0;padding-left:1.2rem;font-size:0.875rem;color:#cbd5e1">
            <li>Run <code>npm run build</code> in <code>stock-purchase-payment-desk-ui/</code></li>
            <li>Upload <code>modules/finance/stock-purchase-payment-desk.php</code></li>
            <li>Upload entire <code>modules/finance/stock-purchase-payment-desk-ui/dist/</code> folder</li>
            <li>Upload <code>modules/finance/stock-purchase-payment-desk-debug.php</code> (this file)</li>
            <li>Run this debug page on production and compare JSON with localhost</li>
        </ol>
    </div>

    <div class="card">
        <h2>Full JSON</h2>
        <pre><?= htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
    </div>
</div>
</body>
</html>
    <?php
}

$local = sppd_debug_collect_local_state();
$report = ['local' => $local];

$remoteBase = trim((string) ($_GET['remote'] ?? ''));
if ($remoteBase !== '') {
    $report['remote'] = sppd_debug_probe_remote($remoteBase, $local);
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

sppd_debug_render_html($report);
