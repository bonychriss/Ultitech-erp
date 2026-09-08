<?php

namespace App\Domains\Trial;

/**
 * Free-trial signup React shell (login-ui Vite dist) via erp-laravel Blade.
 */
final class TrialShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   htmlClass:string,
     *   headMarkup:string,
     *   footerScripts:string
     * }|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $bootCfg = $cfg;
        $bootCfg['engine'] = 'erp-laravel Domains/Trial';

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $headMarkup = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        if ($assets['cssFile'] !== '') {
            $headMarkup .= '<link rel="stylesheet" crossorigin href="'
                . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($bootCfg, $flags);
        if ($json === false) {
            $json = '{}';
        }
        $headMarkup .= '<script>window.__TRIAL_CFG__ = ' . $json . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => 'Start Free Trial | UltiTech ERP',
            'bodyClass' => 'page-register page-trial',
            'htmlClass' => 'page-register page-trial',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        if (function_exists('loginUiLoadReactAssets')) {
            $assets = loginUiLoadReactAssets();
            if (is_array($assets)) {
                return $assets;
            }
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distIndex = $root . '/login-ui/frontend/dist/index.html';
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

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/login-ui/frontend/dist/assets/'), '/') . '/'
            : '/public_html/login-ui/frontend/dist/assets/';

        $cssPath = $root . '/login-ui/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/login-ui/frontend/dist/assets/' . $jsFile;

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}
