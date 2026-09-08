<?php

namespace App\Domains\Sales;

/**
 * Resolve Sales dashboard Vite assets and browser cfg for the React shell.
 */
final class DashboardShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{pageTitle:string,bodyClass:string,headMarkup:string,footerScripts:string}|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $lib = (new DashboardInit())->dashboardLibPath();
        if (is_file($lib)) {
            require_once $lib;
            if (function_exists('dashboardDeskBootstrap')) {
                dashboardDeskBootstrap();
            }
        }

        $themeColor = '#3b82f6';
        if (function_exists('dashboardInitData')) {
            try {
                $init = dashboardInitData();
                if (is_array($init)) {
                    $themeColor = (string) ($init['company']['theme_color'] ?? $themeColor);
                }
            } catch (\Throwable $e) {
                // Shell can still render.
            }
        }

        $apiBase = (string) ($cfg['apiBase'] ?? '');
        $initUrl = (string) ($cfg['initUrl'] ?? '');
        $module = (string) ($cfg['module'] ?? 'sales');

        $headExtras = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">' . "\n";
        if (function_exists('app_url')) {
            $erpStylePath = rtrim((string) config('erp.app_root'), '\\/') . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $headExtras .= '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">' . "\n";
            if (function_exists('erp_dark_theme_css_url')) {
                $headExtras .= '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }
        $headExtras .= '<link rel="stylesheet" href="' . htmlspecialchars($assets['dashCssUrl'], ENT_QUOTES, 'UTF-8') . '">';

        $headMarkup = $headExtras . "\n"
            . '<link rel="stylesheet" crossorigin href="'
            . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8')
            . '">' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<style>:root { --accent-blue: ' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8')
            . ' !important; --brand-blue: ' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . '; }</style>' . "\n"
            . '<script>'
            . 'window.__SALES_DASHBOARD_API_BASE__ = ' . json_encode($apiBase, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_DASHBOARD_INIT_URL__ = ' . json_encode($initUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_DASHBOARD_CFG__ = ' . json_encode([
                'module' => $module,
                'theme_color' => $themeColor,
                'engine' => 'erp-laravel Domains/Sales',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>';

        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];
        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => 'Sales Dashboard',
            'bodyClass' => 'page-exp-desk exp-dashboard-page sales-dashboard page-sales-dashboard-desk page-sales-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => 'Sales Dashboard',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--exp-desk',
            'mainRootClass' => 'exp-desk-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,dashCssUrl:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distIndex = $root . '/modules/sales/dashboard/frontend/dist/index.html';
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
            ? rtrim((string) app_url('/modules/sales/dashboard/frontend/dist/assets/'), '/') . '/'
            : '/public_html/modules/sales/dashboard/frontend/dist/assets/';

        $cssPath = $root . '/modules/sales/dashboard/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/modules/sales/dashboard/frontend/dist/assets/' . $jsFile;
        $dashCssPath = $root . '/modules/sales/dashboard/dashboard.css';
        $dashCssUrl = function_exists('app_url')
            ? app_url('/modules/sales/dashboard/dashboard.css') . '?v=' . (is_file($dashCssPath) ? (string) filemtime($dashCssPath) : (string) time())
            : '/public_html/modules/sales/dashboard/dashboard.css';

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
            'dashCssUrl' => $dashCssUrl,
        ];
    }
}
