<?php

declare(strict_types=1);

/**
 * React shell helpers for Admin Company Settings.
 */

function companySettingsUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function companySettingsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return companySettingsUiWebBasePath() . '/company-settings-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function companySettingsUiLoadReactAssets(): ?array
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
        'assetBase' => companySettingsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function companySettingsUiShellHeadExtras(): string
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
function companySettingsRenderReactShell(array $cfg): void
{
    $assets = companySettingsUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Company Settings</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Company Settings</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>admin/company-settings-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Company Settings';
    $employeeHeaderTitle = 'Company Settings';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cfgJson === false) {
        $cfgJson = '{}';
    }

    $GLOBALS['_erp_header_style_linked'] = true;
    require __DIR__ . '/react-shell.php';
}
