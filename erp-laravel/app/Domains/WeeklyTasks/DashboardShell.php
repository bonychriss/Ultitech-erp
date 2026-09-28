<?php

declare(strict_types=1);

namespace App\Domains\WeeklyTasks;

/**
 * Weekly tasks dashboard React shell (Vite dist under weekly-tasks-ui/frontend).
 */
final class DashboardShell
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
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/weekly-tasks-ui/lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $assets = weeklyTasksUiLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $payload = weeklyTasksUiBuildPayload();
        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{}';
        }

        $headMarkup = $this->commonHeadExtras()
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->chromeCss() . "\n"
            . '<script>window.__WEEKLY_TASKS_CFG__ = ' . $bootJson . ';</script>';

        return [
            'pageTitle' => 'Weekly tasks',
            'bodyClass' => 'page-exp-desk page-weekly-tasks-react page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => '<script type="module" crossorigin src="'
                . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
                . '"></script>',
            'employeeHeaderTitle' => '',
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--exp-desk',
            'mainRootClass' => 'wt-react-root exp-desk-react-root',
        ];
    }

    private function commonHeadExtras(): string
    {
        $parts = [];
        if (function_exists('app_url')) {
            $erpStylePath = rtrim((string) config('erp.app_root'), '\\/') . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
            if (function_exists('erp_dark_theme_css_url')) {
                $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        return $parts === [] ? '' : implode("\n", $parts) . "\n";
    }

    private function chromeCss(): string
    {
        return <<<'CSS'
<style>
body.page-weekly-tasks-react.dashboard { background: #f1f5f9; }
main.main-content.wt-react-root { padding: 0 1.25rem 2rem !important; }
</style>
CSS;
    }
}
