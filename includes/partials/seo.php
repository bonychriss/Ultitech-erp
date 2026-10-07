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
     * @param array{title:string,description:string,path:string,image?:string,robots?:string,schema?:bool} $page
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

        if (!empty($page['schema'])) {
            $graph = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
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
                    ],
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
