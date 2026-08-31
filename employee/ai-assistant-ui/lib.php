<?php

declare(strict_types=1);

function aiAssistantUiWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/employee'), '/');
    }
    return '/employee';
}

function aiAssistantUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return aiAssistantUiWebBasePath() . '/ai-assistant-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl:string,actionUrl:string,exportUrl:string,vouchersUrl:string}|null
 */
function aiAssistantUiLoadReactAssets(): ?array
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
        'assetBase' => aiAssistantUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'apiUrl' => aiAssistantUiPublicUrl('api/init.php'),
        'actionUrl' => aiAssistantUiPublicUrl('api/action.php'),
        'exportUrl' => aiAssistantUiPublicUrl('api/export.php'),
        'vouchersUrl' => aiAssistantUiWebBasePath() . '/my-vouchers.php?module=voucher',
    ];
}
