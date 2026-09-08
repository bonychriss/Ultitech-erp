<?php

declare(strict_types=1);

/**
 * React shell helpers for Sales (Laravel API + ERP sidebar).
 * Uses the existing modules/sales/dashboard Vite build (Phase 1).
 */

function salesUiDashboardDistDir(): string
{
    return dirname(__DIR__) . '/modules/sales/dashboard/frontend/dist';
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,dashCssUrl:string}|null
 */
function salesUiLoadReactAssets(): ?array
{
    $distIndex = salesUiDashboardDistDir() . '/index.html';
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

    $cssPath = salesUiDashboardDistDir() . '/assets/' . $cssFile;
    $jsPath = salesUiDashboardDistDir() . '/assets/' . $jsFile;

    $dashCssPath = dirname(__DIR__) . '/modules/sales/dashboard/dashboard.css';
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

/**
 * @param array<string,mixed> $cfg
 */
function salesUiRenderReactShell(array $cfg): void
{
    $assets = salesUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Sales UI not built</h1>';
        echo '<p>Run <code>npm install && npm run build</code> in <code>modules/sales/dashboard/frontend</code>.</p>';
        echo '</body></html>';
        return;
    }

    // Ensure sales helpers for theme / head extras when available.
    $dashLib = dirname(__DIR__) . '/modules/sales/includes/dashboard-lib.php';
    if (is_file($dashLib)) {
        require_once $dashLib;
        if (function_exists('dashboardDeskBootstrap')) {
            dashboardDeskBootstrap();
        }
    }

    $page_title = 'Sales Dashboard';
    $employeeHeaderTitle = 'Sales Dashboard';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $themeColor = '#3b82f6';
    if (function_exists('dashboardInitData')) {
        try {
            $init = dashboardInitData();
            if (is_array($init)) {
                $themeColor = (string) ($init['company']['theme_color'] ?? $themeColor);
            }
        } catch (Throwable $e) {
            // Shell can still render; API will surface errors.
        }
    }

    $apiBase = (string) ($cfg['apiBase'] ?? '');
    $initUrl = (string) ($cfg['initUrl'] ?? '');
    $module = (string) ($cfg['module'] ?? 'sales');

    $headExtras = '';
    if (function_exists('dashboardDeskShellHeadExtras')) {
        // Override web-base-dependent CSS by injecting dash css via app_url ourselves.
        $headExtras = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">'
            . "\n    ";
        if (function_exists('app_url')) {
            $erpStylePath = dirname(__DIR__) . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $headExtras .= '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">' . "\n    ";
            if (function_exists('erp_dark_theme_css_url')) {
                $headExtras .= '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">' . "\n    ";
            }
        }
        $headExtras .= '<link rel="stylesheet" href="' . htmlspecialchars($assets['dashCssUrl'], ENT_QUOTES, 'UTF-8') . '">';
    }

    $dashboardHeadMarkup = $headExtras . "\n    "
        . '<link rel="stylesheet" crossorigin href="'
        . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8')
        . '">'
        . "\n    " . '<style>:root { --accent-blue: ' . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . ' !important; --brand-blue: '
        . htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') . '; }</style>'
        . "\n    " . '<script>'
        . 'window.__SALES_DASHBOARD_API_BASE__ = ' . json_encode($apiBase, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_DASHBOARD_INIT_URL__ = ' . json_encode($initUrl, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__SALES_DASHBOARD_CFG__ = ' . json_encode([
            'module' => $module,
            'theme_color' => $themeColor,
            'engine' => 'Laravel + React',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ';</script>';

    $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

    require __DIR__ . '/react-shell.php';
}
