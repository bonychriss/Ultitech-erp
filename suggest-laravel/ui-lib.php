<?php

declare(strict_types=1);

/**
 * React shell helpers for Suggest (Laravel API + ERP sidebar).
 */

function suggestUiDistDir(): string
{
    return __DIR__ . '/frontend/dist';
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function suggestUiLoadReactAssets(): ?array
{
    $distIndex = suggestUiDistDir() . '/index.html';
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

    $base = function_exists('app_url')
        ? rtrim((string) app_url('/suggest-laravel/frontend/dist/assets/'), '/') . '/'
        : '/public_html/suggest-laravel/frontend/dist/assets/';

    $cssPath = suggestUiDistDir() . '/assets/' . $cssFile;
    $jsPath = suggestUiDistDir() . '/assets/' . $jsFile;

    return [
        'assetBase' => $base,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * @param array<string,mixed> $cfg
 */
function suggestUiRenderReactShell(array $cfg): void
{
    $assets = suggestUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Suggest UI not built</h1>';
        echo '<p>Run <code>npm install && npm run build</code> in <code>suggest-laravel/frontend</code>.</p>';
        echo '</body></html>';
        return;
    }

    $page_title = 'Suggest a Feature';
    $employeeHeaderTitle = 'Suggestions';
    $employeeHeaderSubtitle = 'Share ideas with the developer team';
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($cfgJson)) {
        $cfgJson = '{}';
    }

    require __DIR__ . '/react-shell.php';
}
