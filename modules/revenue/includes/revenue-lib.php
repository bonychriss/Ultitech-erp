<?php

declare(strict_types=1);

function revenueDeskBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once __DIR__ . '/revenue-entries-lib.php';
        $booted = true;
    }

    return revenue_entries_resolve_pdo();
}

function revenueDeskRequireAccess(): void
{
    revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        header('Location: select-module.php?error=access_denied');
        exit();
    }
}

function revenueDeskModuleBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim(app_url('/modules/revenue'), '/');
    }

    return '/modules/revenue';
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function revenueDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $assetBase = revenueDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = revenueDeskPublicUrl('api');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;
    $distVersion = is_file($distIndex) ? (string) filemtime($distIndex) : (string) time();

    return [
        'distHtml' => $distHtml,
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : $distVersion,
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : $distVersion,
    ];
}

function revenueDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return revenueDeskModuleBasePath() . '/' . $relativePath;
}

function revenueDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    ob_start();
    require dirname(__DIR__, 3) . '/includes/nav-back-script.php';
    $parts[] = trim((string) ob_get_clean());

    return implode("\n    ", $parts);
}

function revenue_desk_list_url(array $query = []): string
{
    $params = array_merge(['module' => 'revenue'], $query);
    $qs = http_build_query($params);
    if (function_exists('company_url')) {
        return rtrim(company_url('revenue_entries.php'), '/') . '?' . $qs;
    }
    if (function_exists('app_url')) {
        return app_url('/revenue_entries.php?' . $qs);
    }
    return 'revenue_entries.php?' . $qs;
}
