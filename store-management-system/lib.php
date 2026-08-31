<?php

declare(strict_types=1);

/**
 * Asset helpers for the Store Management React UI.
 * Built with Vite into store-management-system/dist.
 */

function storeManagementUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return app_url('store-management-system/' . $relativePath);
    }
    return './' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl:string}|null
 */
function storeManagementUiLoadReactAssets(): ?array
{
    $distIndex = __DIR__ . '/dist/index.html';
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

    $cssPath = __DIR__ . '/dist/assets/' . $cssFile;
    $jsPath = __DIR__ . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => storeManagementUiPublicUrl('dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'apiUrl' => storeManagementUiPublicUrl('api/index.php'),
    ];
}
