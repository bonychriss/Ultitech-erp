<?php

declare(strict_types=1);

/**
 * Asset helpers for the Login React page.
 * Built with Vite into login-ui/frontend/dist.
 */

function loginUiWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (substr($dir, -8) === '/login-ui') {
            return rtrim(dirname($dir), '/');
        }
        return $dir === '' ? '' : $dir;
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url(''), '/');
    }
    return '';
}

function loginUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return app_url('/login-ui/' . $relativePath);
    }
    $base = loginUiWebBasePath();
    return ($base === '' ? '' : $base) . '/login-ui/' . $relativePath;
}

/**
 * Ensure asset URLs work from tenant login routes (e.g. /roadmaster/login).
 */
function loginUiNormalizePublicUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || str_starts_with($url, 'data:')) {
        return $url;
    }

    $path = '/' . ltrim(str_replace('\\', '/', $url), '/');
    if (!function_exists('app_url')) {
        return $path;
    }

    $base = '/' . trim((string) APP_BASE_PATH, '/');
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        return $path;
    }

    return app_url($path);
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function loginUiLoadReactAssets(): ?array
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
        'assetBase' => loginUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}
