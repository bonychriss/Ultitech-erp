<?php

namespace App\Domains\Home;

/**
 * Public homepage React shell (home-ui Vite dist) via erp-laravel Blade.
 */
final class HomeShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
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

        $bootCfg = [
            'homeUrl' => (string) ($cfg['homeUrl'] ?? (function_exists('app_url') ? app_url('/') : '/')),
            'loginUrl' => (string) ($cfg['loginUrl'] ?? (function_exists('app_url') ? app_url('/login.php') : '/login.php')),
            'accountUrl' => (string) ($cfg['accountUrl'] ?? (function_exists('app_url') ? app_url('/my-account.php') : '/my-account.php')),
            'trialUrl' => (string) ($cfg['trialUrl'] ?? (function_exists('app_url') ? app_url('/free-trial.php') : '/free-trial.php')),
            'year' => (int) ($cfg['year'] ?? date('Y')),
            'engine' => 'erp-laravel Domains/Home',
        ];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $headMarkup = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&display=swap" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">' . "\n";
        if ($assets['cssFile'] !== '') {
            $headMarkup .= '<link rel="stylesheet" crossorigin href="'
                . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        $headMarkup .= '<script>window.__HOME_CFG__ = '
            . json_encode($bootCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => 'UltiTech ERP | Welcome',
            'bodyClass' => 'home-ui-page',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distIndex = $root . '/home-ui/frontend/dist/index.html';
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
            ? rtrim((string) app_url('/home-ui/frontend/dist/assets/'), '/') . '/'
            : '/public_html/home-ui/frontend/dist/assets/';

        $cssPath = $root . '/home-ui/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/home-ui/frontend/dist/assets/' . $jsFile;

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}
