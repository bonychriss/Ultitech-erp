<?php

namespace App\Domains\Payroll;

/**
 * Payroll React shell: Vite dist under modules/payroll/frontend + ERP Blade chrome.
 */
final class PayrollShell
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
        $payrollPage = (string) ($cfg['payrollPage'] ?? 'dashboard');
        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Payroll');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Payroll');

        $head = $this->commonHeadExtras();
        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $pageBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/payroll'), '/')
            : '/modules/payroll';
        if (function_exists('company_url')) {
            $slug = trim((string) ($cfg['companySlug'] ?? ''));
            if ($slug !== '') {
                $pageBase = rtrim((string) company_url('modules/payroll', $slug), '/');
            }
        }

        $windowScript = 'window.__PAYROLL_API_BASE__ = ' . json_encode($apiBase, JSON_UNESCAPED_SLASHES)
            . ';window.__PAYROLL_PAGE_BASE__ = ' . json_encode($pageBase, JSON_UNESCAPED_SLASHES)
            . ';window.__PAYROLL_PAGE__ = ' . json_encode($payrollPage, JSON_UNESCAPED_SLASHES)
            . ';window.__PAYROLL_CFG__ = ' . json_encode([
                'module' => 'payroll',
                'engine' => 'erp-laravel Domains/Payroll',
                'companySlug' => (string) ($cfg['companySlug'] ?? ''),
                'backUrl' => (string) ($cfg['backUrl'] ?? ''),
                'pageBase' => $pageBase,
                'apiBase' => $apiBase,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $payslipId = (int) ($cfg['payslipId'] ?? 0);
        if ($payslipId > 0) {
            $windowScript .= ';window.__PAYROLL_PAYSLIP_ID__ = ' . json_encode($payslipId, JSON_UNESCAPED_SLASHES);
        }

        $payslipMeta = $cfg['payslipMeta'] ?? null;
        if (is_array($payslipMeta) && $payslipMeta !== []) {
            $windowScript .= ';window.__PAYROLL_PAYSLIP_META__ = '
                . json_encode($payslipMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $employeeId = (int) ($cfg['employeeId'] ?? 0);
        if ($employeeId > 0) {
            $windowScript .= ';window.__PAYROLL_EMPLOYEE_ID__ = ' . json_encode($employeeId, JSON_UNESCAPED_SLASHES);
        }

        $runId = (int) ($cfg['runId'] ?? 0);
        if ($runId > 0) {
            $windowScript .= ';window.__PAYROLL_RUN_ID__ = ' . json_encode($runId, JSON_UNESCAPED_SLASHES);
        }

        $headMarkup = $head
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->payDeskChromeCss() . "\n"
            . '<script>' . $windowScript . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-pay-desk pay-dashboard-page page-payroll-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--pay-desk',
            'mainRootClass' => 'pay-desk-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.payroll') ?: 'modules/payroll/frontend/dist');
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

    private function payDeskChromeCss(): string
    {
        return <<<'CSS'
<style>
body.page-pay-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-pay-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-pay-desk,
body.page-pay-desk.dashboard,
body.page-pay-desk .layout-main-wrapper,
body.page-pay-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-pay-desk .employee-header.employee-header--pay-desk {
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
body.page-pay-desk .employee-header--pay-desk::after { display: none !important; }
body.page-pay-desk .employee-header--pay-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-pay-desk .employee-header--pay-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-pay-desk .employee-header--pay-desk .employee-header-page-title {
    font-size: clamp(1.25rem, 2.2vw, 1.625rem) !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    letter-spacing: -0.025em;
    white-space: nowrap;
}
body.page-pay-desk .employee-header--pay-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.pay-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.pay-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
body.page-pay-desk .pay-run-table th,
body.page-pay-desk .pay-run-table td,
body.page-pay-desk .pay-desk-table th.pay-desk-hide-lg,
body.page-pay-desk .pay-desk-table td.pay-desk-hide-lg,
body.page-pay-desk .pay-desk-table th.pay-desk-hide-md,
body.page-pay-desk .pay-desk-table td.pay-desk-hide-md {
    display: table-cell !important;
    visibility: visible !important;
}
body.page-pay-desk .pay-run-register {
    min-width: 1680px !important;
    width: max-content !important;
    table-layout: auto !important;
}
@media (max-width: 767.98px) {
    body.page-pay-desk .employee-header.employee-header--pay-desk {
        padding: 0 0.75rem !important;
    }
    main.main-content.pay-desk-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-pay-desk,
html[data-theme="dark"] body.page-pay-desk.dashboard,
html[data-theme="dark"] body.page-pay-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-pay-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-pay-desk main.main-content.pay-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-pay-desk .employee-header.employee-header--pay-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-pay-desk .employee-header--pay-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }
}
