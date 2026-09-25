<?php

declare(strict_types=1);

namespace App\Domains\Notifications;

/**
 * Notifications centre React shell (Vite dist under notifications-ui/frontend).
 */
final class NotificationsShell
{
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
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/notifications-ui/lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $assets = notificationsUiLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $payload = notificationsUiBuildPayload();
        $windowCfg = array_merge($payload, [
            'companySlug' => (string) ($cfg['companySlug'] ?? ''),
            'backUrl' => (string) ($cfg['backUrl'] ?? ''),
        ]);

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $windowCfg,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"sections":{"today":[],"yesterday":[],"earlier":[]},"countUnread":0}';
        }

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->chromeCss() . "\n"
            . '<script>window.__NOTIFICATIONS_CFG__ = ' . $bootJson . ';</script>';

        return [
            'pageTitle' => 'Notifications',
            'bodyClass' => 'page-products-desk page-notifications-react page-notifications-wide page-erp-laravel has-mobile-footer',
            'headMarkup' => $headMarkup,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => null,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--products-desk',
            'mainRootClass' => 'nc-react-root',
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

    private function chromeCss(): string
    {
        return <<<'CSS'
<style>
body.page-notifications-react.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-notifications-react.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-notifications-react,
body.page-notifications-react.dashboard,
body.page-notifications-react .layout-main-wrapper,
body.page-notifications-react .layout-main-wrapper > .flex-grow-1 {
    background: #f3f4f6 !important;
}
body.page-notifications-react .employee-header.employee-header--products-desk {
    background: #f3f4f6 !important;
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
body.page-notifications-react .employee-header--products-desk::after { display: none !important; }
main.main-content.nc-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: transparent !important;
}
main.main-content.nc-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 991.98px) {
    body.page-notifications-react .employee-header.employee-header--products-desk {
        background: transparent !important;
    }
}
html[data-theme="dark"] body.page-notifications-react,
html[data-theme="dark"] body.page-notifications-react.dashboard,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-notifications-react main.main-content.nc-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-notifications-react .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
</style>
CSS;
    }
}
