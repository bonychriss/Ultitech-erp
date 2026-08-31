<?php

declare(strict_types=1);

function viewVoucherUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return app_url('/view-voucher-ui/' . $relativePath);
    }

    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $uiRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $webRoot = '';
    if ($docRoot !== '' && strncmp($uiRoot, $docRoot, strlen($docRoot)) === 0) {
        $webRoot = trim(substr($uiRoot, strlen($docRoot)), '/');
    }
    $prefix = $webRoot !== '' ? '/' . $webRoot : '';

    return $prefix . '/view-voucher-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl:string}|null
 */
function viewVoucherUiLoadReactAssets(): ?array
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
        'assetBase' => viewVoucherUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'apiUrl' => viewVoucherUiPublicUrl('api/init.php'),
    ];
}
