<?php

namespace App\Domains\Revenue;

/**
 * Revenue React shell: Vite dist under modules/revenue/frontend + ERP Blade chrome.
 */
final class RevenueShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string,
     *   employeeHeaderSubtitle:string,
     *   employeeHeaderRightHtml:string,
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

        $apiBase = (string) ($cfg['apiBase'] ?? '');
        $page = (string) ($cfg['revenuePage'] ?? 'list');
        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Revenues');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Revenues');

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $pageBase = function_exists('app_url')
            ? rtrim((string) app_url('/'), '/')
            : '/public_html';
        if (function_exists('company_url')) {
            $slug = trim((string) ($cfg['companySlug'] ?? ''));
            if ($slug !== '') {
                $pageBase = rtrim((string) company_url('revenue_entries', $slug), '/');
                // Strip filename so relative ./revenue_*.php links resolve under company folder.
                $pageBase = preg_replace('#/revenue_entries$#', '', $pageBase) ?: $pageBase;
            }
        }

        $windowScript = 'window.__REVENUE_API_BASE__ = ' . json_encode($apiBase, JSON_UNESCAPED_SLASHES)
            . ';window.__REVENUE_PAGE_BASE__ = ' . json_encode($pageBase, JSON_UNESCAPED_SLASHES)
            . ';window.__REVENUE_PAGE__ = ' . json_encode($page, JSON_UNESCAPED_SLASHES)
            . ';window.__REVENUE_CFG__ = ' . json_encode([
                'module' => 'revenue',
                'engine' => 'erp-laravel Domains/Revenue',
                'companySlug' => (string) ($cfg['companySlug'] ?? ''),
                'backUrl' => (string) ($cfg['backUrl'] ?? ''),
                'pageBase' => $pageBase,
                'apiBase' => $apiBase,
                'listUrl' => function_exists('app_url')
                    ? (string) app_url('/revenue_entries.php') . '?module=revenue'
                    : '/revenue_entries.php?module=revenue',
                'createUrl' => function_exists('app_url')
                    ? (string) app_url('/revenue_create.php') . '?module=revenue'
                    : '/revenue_create.php?module=revenue',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $extraClass = 'employee-header--rev-desk';
        $bodyClass = 'page-rev-desk rev-dashboard-page page-revenue-laravel page-erp-laravel';
        $headerSubtitle = '';
        $headerRightHtml = '';
        if ($page === 'list') {
            $companySlug = trim((string) ($cfg['companySlug'] ?? ''));
            $homeUrl = function_exists('company_url') && $companySlug !== ''
                ? company_url('select-module', $companySlug)
                : (function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php');
            $accountingUrl = function_exists('company_url')
                ? company_url('modules/accounting/index', $companySlug !== '' ? $companySlug : null)
                : (function_exists('app_url') ? app_url('/modules/accounting/index.php') : '/modules/accounting/index.php');
            $headerSubtitle = '<nav class="vv-breadcrumb rev-desk-breadcrumb" aria-label="Breadcrumb">'
                . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') . '">Home</a>'
                . '<span class="vv-breadcrumb-sep">/</span>'
                . '<a class="vv-breadcrumb-link erp-nav-back-ignore" href="' . htmlspecialchars($accountingUrl . '?module=accounting', ENT_QUOTES, 'UTF-8') . '">Accounting</a>'
                . '<span class="vv-breadcrumb-sep">/</span>'
                . '<span class="vv-breadcrumb-current">Revenue</span>'
                . '</nav>';
            $headerRightHtml = '<div id="rev-desk-header-tools" class="rev-desk-header-tools"></div>';
        }
        if ($page === 'create') {
            $extraClass .= ' employee-header--rev-create';
            $bodyClass .= ' page-rev-create';
        }
        if ($page === 'import') {
            $extraClass .= ' employee-header--rev-import';
            $bodyClass .= ' page-rev-import';
        }

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss() . "\n"
            . '<script>' . $windowScript . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => $bodyClass,
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'employeeHeaderSubtitle' => $headerSubtitle,
            'employeeHeaderRightHtml' => $headerRightHtml,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => $extraClass,
            'mainRootClass' => 'rev-desk-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.revenue') ?: 'modules/revenue/frontend/dist');
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

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/' . $distRel . '/assets/'), '/') . '/'
            : '/public_html/' . $distRel . '/assets/';

        $cssPath = $root . '/' . $distRel . '/assets/' . $cssFile;
        $jsPath = $root . '/' . $distRel . '/assets/' . $jsFile;

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
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

    private function deskChromeCss(): string
    {
        return <<<'CSS'
<style>
/* Outer page locked — only .rev-desk-table-wrap scrolls. */
html.page-rev-desk-lock,
html:has(body.page-rev-desk),
html:has(body.page-rev-desk) body,
body.page-rev-desk,
body.page-rev-desk.dashboard {
    height: 100dvh !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html.page-rev-desk-lock::-webkit-scrollbar,
html:has(body.page-rev-desk)::-webkit-scrollbar,
body.page-rev-desk::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.page-rev-desk .layout-main-wrapper,
body.page-rev-desk .layout-main-wrapper.min-vh-100 {
    align-items: stretch !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    min-height: 0 !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
}
body.page-rev-desk .layout-main-wrapper > .flex-grow-1,
body.page-rev-desk .flex-grow-1.d-flex.flex-column {
    min-height: 0 !important;
    height: 100% !important;
    max-height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    overscroll-behavior: none !important;
    scrollbar-width: none !important;
}
body.page-rev-desk,
body.page-rev-desk.dashboard,
body.page-rev-desk .layout-main-wrapper,
body.page-rev-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-rev-desk .employee-header.employee-header--rev-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0 !important;
    height: auto !important;
    min-height: 0 !important;
    flex: 0 0 auto !important;
    position: relative !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-rev-desk .employee-header--rev-desk::after { display: none !important; }
body.page-rev-desk .employee-header--rev-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-rev-desk .employee-header--rev-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-rev-desk .employee-header--rev-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-rev-desk .employee-header--rev-desk .employee-header-page-subtitle {
    color: inherit !important;
}
body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    margin: 0;
    font-size: 0.82rem;
    line-height: 1.3;
}
body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-link {
    color: #2563eb !important;
    font-weight: 600;
    text-decoration: none !important;
}
body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-link:hover {
    color: #1d4ed8 !important;
    text-decoration: underline !important;
}
body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-sep {
    color: #94a3b8;
}
body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-current {
    color: #64748b;
    font-weight: 600;
}
body.page-rev-desk .employee-header--rev-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
body.page-rev-desk .employee-header--rev-desk .header-content,
body.page-rev-desk .employee-header--rev-desk .header-right.header-actions-tray,
body.page-rev-desk .employee-header--rev-desk .rev-desk-header-tools {
    position: static !important;
}
main.main-content.rev-desk-react-root {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 1rem !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior-y: auto !important;
    background: #f8fafc !important;
    display: flex !important;
    flex-direction: column !important;
    scrollbar-width: thin !important;
}
main.main-content.rev-desk-react-root #root,
main.main-content.rev-desk-react-root .rev-desk-page {
    width: 100%;
    flex: 0 0 auto;
    min-height: 0 !important;
    max-height: none !important;
    height: auto !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: visible !important;
    overscroll-behavior: auto !important;
}
@media (max-width: 767.98px) {
    main.main-content.rev-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
body.page-rev-create main.main-content.rev-desk-react-root {
    overflow-y: auto !important;
    overscroll-behavior-y: contain !important;
    scrollbar-width: thin !important;
}
body.page-rev-create main.main-content.rev-desk-react-root #root {
    flex: 0 0 auto !important;
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
}
html[data-theme="dark"] body.page-rev-desk,
html[data-theme="dark"] body.page-rev-desk.dashboard,
html[data-theme="dark"] body.page-rev-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-rev-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-rev-desk main.main-content.rev-desk-react-root,
html[data-theme="dark"] body.page-rev-desk .employee-header.employee-header--rev-desk {
    background: #0b141a !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .employee-header-page-title {
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header.employee-header--rev-desk {
    border-bottom-color: #2a3942 !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .employee-header-menu-btn {
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-link,
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-sep,
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-current {
    color: #00a884 !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .rev-desk-breadcrumb .vv-breadcrumb-link:hover {
    color: #06cf9c !important;
}
html[data-theme="dark"] body.page-rev-create main.main-content.rev-desk-react-root {
    background: #0b141a !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar.theme-minimal {
    --sidebar-bg: #0b141a !important;
    --sidebar-text: #e9edef !important;
    --sidebar-hover-bg: #202c33 !important;
    --sidebar-hover-text: #e9edef !important;
    --sidebar-active-bg: rgba(0, 168, 132, 0.16) !important;
    --sidebar-active-text: #00a884 !important;
    --sidebar-border: #2a3942 !important;
    --sidebar-icon-color: #8696a0 !important;
    --sidebar-hover-icon: #e9edef !important;
    background: #0b141a !important;
    background-color: #0b141a !important;
    border-right: 1px solid #2a3942 !important;
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link {
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link i {
    color: #8696a0 !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link:hover {
    background: #202c33 !important;
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link.active,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link.active:hover,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .sidebar-revenue-link {
    background: rgba(0, 168, 132, 0.16) !important;
    color: #00a884 !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .nav-link.active i,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .sidebar-revenue-link i {
    color: #00a884 !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar .user-profile-link,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .user-profile-link .sidebar-text div,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .sidebar-logo,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .dropdown > a,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .sidebar-text {
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk #native-sidebar > hr,
html[data-theme="dark"] body.page-rev-desk #native-sidebar .sidebar-submenu {
    border-color: #2a3942 !important;
}
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-field {
    background: #111b21 !important;
    border-color: #2a3942 !important;
}
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-field:focus-within {
    border-color: #818cf8 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.22) !important;
}
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-icon {
    color: #8696a0 !important;
}
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-input,
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-input:focus {
    background: transparent !important;
    background-color: transparent !important;
    border-color: transparent !important;
    color: #e9edef !important;
}
html[data-theme="dark"] body.page-rev-desk .rev-desk-search-input::placeholder {
    color: #8696a0 !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header.employee-header--rev-desk .rev-desk-search-field input.rev-desk-search-input,
html[data-theme="dark"] body.page-rev-desk .employee-header.employee-header--rev-desk .rev-desk-search-field input.rev-desk-search-input:focus {
    background-color: transparent !important;
    border-color: transparent !important;
    color: #e9edef !important;
}
</style>
<script>document.documentElement.classList.add('page-rev-desk-lock');</script>
CSS;
    }
}
