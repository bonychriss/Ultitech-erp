<?php

declare(strict_types=1);

namespace App\Domains\Attendance;

/**
 * Attendance React shell: Vite dist under attendance/attendance-ui + ERP Blade chrome.
 */
final class AttendanceShell
{
    /** Soft light-blue immersive mobile status bar + header. */
    private const MOBILE_TOP_COLOR = '#BFDBFE';

    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:?string,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $erp = is_array($cfg['erp'] ?? null) ? $cfg['erp'] : [];
        $page = strtolower(trim((string) ($cfg['page'] ?? 'clock')));
        if ($page !== 'analytics') {
            $page = 'clock';
        }

        if ($page === 'analytics') {
            $period = isset($cfg['period']) ? (int) $cfg['period'] : null;
            $boot = (new AnalyticsBoot())->build($erp, $period);
            $wallpaperUrl = (new ClockBoot())->wallpaperUrl();
            $pageTitle = 'Stats';
            $headerTitle = 'Stats';
            $bodyClass = 'page-products-desk page-attendance-desk page-attendance-analytics page-attendance-laravel page-erp-laravel';
        } else {
            $boot = (new ClockBoot())->build($erp);
            $wallpaperUrl = (new ClockBoot())->wallpaperUrl();
            $pageTitle = 'Attendance';
            $headerTitle = null;
            $bodyClass = 'page-products-desk page-attendance-desk page-attendance-laravel page-erp-laravel';
        }

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"page":"' . $page . '","data":{}}';
        }

        $headMarkup = $this->mobileStatusBarHead()
            . $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss($wallpaperUrl) . "\n"
            . '<script>window.__ATTENDANCE_PAGE__ = ' . $bootJson . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => $bodyClass,
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--products-desk',
            'mainRootClass' => 'att-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.attendance') ?: 'attendance/attendance-ui/dist');
        $jsFile = 'attendance-ui.js';
        $cssFile = 'attendance-ui.css';
        $jsPath = $root . '/' . $distRel . '/assets/' . $jsFile;
        $cssPath = $root . '/' . $distRel . '/assets/' . $cssFile;

        if (!is_file($jsPath) || !is_file($cssPath)) {
            // Fallback: parse Vite index.html for hashed asset names.
            $distIndex = $root . '/' . $distRel . '/index.html';
            if (!is_file($distIndex)) {
                return null;
            }
            $distHtml = file_get_contents($distIndex) ?: '';
            preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
            preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
            $jsFile = $jsMatch[1] ?? '';
            $cssFile = $cssMatch[1] ?? '';
            if ($jsFile === '' || $cssFile === '') {
                return null;
            }
            $jsPath = $root . '/' . $distRel . '/assets/' . $jsFile;
            $cssPath = $root . '/' . $distRel . '/assets/' . $cssFile;
            if (!is_file($jsPath) || !is_file($cssPath)) {
                return null;
            }
        }

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/' . $distRel . '/assets/'), '/') . '/'
            : '/public_html/' . $distRel . '/assets/';

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => (string) filemtime($cssPath),
            'jsVersion' => (string) filemtime($jsPath),
        ];
    }

    /**
     * Immersive mobile top chrome: status bar + header share one solid fill (SportyBet-style).
     */
    private function mobileStatusBarHead(): string
    {
        $color = self::MOBILE_TOP_COLOR;

        return '<meta name="theme-color" content="' . $color . '">' . "\n"
            . '<meta name="theme-color" media="(prefers-color-scheme: light)" content="' . $color . '">' . "\n"
            . '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="' . $color . '">' . "\n"
            . '<meta name="msapplication-navbutton-color" content="' . $color . '">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n"
            . '<script>(function(){var m=document.querySelector(\'meta[name="viewport"]\');'
            . 'if(m){m.setAttribute("content","width=device-width, initial-scale=1.0, viewport-fit=cover");}'
            . 'document.documentElement.style.backgroundColor="' . $color . '";'
            . '})();</script>' . "\n";
    }

    private function commonHeadExtras(): string
    {
        $parts = [
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        ];
        if (function_exists('app_url')) {
            $erpStylePath = rtrim((string) config('erp.app_root'), '\\/') . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
            if (function_exists('erp_dark_theme_css_url')) {
                $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        return implode("\n", $parts) . "\n";
    }

    private function deskChromeCss(string $wallpaperUrl): string
    {
        $wp = htmlspecialchars($wallpaperUrl, ENT_QUOTES, 'UTF-8');
        $top = self::MOBILE_TOP_COLOR;

        return <<<CSS
<style>
body.page-attendance-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-attendance-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
@media (min-width: 992px) {
    body.page-attendance-desk .layout-main-wrapper { gap: 12px !important; }
}
body.page-attendance-desk,
body.page-attendance-desk.dashboard,
body.page-attendance-desk .layout-main-wrapper,
body.page-attendance-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-attendance-desk .employee-header.employee-header--products-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-attendance-desk .employee-header--products-desk::after { display: none !important; }
body.page-attendance-desk .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.65rem 0 !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem;
}
body.page-attendance-desk .employee-header--products-desk .header-right.header-actions-tray {
    margin-left: auto !important;
}
body.page-attendance-analytics .employee-header--products-desk .employee-header-page-title {
    font-weight: 500 !important;
}
main.main-content.att-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.att-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-attendance-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    main.main-content.att-react-root {
        padding: 0 0.75rem 1.25rem !important;
    }
}
@media (max-width: 575.98px) {
    main.main-content.att-react-root {
        padding: 0 0.6rem 1rem !important;
    }
}
/* Safety: never show floating mobile footer on Stats */
@media (max-width: 991.98px) {
    body.page-attendance-analytics .mobile-footer {
        display: none !important;
    }
    body.page-attendance-analytics.has-mobile-footer {
        padding-bottom: 0 !important;
    }
}
body.page-attendance-analytics #erp-nav-back-control {
    display: none !important;
}
html[data-theme="dark"] body.page-attendance-desk,
html[data-theme="dark"] body.page-attendance-desk.dashboard,
html[data-theme="dark"] body.page-attendance-analytics,
html[data-theme="dark"] body.page-attendance-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-attendance-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-attendance-desk main.main-content,
html[data-theme="dark"] body.page-attendance-desk main.main-content.att-react-root,
html[data-theme="dark"] body.page-attendance-analytics main.main-content.att-react-root {
    background: #020617 !important;
}
html[data-theme="dark"] body.page-attendance-desk .employee-header.employee-header--products-desk,
html[data-theme="dark"] body.page-attendance-analytics .employee-header.employee-header--products-desk {
    background: #020617 !important;
    border-bottom-color: #1e293b !important;
}
html[data-theme="dark"] body.page-attendance-desk .att-shell,
html[data-theme="dark"] body.page-attendance-analytics .att-shell {
    background: #020617 !important;
    color: #e2e8f0 !important;
}
html[data-theme="dark"] body.page-attendance-analytics .att-shell.att-page-analytics {
    background: transparent !important;
}
body.page-attendance-desk .clock-card-v2 {
    background-color: #1c1917 !important;
    background-image:
        linear-gradient(160deg, rgba(15, 23, 42, 0.55) 0%, rgba(15, 23, 42, 0.28) 45%, rgba(28, 25, 23, 0.45) 100%),
        url('{$wp}') !important;
    background-position: center center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
    box-shadow: 0 14px 36px rgba(15, 23, 42, 0.28) !important;
}
/* Immersive top: one light-purple block under time/battery (SportyBet-style) */
@media (max-width: 991.98px) {
    html:has(body.page-attendance-desk),
    html[data-theme="dark"]:has(body.page-attendance-desk) {
        background-color: {$top} !important;
    }
    body.page-attendance-desk,
    body.page-attendance-desk.dashboard,
    html[data-theme="dark"] body.page-attendance-desk,
    html[data-theme="dark"] body.page-attendance-desk.dashboard {
        background-color: #020617 !important;
        background-image: linear-gradient({$top}, {$top}) !important;
        background-size: 100% calc(env(safe-area-inset-top, 0px) + 3.5rem) !important;
        background-repeat: no-repeat !important;
        background-position: top center !important;
    }
    html:not([data-theme="dark"]) body.page-attendance-desk,
    html:not([data-theme="dark"]) body.page-attendance-desk.dashboard {
        background-color: #f8fafc !important;
        background-image: linear-gradient({$top}, {$top}) !important;
        background-size: 100% calc(env(safe-area-inset-top, 0px) + 3.5rem) !important;
        background-repeat: no-repeat !important;
        background-position: top center !important;
    }
    body.page-attendance-desk .employee-header.employee-header--products-desk,
    body.page-attendance-analytics .employee-header.employee-header--products-desk,
    html[data-theme="dark"] body.page-attendance-desk .employee-header.employee-header--products-desk,
    html[data-theme="dark"] body.page-attendance-analytics .employee-header.employee-header--products-desk {
        background: {$top} !important;
        border: none !important;
        box-shadow: none !important;
        padding-top: env(safe-area-inset-top, 0px) !important;
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
        margin: 0 !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 1020 !important;
    }
    body.page-attendance-desk .employee-header--products-desk .header-content {
        padding: 0.7rem 0 !important;
        min-height: 2.75rem;
        background: transparent !important;
    }
    body.page-attendance-desk .employee-header--products-desk .employee-header-page-title,
    body.page-attendance-analytics .employee-header--products-desk .employee-header-page-title,
    body.page-attendance-desk .employee-header--products-desk .employee-header-page-title[style] {
        color: #0f172a !important;
    }
    body.page-attendance-desk .employee-header--products-desk .employee-header-menu-btn,
    body.page-attendance-desk .employee-header--products-desk .employee-header-menu-btn[style],
    body.page-attendance-desk .employee-header--products-desk .header-actions-tray a,
    body.page-attendance-desk .employee-header--products-desk .header-actions-tray button,
    body.page-attendance-desk .employee-header--products-desk .header-actions-tray i,
    body.page-attendance-desk .employee-header--products-desk .header-actions-tray svg {
        color: #0f172a !important;
    }
    body.page-attendance-desk .layout-main-wrapper,
    body.page-attendance-desk .layout-main-wrapper > .flex-grow-1 {
        background: transparent !important;
    }
}
</style>
CSS;
    }
}
