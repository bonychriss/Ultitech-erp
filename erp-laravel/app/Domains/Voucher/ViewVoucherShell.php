<?php

namespace App\Domains\Voucher;

/**
 * View Payment Voucher React shell: Vite dist under view-voucher-ui + ERP Blade chrome.
 */
final class ViewVoucherShell
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

        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Payment Voucher');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Voucher Preview');
        $clientCfg = is_array($cfg['clientCfg'] ?? null) ? $cfg['clientCfg'] : [];
        $headerSubtitle = (string) ($cfg['headerSubtitle'] ?? '');
        $headerRightHtml = (string) ($cfg['headerRightHtml'] ?? '');

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $windowScript = 'window.__VV_CFG__ = ' . json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $vvCssV = (string) time();
        $extraCss = '';
        if (function_exists('app_url')) {
            $extraCss .= '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/download-button.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $vvCssV . '">' . "\n";
            $extraCss .= '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/voucher-view-page.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $vvCssV . '">' . "\n";
            $extraCss .= '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/approval-flow.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $vvCssV . '">' . "\n";
        }

        $approvalStyles = '';
        $approvalFile = $root . '/includes/approval-flow-styles.php';
        if (is_file($approvalFile)) {
            ob_start();
            require $approvalFile;
            $approvalStyles = (string) ob_get_clean();
        }
        $actionStyles = '';
        $actionFile = $root . '/includes/voucher-view-actions-styles.php';
        if (is_file($actionFile)) {
            ob_start();
            require $actionFile;
            $actionStyles = (string) ob_get_clean();
        }

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . $extraCss
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $approvalStyles
            . $actionStyles
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>' . "\n"
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>' . "\n"
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>' . "\n"
            . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>' . "\n"
            . '<script>' . $windowScript . ';</script>' . "\n"
            . $this->deskChromeCss();

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'vv-view-voucher-page vv-actions-in-header page-view-voucher page-voucher-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'employeeHeaderSubtitle' => $headerSubtitle,
            'employeeHeaderRightHtml' => $headerRightHtml,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--view-voucher',
            'mainRootClass' => 'vv-react-shell view-voucher-react-root',
            'active_module' => 'voucher',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.view_voucher') ?: 'view-voucher-ui/frontend/dist');
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
:root { --vv-page-bg: #ffffff; }
body.vv-view-voucher-page.dashboard { background: var(--vv-page-bg) !important; font-family: 'Inter', system-ui, sans-serif; }
body.vv-view-voucher-page .layout-main-wrapper,
body.vv-view-voucher-page .layout-main-wrapper > .flex-grow-1 {
    background: var(--vv-page-bg) !important;
}
body.vv-view-voucher-page .header,
body.vv-view-voucher-page .employee-header,
body.vv-view-voucher-page .admin-header {
    background: var(--vv-page-bg) !important;
    border: none !important;
    box-shadow: none !important;
}
main.main-content.vv-react-shell,
main.main-content.view-voucher-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0.25rem 1.25rem 2rem !important;
    box-sizing: border-box;
    background: var(--vv-page-bg) !important;
}
main.main-content.vv-react-shell #root { width: 100%; max-width: none; margin: 0; }
@media (max-width: 767.98px) {
    main.main-content.vv-react-shell { padding: 0.5rem 0.75rem 1.5rem !important; }
}
</style>
CSS;
    }
}
