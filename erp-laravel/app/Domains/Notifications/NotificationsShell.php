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
        $isAdminUser = function_exists('isAdmin') && isAdmin();
        $homeUrl = function_exists('company_url')
            ? company_url($isAdminUser ? 'admin/dashboard.php' : 'select-module.php')
            : '';
        $windowCfg = array_merge($payload, [
            'companySlug' => (string) ($cfg['companySlug'] ?? ''),
            'backUrl' => (string) ($cfg['backUrl'] ?? ''),
            'homeUrl' => (string) $homeUrl,
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
    main.main-content.nc-react-root { padding: 0 0.75rem 1.5rem !important; overflow: visible !important; }
}
html[data-theme="dark"] body.page-notifications-react,
html[data-theme="dark"] body.page-notifications-react.dashboard,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper,
html[data-theme="dark"] body.page-notifications-react .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-notifications-react .employee-header,
html[data-theme="dark"] main.main-content.nc-react-root {
    background: #0b141a !important;
}
html[data-theme="dark"] body.page-notifications-react .employee-header { border: 0 !important; box-shadow: none !important; }
html[data-theme="dark"] body.page-notifications-react #native-sidebar:not(#_) { background: #0b141a !important; border-right-color: #2a3942 !important; }
html[data-theme="dark"] body.page-notifications-react #native-sidebar .nav-link.active:not(#_) { background: rgba(99, 102, 241, 0.2) !important; color: #a5b4fc !important; }
html[data-theme="dark"] body.page-notifications-react #native-sidebar .nav-link.active:not(#_) i { background: transparent !important; color: #a5b4fc !important; }

html[data-theme="dark"] .ncr-shell:not(#_) { color: #e9edef; }
html[data-theme="dark"] .ncr-topbar:not(#_) { border-bottom-color: #2a3942 !important; }
html[data-theme="dark"] .ncr-title:not(#_) { color: #e9edef !important; }
html[data-theme="dark"] .ncr-section-heading:not(#_) { color: #8696a0 !important; }
html[data-theme="dark"] .ncr-crumbs a:not(#_) { color: #60a5fa !important; }
html[data-theme="dark"] .ncr-crumb-sep:not(#_) { color: #667781 !important; }
html[data-theme="dark"] .ncr-crumb-current:not(#_) { color: #00a884 !important; }
html[data-theme="dark"] .ncr-mark-all:not(#_) { background: transparent !important; color: #818cf8 !important; border: 0 !important; }
html[data-theme="dark"] .ncr-mark-all:not(#_):hover:not(:disabled):not(.is-disabled) { color: #a5b4fc !important; }
html[data-theme="dark"] .ncr-mark-all.is-disabled:not(#_) { color: #667781 !important; }
html[data-theme="dark"] .ncr-clear-read:not(#_) { background: transparent !important; border: 0 !important; color: #8696a0 !important; }
html[data-theme="dark"] .ncr-clear-read:not(#_):hover:not(:disabled) { color: #e9edef !important; }
html[data-theme="dark"] .ncr-settings:not(#_) { color: #8696a0 !important; }
html[data-theme="dark"] .ncr-settings:not(#_):hover { background: #202c33 !important; color: #e9edef !important; }

html[data-theme="dark"] .ncr-row:not(#_) { background: #111b21 !important; border-color: #2a3942 !important; box-shadow: none !important; }
html[data-theme="dark"] .ncr-row.is-unread:not(#_) { background: #142029 !important; border-left: 3px solid #818cf8 !important; }
html[data-theme="dark"] .ncr-row:not(#_):hover { background: #202c33 !important; border-color: #3b4a54 !important; }
html[data-theme="dark"] .ncr-row-title:not(#_),
html[data-theme="dark"] .ncr-msg-em:not(#_) { color: #e9edef !important; }
html[data-theme="dark"] .ncr-row-message:not(#_) { color: #8696a0 !important; }
html[data-theme="dark"] .ncr-row-time:not(#_) { color: #667781 !important; }
html[data-theme="dark"] .ncr-unread-dot:not(#_) { background: #818cf8 !important; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
html[data-theme="dark"] .ncr-dismiss:not(#_) { color: #667781 !important; }
html[data-theme="dark"] .ncr-dismiss:not(#_):hover { background: rgba(248, 113, 113, 0.12) !important; color: #f87171 !important; }
html[data-theme="dark"] .ncr-fan-card:not(#_) { background: #202c33 !important; border-color: #2a3942 !important; }
html[data-theme="dark"] .ncr-fan-badge:not(#_) { background: #111b21 !important; }

html[data-theme="dark"] .ncr-mod-badge:not(#_),
html[data-theme="dark"] .nc-r-icon:not(#_) { color: #94a3b8 !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-voucher) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-voucher:not(#_),
html[data-theme="dark"] .ncr-row:has(.nc-mod-website) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-website:not(#_),
html[data-theme="dark"] .ncr-row:has(.nc-mod-finance) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-finance:not(#_) { color: #2dd4bf !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-payroll) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-payroll:not(#_) { color: #60a5fa !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-sales) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-sales:not(#_) { color: #4ade80 !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-stock) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-stock:not(#_) { color: #a5b4fc !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-deliveries) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-deliveries:not(#_) { color: #38bdf8 !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-driver_kpi) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-driver_kpi:not(#_) { color: #22d3ee !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-attendance) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-attendance:not(#_) { color: #fb923c !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-letter) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-letter:not(#_),
html[data-theme="dark"] .ncr-row:has(.nc-mod-suggest) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-suggest:not(#_) { color: #fbbf24 !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-tasks) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-tasks:not(#_) { color: #fb7185 !important; }
html[data-theme="dark"] .ncr-row:has(.nc-mod-account) .ncr-mod-badge:not(#_), html[data-theme="dark"] .nc-r-icon.nc-mod-account:not(#_) { color: #a5b4fc !important; }
html[data-theme="dark"] .nc-r-icon.nc-status-alert:not(#_) { color: #fb7185 !important; }

html[data-theme="dark"] .ncr-empty:not(#_) { color: #8696a0; }
html[data-theme="dark"] .ncr-empty-icon:not(#_) { background: #202c33 !important; color: #8696a0 !important; }
html[data-theme="dark"] .ncr-empty-title:not(#_) { color: #e9edef !important; }

html[data-theme="dark"] .ncr-settings-sub:not(#_),
html[data-theme="dark"] .ncr-settings-card-sub:not(#_),
html[data-theme="dark"] .ncr-settings-module-desc:not(#_) { color: #8696a0 !important; }
html[data-theme="dark"] .ncr-settings-card:not(#_) { background: #111b21 !important; border-color: #2a3942 !important; box-shadow: none !important; }
html[data-theme="dark"] .ncr-settings-card-title:not(#_),
html[data-theme="dark"] .ncr-settings-module-label:not(#_),
html[data-theme="dark"] .ncr-settings-module-name:not(#_) { color: #e9edef !important; }
html[data-theme="dark"] .ncr-settings-module:not(#_) { border-top-color: #2a3942 !important; }
html[data-theme="dark"] .ncr-settings-col-name:not(#_),
html[data-theme="dark"] .ncr-settings-col-desc:not(#_),
html[data-theme="dark"] .ncr-settings-col-toggle:not(#_) { color: #667781 !important; }
html[data-theme="dark"] .ncr-settings-card-icon:not(#_) { background: #202c33 !important; color: #a5b4fc !important; }
html[data-theme="dark"] .ncr-switch:not(#_) { background: #3b4a54 !important; }
html[data-theme="dark"] .ncr-switch.is-on:not(#_) { background: #6366f1 !important; }
html[data-theme="dark"] .ncr-switch:not(#_):focus-visible { outline-color: #818cf8 !important; }
html[data-theme="dark"] .ncr-save-status.is-saved:not(#_) { color: #00a884 !important; }
html[data-theme="dark"] .ncr-settings-error:not(#_) { background: rgba(248, 113, 113, 0.12) !important; color: #fca5a5 !important; }
</style>
CSS;
    }
}
