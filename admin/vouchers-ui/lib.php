<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the React All-Vouchers desk.
 * The React app is built with Vite into vouchers-ui/frontend/dist.
 */

function vouchersUiWebBasePath(): string
{
    // Prefer company-scoped /{slug}/admin so React fetch stays on the tenant URL.
    if (function_exists('company_url')) {
        return rtrim((string) company_url('admin'), '/');
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '' && preg_match('#^(.*?)/admin(?:/|$)#', $script, $m)) {
        return rtrim($m[1], '/') . '/admin';
    }
    if ($script !== '') {
        return rtrim(dirname($script), '/');
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
    // Static Vite assets stay on physical /admin/...; API must be company-scoped.
    $apiUrl = function_exists('company_url')
        ? rtrim((string) company_url('admin/vouchers-ui/api'), '/')
        : vouchersUiPublicUrl('api');

    return [
        'assetBase' => vouchersUiPublicUrl('frontend/dist/assets/'),
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
