<?php

namespace App\Domains\Home;

/**
 * Public homepage React shell (home-ui Vite dist) via erp-laravel Blade.
 */
final class HomeShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string
     * }|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $bootCfg = [
            'page' => (string) ($cfg['page'] ?? 'home'),
            'homeUrl' => (string) ($cfg['homeUrl'] ?? (function_exists('app_url') ? app_url('/') : '/')),
            'pricingUrl' => (string) ($cfg['pricingUrl'] ?? (function_exists('app_url') ? app_url('/pricing.php') : '/pricing.php')),
            'loginUrl' => (string) ($cfg['loginUrl'] ?? (function_exists('app_url') ? app_url('/login.php') : '/login.php')),
            'accountUrl' => (string) ($cfg['accountUrl'] ?? (function_exists('app_url') ? app_url('/my-account.php') : '/my-account.php')),
            'trialUrl' => (string) ($cfg['trialUrl'] ?? (function_exists('app_url') ? app_url('/free-trial.php') : '/free-trial.php')),
            'imgBase' => (string) ($cfg['imgBase'] ?? (function_exists('app_url')
                ? rtrim((string) app_url('/home-ui/frontend/dist/skilline/img'), '/') . '/'
                : '/home-ui/frontend/dist/skilline/img/')),
            'contactUrl' => (string) ($cfg['contactUrl'] ?? (function_exists('app_url') ? app_url('/contact.php') : '/contact.php')),
            'aboutUrl' => (string) ($cfg['aboutUrl'] ?? (function_exists('app_url') ? app_url('/about.php') : '/about.php')),
            'year' => (int) ($cfg['year'] ?? date('Y')),
            'engine' => 'erp-laravel Domains/Home',
        ];

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $contact = [];
        $texts = [];
        if (is_file($root . '/includes/site-contact.php')) {
            require_once $root . '/includes/site-contact.php';
            $contact = erp_site_contact();
            $texts = erp_site_texts();
        }
        $bootCfg['contact'] = $contact;
        $bootCfg['texts'] = $texts;

        $pageKey = strtolower((string) $bootCfg['page']);
        $seo = match ($pageKey) {
            'pricing' => [
                'title' => 'Pricing and Free Trial | UltiTech ERP',
                'description' => 'See UltiTech ERP pricing and try the full suite free for 14 days: sales, inventory, payroll, expenses and reports in one cloud system. No card needed.',
                'path' => '/pricing.php',
            ],
            'contact' => [
                'title' => 'Contact Us | UltiTech ERP',
                'description' => 'Call, WhatsApp or email the UltiTech ERP team in Dar es Salaam, Tanzania for demos, pricing questions and support.',
                'path' => '/contact.php',
            ],
            'about' => [
                'title' => 'About Us | UltiTech ERP',
                'description' => 'UltiTech builds a cloud ERP that brings sales, stock, payroll, expenses and accounting into one system for growing businesses in Tanzania.',
                'path' => '/about.php',
            ],
            default => [
                'title' => 'UltiTech ERP | Cloud ERP for Sales, Stock, Payroll and Accounting',
                'description' => 'UltiTech ERP connects sales, stock, payroll, expenses and accounting in one secure cloud system, with live reports for every department. Start a free 14-day trial.',
                'path' => '/',
                'schema' => true,
            ],
        };
        $seo['contact'] = $contact;
        $pageTitle = $seo['title'];

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $seoPartial = $root . '/includes/partials/seo.php';
        $headMarkup = '';
        if (is_file($seoPartial)) {
            require_once $seoPartial;
            $headMarkup .= erp_seo_tags($seo);
        }
        $headMarkup .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css">' . "\n";
        if ($assets['cssFile'] !== '') {
            $headMarkup .= '<link rel="stylesheet" crossorigin href="'
                . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        $headMarkup .= '<script>window.__HOME_CFG__ = '
            . json_encode($bootCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            . ';</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => 'home-ui-page',
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
        ];
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $distIndex = $root . '/home-ui/frontend/dist/index.html';
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
            ? rtrim((string) app_url('/home-ui/frontend/dist/assets/'), '/') . '/'
            : '/public_html/home-ui/frontend/dist/assets/';

        $cssPath = $root . '/home-ui/frontend/dist/assets/' . $cssFile;
        $jsPath = $root . '/home-ui/frontend/dist/assets/' . $jsFile;

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}
