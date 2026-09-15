<?php

namespace App\Domains\Admin;

/**
 * Pack React assets from admin/*-settings-ui into Blade erp.react-shell.
 */
final class AdminShell
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
    public function emailSettings(array $cfg = []): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/admin/email-settings-ui/lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $assets = emailSettingsUiLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $apiBase = (string) ($cfg['apiBase'] ?? emailSettingsUiPublicUrl('api'));
        $initial = $cfg['initial'] ?? null;
        if (!is_array($initial)) {
            try {
                $pdo = emailSettingsUiPdo();
                $initial = emailSettingsUiGetPayload($pdo);
            } catch (\Throwable $e) {
                $initial = ['form' => new \stdClass(), 'links' => new \stdClass(), 'meta' => new \stdClass()];
            }
        }

        $windowCfg = [
            'apiBase' => $apiBase,
            'module' => 'settings',
            'engine' => 'erp-laravel Domains/Admin + admin/email-settings-ui',
            'companySlug' => (string) ($cfg['companySlug'] ?? ''),
            'initial' => $initial,
        ];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $head = emailSettingsUiShellHeadExtras() . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<script>window.__EMAIL_SETTINGS_CFG__ = '
            . json_encode($windowCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>';

        return [
            'pageTitle' => 'Email Configuration',
            'bodyClass' => 'page-exp-desk page-email-settings page-admin-laravel page-erp-laravel',
            'headMarkup' => $head,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => 'Email Configuration',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--exp-desk',
            'mainRootClass' => 'exp-desk-react-root',
        ];
    }

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
    public function whatsappSettings(array $cfg = []): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/admin/whatsapp-settings-ui/lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $assets = whatsappSettingsUiLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $apiBase = (string) ($cfg['apiBase'] ?? whatsappSettingsUiPublicUrl('api'));
        $initial = $cfg['initial'] ?? null;
        if (!is_array($initial)) {
            try {
                $pdo = whatsappSettingsUiPdo();
                $initial = whatsappSettingsUiGetPayload($pdo);
            } catch (\Throwable $e) {
                $initial = ['form' => new \stdClass(), 'links' => new \stdClass(), 'meta' => new \stdClass()];
            }
        }

        $windowCfg = [
            'apiBase' => $apiBase,
            'module' => 'settings',
            'engine' => 'erp-laravel Domains/Admin + admin/whatsapp-settings-ui',
            'companySlug' => (string) ($cfg['companySlug'] ?? ''),
            'initial' => $initial,
        ];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $head = whatsappSettingsUiShellHeadExtras() . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<script>window.__WHATSAPP_SETTINGS_CFG__ = '
            . json_encode($windowCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ';</script>';

        return [
            'pageTitle' => 'WhatsApp Configuration',
            'bodyClass' => 'page-exp-desk page-whatsapp-settings page-admin-laravel page-erp-laravel',
            'headMarkup' => $head,
            'footerScripts' => '<style>
body.page-whatsapp-settings,
body.page-whatsapp-settings.dashboard,
body.page-whatsapp-settings .layout-main-wrapper,
body.page-whatsapp-settings .layout-main-wrapper > .flex-grow-1,
body.page-whatsapp-settings .employee-header.employee-header--exp-desk,
body.page-whatsapp-settings main.main-content,
body.page-whatsapp-settings main.main-content.exp-desk-react-root,
body.page-whatsapp-settings main.main-content.exp-desk-react-root #root {
    background: #f9fafb !important;
    background-color: #f9fafb !important;
    box-shadow: none !important;
}
body.page-whatsapp-settings .wa-page,
body.page-whatsapp-settings .wizard {
    background: transparent !important;
    box-shadow: none !important;
    border-color: transparent !important;
}
</style>
<script type="module" crossorigin src="'
                . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => 'WhatsApp Configuration',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--exp-desk',
            'mainRootClass' => 'exp-desk-react-root',
        ];
    }
}
