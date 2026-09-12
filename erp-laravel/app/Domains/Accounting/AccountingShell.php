<?php

namespace App\Domains\Accounting;

/**
 * Accounting React shell: Vite dist under modules/accounting/frontend + ERP Blade chrome.
 */
final class AccountingShell
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

        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Accounting');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Accounting');
        $companySlug = trim((string) ($cfg['companySlug'] ?? ''));
        $sections = $cfg['sections'] ?? Nav::sections($companySlug);
        if (!is_array($sections)) {
            $sections = Nav::sections($companySlug);
        }

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $boot = [
            'module' => 'accounting',
            'engine' => 'erp-laravel Domains/Accounting',
            'companySlug' => $companySlug,
            'backUrl' => (string) ($cfg['backUrl'] ?? ''),
            'sections' => array_values($sections),
        ];

        $bootJson = json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $windowScript = 'window.__ACCOUNTING_CFG__ = ' . $bootJson . ';';

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss() . "\n"
            . '<script type="application/json" id="accounting-boot-config">' . $bootJson . '</script>' . "\n"
            . '<script>' . $windowScript . '</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-accounting-desk accounting-page page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--accounting-desk',
            'mainRootClass' => 'accounting-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.accounting') ?: 'modules/accounting/frontend/dist');
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
body.page-accounting-desk .employee-header.employee-header--accounting-desk {
    display: none !important;
}
body.page-accounting-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-accounting-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-accounting-desk,
body.page-accounting-desk.dashboard,
body.page-accounting-desk .layout-main-wrapper,
body.page-accounting-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-accounting-desk .employee-header.employee-header--accounting-desk {
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
body.page-accounting-desk .employee-header--accounting-desk::after { display: none !important; }
body.page-accounting-desk .employee-header--accounting-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-accounting-desk .employee-header--accounting-desk .employee-header-page-title {
    font-size: clamp(1.25rem, 2.2vw, 1.625rem) !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    letter-spacing: -0.025em;
}
main.main-content.accounting-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.accounting-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-accounting-desk .employee-header.employee-header--accounting-desk {
        padding: 0 0.75rem !important;
    }
    main.main-content.accounting-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-accounting-desk,
html[data-theme="dark"] body.page-accounting-desk.dashboard,
html[data-theme="dark"] body.page-accounting-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-accounting-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-accounting-desk main.main-content.accounting-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-accounting-desk .employee-header.employee-header--accounting-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-accounting-desk .employee-header--accounting-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }
}
