<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the Admin Dashboard React app.
 * Reuses the built employee/dashboard-ui frontend; JSON API lives under admin/dashboard-ui/api.
 */

function adminDashboardUiWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    return '/admin';
}

function adminDashboardUiRootWebPath(): string
{
    $adminBase = adminDashboardUiWebBasePath();
    $root = rtrim(str_replace('\\', '/', dirname($adminBase)), '/');
    return ($root === '' || $root === '/') ? '' : $root;
}

function adminDashboardUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return adminDashboardUiWebBasePath() . '/dashboard-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function adminDashboardUiLoadReactAssets(): ?array
{
    $distDir = __DIR__ . '/../../employee/dashboard-ui/frontend/dist';
    $distIndex = $distDir . '/index.html';
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

    $cssPath = $distDir . '/assets/' . $cssFile;
    $jsPath = $distDir . '/assets/' . $jsFile;
    $rootWeb = adminDashboardUiRootWebPath();
    $assetBase = ($rootWeb === '' ? '' : $rootWeb) . '/employee/dashboard-ui/frontend/dist/assets/';

    return [
        'assetBase' => $assetBase,
        'apiUrl' => adminDashboardUiPublicUrl('api'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
