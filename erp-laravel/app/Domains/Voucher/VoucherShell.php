<?php

namespace App\Domains\Voucher;

/**
 * Create Payment Voucher React shell: Vite dist under employee/create-voucher-ui + ERP Blade chrome.
 */
final class VoucherShell
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

        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Create Voucher');
        $headerTitle = (string) ($cfg['headerTitle'] ?? '');
        $clientCfg = is_array($cfg['clientCfg'] ?? null) ? $cfg['clientCfg'] : [];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $windowScript = 'window.__CV_CFG__ = ' . json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<script>' . $windowScript . ';</script>' . "\n"
            . $this->deskChromeCss();

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-create-voucher page-voucher-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--create-voucher',
            'mainRootClass' => 'create-voucher-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.voucher') ?: 'employee/create-voucher-ui/frontend/dist');
        $distIndex = $root . '/' . $distRel . '/index.html';
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
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">',
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
:root { --bg-body: #f1f5f9; }
body.page-create-voucher.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
html, body.page-create-voucher.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
html::-webkit-scrollbar, body.page-create-voucher.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
body.page-create-voucher.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-create-voucher.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-create-voucher,
body.page-create-voucher.dashboard,
body.page-create-voucher .layout-main-wrapper,
body.page-create-voucher .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
body.page-create-voucher .header,
body.page-create-voucher .employee-header,
body.page-create-voucher .employee-header.employee-header--create-voucher {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-create-voucher .employee-header--create-voucher .header-content {
    background: transparent !important;
    padding: 0.5rem 0 !important;
}
main.main-content.create-voucher-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    overflow: auto !important;
}
main.main-content.create-voucher-react-root #root { width: 100%; max-width: none; margin: 0; }
@media (max-width: 767.98px) {
    main.main-content.create-voucher-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-create-voucher,
html[data-theme="dark"] body.page-create-voucher.dashboard,
html[data-theme="dark"] body.page-create-voucher .layout-main-wrapper,
html[data-theme="dark"] body.page-create-voucher .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-create-voucher .employee-header,
html[data-theme="dark"] main.main-content.create-voucher-react-root {
    background: #0f172a !important;
}
</style>
CSS;
    }
}
