<?php

declare(strict_types=1);

/**
 * React shell helpers for Admin Company Management (Platform Control).
 */

function managementUiWebBasePath(): string
{
    // Prefer app_url so tenant routes like /ultimate/admin/... still load
    // real files from /admin/management-ui/ (no physical /ultimate/admin tree).
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        // Strip /{company_slug}/admin when present.
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function managementUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return managementUiWebBasePath() . '/management-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function managementUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => managementUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function managementUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];
    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }
    return implode("\n    ", $parts);
}

/**
 * @param array<string,mixed> $cfg
 */
function managementRenderReactShell(array $cfg): void
{
    $assets = managementUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Company Management</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Company Management</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>admin/management-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Company Management';
    $employeeHeaderTitle = 'Company Management';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cfgJson === false) {
        $cfgJson = '{}';
    }

    $GLOBALS['_erp_header_style_linked'] = true;
    require __DIR__ . '/react-shell.php';
}
