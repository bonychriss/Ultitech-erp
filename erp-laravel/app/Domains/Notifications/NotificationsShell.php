<?php

declare(strict_types=1);

namespace App\Domains\Notifications;

/**
 * Notifications centre React shell (Vite dist under notifications-ui/frontend).
 * Page chrome matches create-voucher (slate canvas, Inter, header padding).
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

        $page = strtolower(trim((string) ($cfg['page'] ?? 'list'))) === 'settings' ? 'settings' : 'list';
        $payload = notificationsUiBuildPayload($page);
        $windowCfg = array_merge($payload, [
            'companySlug' => (string) ($cfg['companySlug'] ?? ''),
            'backUrl' => (string) ($cfg['backUrl'] ?? ''),
        ]);

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $windowCfg,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"page":"list","sections":{"today":[],"yesterday":[],"earlier":[]},"countUnread":0}';
        }

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->chromeCss() . "\n"
            . '<script>window.__NOTIFICATIONS_CFG__ = ' . $bootJson . ';</script>';

        $pageTitle = $page === 'settings' ? 'Notification settings' : 'Notifications';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-notifications-react page-notifications-wide page-erp-laravel'
                . ($page === 'settings' ? ' page-notifications-settings' : ''),
            'headMarkup' => $headMarkup,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => '',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--notifications',
            'mainRootClass' => 'nc-react-root',
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

    private function chromeCss(): string
    {
        return <<<'CSS'
<style>
:root { --bg-body: #f1f5f9; }
body.page-notifications-react.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
html, body.page-notifications-react.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
html::-webkit-scrollbar, body.page-notifications-react.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
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
    background: #f1f5f9 !important;
}
body.page-notifications-react .header,
body.page-notifications-react .employee-header,
body.page-notifications-react .employee-header.employee-header--notifications {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-notifications-react .employee-header--notifications .header-content {
    background: transparent !important;
    padding: 0.5rem 0 !important;
}
body.page-notifications-react .employee-header--notifications::after { display: none !important; }
main.main-content.nc-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 1.25rem 2rem !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    overflow: auto !important;
}
main.main-content.nc-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    main.main-content.nc-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-notifications-react,
html[data-theme="dark"] body.page-notifications-react.dashboard,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-notifications-react .employee-header,
html[data-theme="dark"] main.main-content.nc-react-root {
    background: #0f172a !important;
}
</style>
CSS;
    }
}
