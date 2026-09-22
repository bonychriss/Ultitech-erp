<?php

declare(strict_types=1);

namespace App\Domains\DriverKpi;

/**
 * Driver KPI React shell: reuses deliveries Vite dist + ERP Blade chrome.
 * Legacy JSON API stays under driver-kpi/api.
 */
final class DriverKpiShell
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
     *   hideSidebar:bool,
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

        $erp = is_array($cfg['erp'] ?? null) ? $cfg['erp'] : [];
        $slug = (string) ($erp['company_slug'] ?? ($cfg['companySlug'] ?? ''));
        $service = $this->normalizeService((string) ($cfg['service'] ?? 'delivery'));
        $weekOffset = (int) ($cfg['weekOffset'] ?? 0);
        $selectedUserId = (int) ($cfg['userId'] ?? 0);

        $boot = $this->buildBoot($erp, $slug, $service, $weekOffset, $selectedUserId, $assets);

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"page":"driver-kpi","data":{}}';
        }

        $recordTitle = (string) ($boot['data']['serviceMeta']['record_title'] ?? 'Driver KPI');

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss() . "\n"
            . '<script>window.__DELIVERIES_CFG__ = ' . $bootJson . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $recordTitle,
            'bodyClass' => 'page-products-desk page-dkpi page-dkpi-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $recordTitle,
            'hideHeaderCompanyBranding' => true,
            'hideSidebar' => false,
            'employeeHeaderExtraClass' => 'employee-header--dkpi',
            'mainRootClass' => 'dkpi-react-root',
        ];
    }

    /**
     * @param array<string,mixed> $erp
     * @param array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl?:string} $assets
     * @return array<string,mixed>
     */
    private function buildBoot(array $erp, string $slug, string $service, int $weekOffset, int $selectedUserId, array $assets): array
    {
        $this->loadHelpers();

        $viewerId = (int) ($erp['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $viewerName = (string) ($erp['full_name'] ?? ($_SESSION['full_name'] ?? 'User'));
        $viewerRole = strtolower(trim((string) ($_SESSION['role'] ?? 'employee')));
        $isAdmin = in_array($viewerRole, ['admin', 'administrator', 'superadmin', 'super_admin', 'company_admin'], true)
            || (function_exists('isAdmin') && isAdmin());
        $viewerDepartment = trim((string) ($_SESSION['department'] ?? ($erp['department'] ?? '')));

        $bounds = function_exists('dkpi_week_bounds') ? dkpi_week_bounds() : [
            'week_start' => date('Y-m-d'),
            'week_end' => date('Y-m-d'),
        ];
        if ($weekOffset !== 0 && function_exists('dkpi_shift_week')) {
            $bounds = dkpi_shift_week((string) $bounds['week_start'], $weekOffset);
        }

        $services = function_exists('dkpi_services') ? dkpi_services() : [];
        $serviceMeta = $services[$service] ?? [
            'key' => $service,
            'label' => ucfirst($service),
            'record_title' => 'Driver KPI',
            'lede' => '',
            'description' => '',
        ];
        $metrics = function_exists('dkpi_metrics') ? array_values(dkpi_metrics($service)) : [];

        global $pdo;
        $employees = [];
        $entry = null;
        $weekRows = [];
        $viewerIsDriver = function_exists('dkpi_is_driver_department')
            ? dkpi_is_driver_department($viewerDepartment)
            : false;
        if ($pdo instanceof \PDO && function_exists('dkpi_ensure_tables')) {
            dkpi_ensure_tables($pdo);
            if ($viewerDepartment === '' && function_exists('dkpi_user_department')) {
                $viewerDepartment = dkpi_user_department($pdo, $viewerId);
                $viewerIsDriver = function_exists('dkpi_is_driver_department')
                    ? dkpi_is_driver_department($viewerDepartment)
                    : false;
            } elseif (function_exists('dkpi_user_is_driver')) {
                $viewerIsDriver = dkpi_user_is_driver($pdo, $viewerId);
                if ($viewerDepartment === '') {
                    $viewerDepartment = dkpi_user_department($pdo, $viewerId);
                }
            }
            if ($isAdmin && function_exists('dkpi_list_employees')) {
                $employees = dkpi_list_employees($pdo);
            }
            $uid = $isAdmin && $selectedUserId > 0 ? $selectedUserId : $viewerId;
            if ($isAdmin) {
                $driverIds = array_map(static fn ($e) => (int) ($e['id'] ?? 0), $employees);
                if ($uid <= 0 || ($driverIds !== [] && !in_array($uid, $driverIds, true))) {
                    $uid = (int) ($employees[0]['id'] ?? $viewerId);
                }
            } elseif (!$viewerIsDriver) {
                $uid = $viewerId;
            }
            if ($uid <= 0) {
                $uid = $viewerId;
            }
            $selectedUserId = $uid;
            if (function_exists('dkpi_get_entry') && ($isAdmin || $viewerIsDriver)) {
                $entry = dkpi_get_entry($pdo, $uid, (string) $bounds['week_start'], $service);
            }
            if (function_exists('dkpi_list_entries')) {
                $weekRows = dkpi_list_entries(
                    $pdo,
                    (string) $bounds['week_start'],
                    $isAdmin ? null : $viewerId,
                    $service
                );
            }
        } else {
            $selectedUserId = $viewerId;
        }

        $apiUrl = $assets['apiUrl'] ?? $this->companyPath('driver-kpi/api/index.php', $slug);

        return [
            'page' => 'driver-kpi',
            'apiUrl' => $apiUrl,
            'data' => [
                'companyName' => (string) ($erp['company_name'] ?? ''),
                'viewer' => [
                    'id' => $viewerId,
                    'name' => $viewerName,
                    'isAdmin' => $isAdmin,
                    'department' => $viewerDepartment,
                    'isDriver' => $viewerIsDriver,
                ],
                'service' => $service,
                'serviceMeta' => $serviceMeta,
                'services' => array_values($services),
                'metrics' => $metrics,
                'weekOffset' => $weekOffset,
                'week' => $bounds,
                'selectedUserId' => $selectedUserId,
                'employees' => $employees,
                'entry' => $entry,
                'entries' => $weekRows,
                'urls' => [
                    'modules' => $this->companyPath('select-module', $slug),
                    'hub' => $this->companyPath('deliveries/hub', $slug, ['module' => 'deliveries']),
                    'dashboard' => $this->companyPath('deliveries/index', $slug, ['module' => 'deliveries']),
                    'self' => $this->companyPath('driver-kpi/index', $slug, ['module' => 'driver_kpi']),
                    'performance' => $this->companyPath('weekly_tasks/my_progress.php', $slug, [
                        'module' => 'tasks',
                        'week_offset' => $weekOffset,
                    ]),
                    'api' => $apiUrl,
                ],
            ],
        ];
    }

    private function loadHelpers(): void
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $wm = $root . '/todo/includes/weekly_mission_helpers.php';
        if (is_file($wm)) {
            require_once $wm;
        }
        $helpers = $root . '/driver-kpi/includes/driver_kpi_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    private function normalizeService(string $service): string
    {
        $this->loadHelpers();
        if (function_exists('dkpi_normalize_service')) {
            return dkpi_normalize_service($service);
        }
        $key = strtolower(trim($service));
        return in_array($key, ['delivery', 'ride'], true) ? $key : 'delivery';
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl?:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $legacyLib = $root . '/deliveries/deliveries-ui/lib.php';
        if (is_file($legacyLib)) {
            require_once $legacyLib;
            if (function_exists('deliveriesUiLoadReactAssets')) {
                $assets = deliveriesUiLoadReactAssets();
                if (is_array($assets)) {
                    $slug = (string) ($GLOBALS['ERP_CONTEXT']['company_slug'] ?? '');
                    $assets['apiUrl'] = $this->companyPath('driver-kpi/api/index.php', $slug);
                    return $assets;
                }
            }
        }

        $distRel = (string) (config('erp.react_dist.deliveries') ?: 'deliveries/deliveries-ui/frontend/dist');
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
        $jsPath = $root . '/' . $distRel . '/assets/' . $jsFile;
        $cssPath = $root . '/' . $distRel . '/assets/' . $cssFile;
        if (!is_file($jsPath) || !is_file($cssPath)) {
            return null;
        }

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/' . $distRel . '/assets/'), '/') . '/'
            : '/public_html/' . $distRel . '/assets/';

        $slug = (string) ($GLOBALS['ERP_CONTEXT']['company_slug'] ?? '');

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => (string) filemtime($cssPath),
            'jsVersion' => (string) filemtime($jsPath),
            'apiUrl' => $this->companyPath('driver-kpi/api/index.php', $slug),
        ];
    }

    /**
     * @param array<string,scalar> $query
     */
    private function companyPath(string $path, string $slug, array $query = []): string
    {
        if ($slug !== '' && function_exists('company_url')) {
            $url = company_url($path, $slug);
        } elseif (function_exists('app_url')) {
            $url = app_url('/' . ltrim($path, '/'));
        } else {
            $url = '/' . ltrim($path, '/');
        }
        if ($slug !== '' && !isset($query['company_slug'])) {
            $query['company_slug'] = $slug;
        }
        if ($query === []) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
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
        return <<<CSS
<style>
body.page-dkpi.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
    background: #f8fafc !important;
}
body.page-dkpi,
body.page-dkpi.dashboard,
body.page-dkpi .layout-main-wrapper,
body.page-dkpi .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-dkpi .employee-header.employee-header--dkpi {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
}
body.page-dkpi .employee-header--dkpi::after { display: none !important; }
body.page-dkpi .employee-header--dkpi .header-content {
    display: flex !important;
    align-items: center !important;
    padding: 0.75rem 0 0.35rem !important;
    width: 100%;
    background: transparent !important;
}
body.page-dkpi .employee-header--dkpi .employee-header-page-title {
    font-size: 1.125rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
main.main-content.dkpi-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: auto !important;
    background: #f8fafc !important;
}
main.main-content.dkpi-react-root #root {
    width: 100%;
    min-height: 40vh;
}
body.page-dkpi .dkpi-btn.dkpi-btn--pill-purple {
    border-radius: 9999px !important;
    height: 40px !important;
    min-height: 40px !important;
    padding: 0 22px !important;
    background: #7c3aed !important;
    border: 1px solid #7c3aed !important;
    color: #fff !important;
}
</style>
CSS;
    }
}
