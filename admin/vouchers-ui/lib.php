<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the React All-Vouchers desk.
 * The React app is built with Vite into vouchers-ui/frontend/dist.
 */

function vouchersUiWebBasePath(): string
{
    // Directory of the currently-running script (e.g. /admin for all-vouchers.php).
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    return '/admin';
}

function vouchersUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return vouchersUiWebBasePath() . '/vouchers-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function vouchersUiLoadReactAssets(): ?array
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
        'assetBase' => vouchersUiPublicUrl('frontend/dist/assets/'),
        'apiUrl' => vouchersUiPublicUrl('api'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
