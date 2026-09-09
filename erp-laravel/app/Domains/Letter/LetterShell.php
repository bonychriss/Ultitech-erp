<?php

namespace App\Domains\Letter;

/**
 * Letter React shell: Vite dist under modules/letter/frontend + ERP Blade chrome.
 */
final class LetterShell
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

        $letterPage = (string) ($cfg['letterPage'] ?? 'compose');
        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Letter');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Letter');
        $clientCfg = is_array($cfg['clientCfg'] ?? null) ? $cfg['clientCfg'] : [];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $windowScript = 'window.__LETTER_PAGE__ = ' . json_encode($letterPage, JSON_UNESCAPED_SLASHES)
            . ';window.__LETTER_CFG__ = ' . json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Open+Sans:wght@400;600&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss() . "\n"
            . '<script>' . $windowScript . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-letter-desk page-letter-laravel page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => 'employee-header--letter-desk',
            'mainRootClass' => 'letter-desk-react-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.letter') ?: 'modules/letter/frontend/dist');
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
body.page-letter-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-letter-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-letter-desk,
body.page-letter-desk.dashboard,
body.page-letter-desk .layout-main-wrapper,
body.page-letter-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
body.page-letter-desk .employee-header.employee-header--letter-desk {
    background: #f1f5f9 !important;
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
body.page-letter-desk .employee-header--letter-desk::after { display: none !important; }
body.page-letter-desk .employee-header--letter-desk .header-content {
    display: flex !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    width: 100%;
    gap: 0.5rem 1rem;
}
body.page-letter-desk .employee-header--letter-desk .employee-header-page-title {
    font-size: clamp(1.25rem, 2.2vw, 1.625rem) !important;
    font-weight: 800 !important;
    color: #0f172a !important;
}
main.main-content.letter-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
}
main.main-content.letter-desk-react-root #root {
    width: 100%;
    margin: 0;
    min-height: 320px;
}
@media print {
    body.page-letter-desk .sidebar,
    body.page-letter-desk .employee-header,
    body.page-letter-desk .letter-composer-sidebar,
    body.page-letter-desk .letter-toolbar {
        display: none !important;
    }
    body.page-letter-desk,
    body.page-letter-desk .layout-main-wrapper,
    body.page-letter-desk main.main-content.letter-desk-react-root {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>
CSS;
    }
}
