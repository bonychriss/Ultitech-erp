<?php

declare(strict_types=1);

/**
 * Asset helpers for the Select Module React launcher.
 * Built with Vite into select-module-ui/frontend/dist.
 */

function selectModuleUiWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        // select-module.php lives at site root (or /{slug}/select-module via rewrite).
        if (substr($dir, -15) === '/select-module-ui') {
            return rtrim(dirname($dir), '/');
        }
        return $dir === '' ? '' : $dir;
    }
    if (function_exists('app_url')) {
        return rtrim((string) app_url(''), '/');
    }
    return '';
}

function selectModuleUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = selectModuleUiWebBasePath();
    return ($base === '' ? '' : $base) . '/select-module-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function selectModuleUiLoadReactAssets(): ?array
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
        'assetBase' => selectModuleUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * Read published desktop app version from updates/latest.yml (if present on server).
 */
function selectModuleDesktopLatestVersion(): ?string
{
    $candidates = [
        dirname(__DIR__) . '/client-apps/desktop/updates/latest.yml',
        dirname(__DIR__) . '/client-apps/desktop/dist-build/latest.yml',
    ];
    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            continue;
        }
        if (preg_match('/^version:\s*([^\s#]+)/m', $content, $matches)) {
            $version = trim($matches[1]);
            return $version !== '' ? $version : null;
        }
    }
    return null;
}
