<?php

declare(strict_types=1);

/**
 * Search and link-preview tags for the public ultitech.io pages (home, pricing, free trial, sign in).
 * Canonical and preview URLs always use the live domain so www. and local copies count as the same page.
 */

if (!defined('ERP_PUBLIC_SITE_URL')) {
    define('ERP_PUBLIC_SITE_URL', 'https://ultitech.io');
}

if (!function_exists('erp_seo_tags')) {
    /**
     * @param array{title:string,description:string,path:string,image?:string,robots?:string,schema?:bool,contact?:array<string,string>} $page
     */
    function erp_seo_tags(array $page): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $site = rtrim(ERP_PUBLIC_SITE_URL, '/');
        $url = $site . '/' . ltrim($page['path'], '/');
        $image = $site . ($page['image'] ?? '/assets/brand/ultitech-og.png');
        $robots = $page['robots'] ?? 'index, follow';

        $tags = [
            '<meta name="description" content="' . $esc($page['description']) . '">',
            '<meta name="robots" content="' . $esc($robots) . '">',
        ];
        if (!str_contains($robots, 'noindex')) {
            $tags[] = '<link rel="canonical" href="' . $esc($url) . '">';
        }
        $tags = array_merge($tags, [
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="UltiTech ERP">',
            '<meta property="og:title" content="' . $esc($page['title']) . '">',
            '<meta property="og:description" content="' . $esc($page['description']) . '">',
            '<meta property="og:url" content="' . $esc($url) . '">',
            '<meta property="og:image" content="' . $esc($image) . '">',
            '<meta property="og:image:width" content="1200">',
            '<meta property="og:image:height" content="630">',
            '<meta property="og:image:alt" content="UltiTech ERP logo">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="' . $esc($page['title']) . '">',
            '<meta name="twitter:description" content="' . $esc($page['description']) . '">',
            '<meta name="twitter:image" content="' . $esc($image) . '">',
            '<meta name="theme-color" content="#8b3fe0">',
        ]);

        if (empty($page['schema']) && !str_contains($robots, 'noindex')) {
            $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'UltiTech ERP', 'item' => $site . '/']];
            $pageName = trim((string) preg_replace('/\s*\|\s*UltiTech ERP$/', '', $page['title']));
            $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $pageName, 'item' => $url];
            $graph = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'WebPage',
                        '@id' => $url . '#webpage',
                        'url' => $url,
                        'name' => $page['title'],
                        'description' => $page['description'],
                        'isPartOf' => ['@id' => $site . '/#website'],
                        'about' => ['@id' => $site . '/#organization'],
                        'breadcrumb' => ['@id' => $url . '#breadcrumb'],
                        'inLanguage' => 'en',
                    ],
                    ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $crumbs],
                ],
            ];
            $tags[] = '<script type="application/ld+json">'
                . json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
                . '</script>';
        }

        if (!empty($page['schema'])) {
            $organization = [
                '@type' => 'Organization',
                '@id' => $site . '/#organization',
                'name' => 'UltiTech',
                'alternateName' => ['UltiTech ERP', 'ultitech.io'],
                'url' => $site . '/',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $site . '/assets/brand/ultitech-logo-512.png',
                    'width' => 512,
                    'height' => 512,
                ],
            ];
            $contact = is_array($page['contact'] ?? null) ? $page['contact'] : [];
            if (($contact['phone_tel'] ?? '') !== '') {
                $organization['telephone'] = $contact['phone_tel'];
            }
            if (($contact['email'] ?? '') !== '') {
                $organization['email'] = $contact['email'];
            }
            if (($contact['address'] ?? '') !== '') {
                $organization['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $contact['address']];
            }
            if (($contact['phone_tel'] ?? '') !== '' || ($contact['email'] ?? '') !== '') {
                $organization['contactPoint'] = array_filter([
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'telephone' => $contact['phone_tel'] ?? '',
                    'email' => $contact['email'] ?? '',
                    'areaServed' => 'TZ',
                ]);
            }
            if (($contact['instagram_url'] ?? '') !== '') {
                $organization['sameAs'] = [$contact['instagram_url']];
            }

            $graph = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    $organization,
                    [
                        '@type' => 'WebSite',
                        '@id' => $site . '/#website',
                        'name' => 'UltiTech ERP',
                        'alternateName' => ['UltiTech', 'ultitech.io'],
                        'url' => $site . '/',
                        'publisher' => ['@id' => $site . '/#organization'],
                        'inLanguage' => 'en',
                    ],
                    [
                        '@type' => 'SoftwareApplication',
                        'name' => 'UltiTech ERP',
                        'applicationCategory' => 'BusinessApplication',
                        'operatingSystem' => 'Web browser',
                        'url' => $site . '/',
                        'description' => $page['description'],
                        'publisher' => ['@id' => $site . '/#organization'],
                    ],
                ],
            ];
            $tags[] = '<script type="application/ld+json">'
                . json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
                . '</script>';
        }

        return implode("\n", $tags) . "\n";
    }
}

if (!function_exists('erp_seo_fallback_html')) {
    /**
     * Plain-HTML copy of a public page placed inside #root, so crawlers get headings and
     * site links before JavaScript runs. React replaces it on mount.
     *
     * @param array{title:string,description:string} $page
     * @param array<string,string> $urls keys: home, pricing, trial, about, contact, login
     */
    function erp_seo_fallback_html(string $pageKey, array $page, array $urls): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $nav = [
            'home' => 'Home',
            'pricing' => 'Pricing',
            'trial' => 'Free Trial',
            'about' => 'About Us',
            'contact' => 'Contact Us',
            'login' => 'Sign in',
        ];
        $links = '';
        foreach ($nav as $key => $label) {
            if (!empty($urls[$key])) {
                $links .= '<li><a href="' . $esc($urls[$key]) . '">' . $esc($label) . '</a></li>';
            }
        }
        $heading = $pageKey === 'home'
            ? 'UltiTech ERP'
            : trim((string) preg_replace('/\s*\|\s*UltiTech ERP$/', '', $page['title']));
        $modules = [
            'Sales' => 'Quotations, sales orders, invoices, customers and price lists.',
            'Stock and inventory' => 'Products, warehouses, purchase orders, stock levels and transfers.',
            'Payroll' => 'Salaries, payslips and payroll runs for your staff.',
            'Expenses and payment vouchers' => 'Payment requests with approvals, receipts and budgets.',
            'Accounting and reports' => 'Account balances, general ledger, cash book and live reports.',
            'CRM and deliveries' => 'Leads, customer follow-ups, delivery notes and dispatch.',
        ];
        $items = '';
        foreach ($modules as $name => $text) {
            $items .= '<li><strong>' . $esc($name) . '</strong>: ' . $esc($text) . '</li>';
        }

        return '<div class="erp-seo-fallback" style="max-width:960px;margin:0 auto;padding:2rem 1.25rem;font-family:sans-serif;line-height:1.5;">'
            . '<nav aria-label="Main"><ul style="display:flex;flex-wrap:wrap;gap:1rem;list-style:none;padding:0;margin:0 0 1.5rem;">' . $links . '</ul></nav>'
            . '<h1>' . $esc($heading) . '</h1>'
            . '<p>' . $esc($page['description']) . '</p>'
            . '<h2>Modules in UltiTech ERP</h2><ul>' . $items . '</ul>'
            . '<p><a href="' . $esc($urls['trial'] ?? '/free-trial.php') . '">Start a free 14-day trial</a> or <a href="' . $esc($urls['contact'] ?? '/contact.php') . '">contact the UltiTech team</a> in Dar es Salaam, Tanzania.</p>'
            . '</div>';
    }
}
