<?php

namespace App\Domains\Accounting;

/**
 * Host UltiTech Accounting React dist inside ERP chrome (native sidebar).
 */
final class UltitechAccountingShell
{
    /**
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $pageTitle = (string) ($cfg['pageTitle'] ?? 'Accounting');
        $headerTitle = (string) ($cfg['headerTitle'] ?? 'Accounting');
        $boot = is_array($cfg['boot'] ?? null) ? $cfg['boot'] : [];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $windowScript = 'window.__ULTITECH_ACCOUNTING_CFG__ = ' . $bootJson . ';';

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $this->deskChromeCss() . "\n"
            . '<script type="application/json" id="ultitech-accounting-boot">' . $bootJson . '</script>' . "\n"
            . '<script>' . $windowScript . '</script>';

        $footerScripts = $this->sidebarHashActiveScript()
            . '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'page-accounting-desk page-ultitech-accounting accounting-page page-erp-laravel',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'hideSidebar' => false,
            'active_module' => 'accounting',
            'employeeHeaderExtraClass' => 'employee-header--accounting-desk',
            'mainRootClass' => 'accounting-react-root ultitech-accounting-root',
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distRel = (string) (config('erp.react_dist.ultitech_accounting') ?: 'ultitech-accounting/frontend/dist');
        $distIndex = $root . '/' . $distRel . '/index.html';
        if (! is_file($distIndex)) {
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

    private function sidebarHashActiveScript(): string
    {
        return <<<'JS'
<script>
(function () {
  function normHash(h) {
    h = String(h || '').replace(/^#/, '');
    if (!h || h === '/') return '/';
    return h.charAt(0) === '/' ? h : '/' + h;
  }
  function syncUltitechSidebarActive() {
    var current = normHash(window.location.hash || '/');
    var links = document.querySelectorAll('#native-sidebar a.nav-link[href*="ultitech-bridge"]');
    links.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      var frag = '';
      var hashIdx = href.indexOf('#');
      if (hashIdx >= 0) frag = href.slice(hashIdx + 1);
      var target = normHash(frag || '/');
      var on = target === current;
      a.classList.toggle('active', on);
      a.style.fontWeight = on ? '500' : '400';
    });
  }
  syncUltitechSidebarActive();
  window.addEventListener('hashchange', syncUltitechSidebarActive);
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('#native-sidebar a.nav-link[href*="ultitech-bridge"]') : null;
    if (!a) return;
    // Same document: let hash update, then sync (avoid leaving stale .active).
    setTimeout(syncUltitechSidebarActive, 0);
  });
})();
</script>
JS;
    }

    private function deskChromeCss(): string
    {
        return <<<'CSS'
<style>
/* Desktop: content-first (no duplicate header). Mobile: PV-style header chrome. */
body.page-ultitech-accounting .employee-header.employee-header--accounting-desk {
    display: none !important;
}
@media (max-width: 991.98px) {
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk {
        display: block !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0.35rem 0.7rem 0.15rem !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .header-content {
        display: grid !important;
        grid-template-columns: auto minmax(0, 1fr) auto !important;
        align-items: center !important;
        gap: 0.45rem 0.65rem !important;
        min-height: 0 !important;
        padding: 0 !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .header-left {
        display: flex !important;
        align-items: center !important;
        margin: 0 !important;
        padding: 0 !important;
        grid-column: 1;
        grid-row: 1;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-menu-btn {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        min-width: 2.5rem;
        min-height: 2.5rem;
        margin: 0 !important;
        padding: 0.2rem !important;
        color: #0f172a !important;
        line-height: 1;
        text-decoration: none !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .erp-hamburger {
        color: #0f172a !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-page-heading {
        grid-column: 2;
        grid-row: 1;
        margin: 0 !important;
        padding: 0 !important;
        min-width: 0;
        gap: 2px !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-page-title {
        font-size: 1.2rem !important;
        font-weight: 500 !important;
        color: #0f172a !important;
        line-height: 1.25 !important;
        letter-spacing: -0.01em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-page-subtitle {
        margin: 0 !important;
        font-size: 12px !important;
        color: #94a3b8 !important;
        line-height: 1.3 !important;
        display: block !important;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        line-height: 1.3;
        min-width: 0;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-link {
        color: #94a3b8;
        text-decoration: none;
        font-weight: 500;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-link:hover {
        color: #7c3aed;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-sep {
        color: #cbd5e1;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-current {
        color: #64748b;
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 46vw;
    }
    body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .header-right {
        grid-column: 3;
        grid-row: 1;
        margin-left: 0 !important;
    }
    body.page-ultitech-accounting #erp-auto-breadcrumb {
        display: none !important;
    }
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-menu-btn,
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .erp-hamburger,
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .employee-header-page-title {
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-link,
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-sep,
    html[data-theme="dark"] body.page-ultitech-accounting .employee-header.employee-header--accounting-desk .vv-breadcrumb-current {
        color: #00a884 !important;
    }
}
/* Keep sidebar labels regular weight; only true .active may use medium */
body.page-ultitech-accounting #native-sidebar .nav-link {
    font-weight: 400 !important;
}
body.page-ultitech-accounting #native-sidebar .nav-link.active {
    font-weight: 500 !important;
}
body.page-ultitech-accounting #native-sidebar .sidebar-text,
body.page-ultitech-accounting #native-sidebar .user-profile-link .sidebar-text {
    font-weight: 400 !important;
}
body.page-ultitech-accounting.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-ultitech-accounting.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-ultitech-accounting,
body.page-ultitech-accounting.dashboard,
body.page-ultitech-accounting .layout-main-wrapper,
body.page-ultitech-accounting .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
main.main-content.ultitech-accounting-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0.75rem 1.25rem 1.5rem !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
@media (max-width: 720px) {
    main.main-content.ultitech-accounting-root {
        padding: 0.55rem 0.7rem 1.1rem !important;
    }
}
/* Account form mobile footer (must beat ERP chrome / mobile nav) */
@media (max-width: 991.98px) {
    html.ua-sheet-open,
    body.ua-sheet-open {
        overflow: hidden !important;
        overscroll-behavior: none !important;
    }
    body.ua-sheet-open .mobile-footer,
    body.ua-sheet-open nav.mobile-footer,
    body.ua-sheet-open .mf-floating-instagram {
        display: none !important;
        visibility: hidden !important;
        pointer-events: none !important;
    }
    /* Hide floating tab bar while scrolling down Cash & Bank */
    body.page-ultitech-accounting nav.mobile-footer.mf-scroll-hidden,
    body.page-ultitech-accounting .mobile-footer.mf-floating-instagram.mf-scroll-hidden {
        transform: translate(-50%, calc(100% + 32px + env(safe-area-inset-bottom, 0px))) !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }
    body > .ua-sheet-overlay,
    body > .ua-bank-picker-overlay {
        position: fixed !important;
        inset: 0 !important;
        display: flex !important;
        align-items: flex-end !important;
        justify-content: stretch !important;
        padding: 0 !important;
        overflow: hidden !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        background: rgba(15, 23, 42, 0.45) !important;
    }
    body > .ua-sheet-overlay { z-index: 2147483000 !important; }
    body > .ua-bank-picker-overlay { z-index: 2147483010 !important; }
    body > .ua-sheet-overlay .ua-sheet,
    body > .ua-bank-picker-overlay .ua-bank-picker {
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 1.5rem 1.5rem 0 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
        background: #fff !important;
    }
    body > .ua-sheet-overlay .ua-sheet {
        max-height: 78vh !important;
        max-height: 78dvh !important;
    }
    body > .ua-bank-picker-overlay .ua-bank-picker {
        height: min(68vh, 560px) !important;
        height: min(68dvh, 560px) !important;
    }
    body > .ua-sheet-overlay input,
    body > .ua-sheet-overlay select,
    body > .ua-sheet-overlay textarea,
    body > .ua-bank-picker-overlay input {
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
        font-size: 16px !important;
    }
}
main.main-content.ultitech-accounting-root #root {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    margin: 0;
    min-height: 320px;
    overflow-x: hidden;
    box-sizing: border-box;
    font-size: 15px !important;
    font-weight: 400 !important;
}
main.main-content.ultitech-accounting-root #root .ua-page-wrap {
    max-width: 100%;
    min-width: 0;
    overflow-x: hidden;
}
main.main-content.ultitech-accounting-root #root .ua-table-scroll {
    max-width: 100%;
    overflow-x: auto;
}
main.main-content.ultitech-accounting-root #root h1,
main.main-content.ultitech-accounting-root #root h2,
main.main-content.ultitech-accounting-root #root h3,
main.main-content.ultitech-accounting-root #root .ua-page-title {
    font-size: 18px !important;
    font-weight: 400 !important;
}
main.main-content.ultitech-accounting-root #root h2,
main.main-content.ultitech-accounting-root #root .ua-section-title {
    font-size: 13px !important;
    font-weight: 400 !important;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b !important;
}
main.main-content.ultitech-accounting-root #root .ua-empty-title {
    font-size: 14px !important;
    font-weight: 400 !important;
}
main.main-content.ultitech-accounting-root #root .ua-empty-body {
    font-size: 13px !important;
    font-weight: 400 !important;
    color: #64748b !important;
}
main.main-content.ultitech-accounting-root #root .ua-page-sub {
    font-size: 14px !important;
}
main.main-content.ultitech-accounting-root #root table,
main.main-content.ultitech-accounting-root #root td,
main.main-content.ultitech-accounting-root #root th,
main.main-content.ultitech-accounting-root #root input,
main.main-content.ultitech-accounting-root #root select,
main.main-content.ultitech-accounting-root #root button,
main.main-content.ultitech-accounting-root #root label {
    font-size: 14px !important;
}
main.main-content.ultitech-accounting-root #root .ua-btn-pill {
    border-radius: 9999px !important;
    padding-left: 1.1rem !important;
    padding-right: 1.1rem !important;
}
html[data-theme="dark"] body.page-ultitech-accounting,
html[data-theme="dark"] body.page-ultitech-accounting.dashboard,
html[data-theme="dark"] body.page-ultitech-accounting .layout-main-wrapper,
html[data-theme="dark"] body.page-ultitech-accounting .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-ultitech-accounting main.main-content.ultitech-accounting-root {
    background: #0f172a !important;
}
</style>
CSS;
    }
}
