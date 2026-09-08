<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the employee Create Voucher React app.
 * Built with Vite into employee/create-voucher-ui/frontend/dist.
 */

function createVoucherUiWebBasePath(): string
{
    // Always serve Vite assets from physical /employee/ (not /{slug}/employee aliases).
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/employee'), '/');
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '' && preg_match('#^(.*?)/employee(?:/|$)#', $script, $m)) {
        // /public_html/ultimate/employee/... → /public_html/employee
        $prefix = $m[1];
        if (preg_match('#/([A-Za-z0-9-]+)$#', $prefix, $slug)
            && !in_array(strtolower($slug[1]), ['public_html', 'htdocs'], true)
        ) {
            $prefix = preg_replace('#/([A-Za-z0-9-]+)$#', '', $prefix) ?: $prefix;
        }
        return rtrim($prefix, '/') . '/employee';
    }
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }
    return '/employee';
}

function createVoucherUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return createVoucherUiWebBasePath() . '/create-voucher-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function createVoucherUiLoadReactAssets(): ?array
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
        'assetBase' => createVoucherUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
