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
body.page-rev-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-rev-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
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
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
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
body.page-rev-desk .employee-header--rev-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.rev-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    background: #f8fafc !important;
}
main.main-content.rev-desk-react-root #root {
    width: 100%;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    main.main-content.rev-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-rev-desk,
html[data-theme="dark"] body.page-rev-desk.dashboard,
html[data-theme="dark"] body.page-rev-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-rev-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-rev-desk main.main-content.rev-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header.employee-header--rev-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-rev-desk .employee-header--rev-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }
}
