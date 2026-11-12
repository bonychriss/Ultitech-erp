<?php

/*
|--------------------------------------------------------------------------
| Public sitemap (served at /sitemap.xml via sitemap.php ? erp-laravel)
|--------------------------------------------------------------------------
| 'path' is the public URL path; 'file' is the root PHP entry used for <lastmod>.
| Only list pages that load without a login.
|
| 'images' are listed as <image:image> entries so Google Images can find them.
| Paths starting with "src/" are home-ui sources resolved to their hashed build file
| through home-ui/frontend/dist/.vite/manifest.json; other paths are site-relative files.
*/

$brand = ['assets/brand/ultitech-og.png', 'assets/brand/ultitech-logo-512.png'];

return [
    'manifest' => 'home-ui/frontend/dist/.vite/manifest.json',

    'pages' => [
        [
            'path' => '/',
            'file' => 'index.php',
            'changefreq' => 'weekly',
            'priority' => '1.0',
            'images' => array_merge($brand, [
                'src/assets/hero/hand-cards.png',
                'src/assets/screens/inventory-stock.jpg',
                'src/assets/screens/hr-payroll.jpg',
                'src/assets/features/sales-invoice.jpg',
                'src/assets/features/finance.png',
                'src/assets/features/operations.png',
                'src/assets/features/product-owners.png',
                'src/assets/features/product-teams.png',
                'src/assets/news/featured.png',
                'src/assets/news/finance.png',
                'src/assets/news/payroll.png',
                'src/assets/news/stock.png',
            ]),
        ],
        ['path' => '/pricing.php', 'file' => 'pricing.php', 'changefreq' => 'monthly', 'priority' => '0.9', 'images' => $brand],
        ['path' => '/free-trial.php', 'file' => 'free-trial.php', 'changefreq' => 'monthly', 'priority' => '0.9', 'images' => $brand],
        ['path' => '/about.php', 'file' => 'about.php', 'changefreq' => 'monthly', 'priority' => '0.7', 'images' => $brand],
        ['path' => '/contact.php', 'file' => 'contact.php', 'changefreq' => 'monthly', 'priority' => '0.7', 'images' => $brand],
        ['path' => '/faq.php', 'file' => 'includes/site-faq.php', 'changefreq' => 'monthly', 'priority' => '0.7', 'images' => $brand],
        ['path' => '/login.php', 'file' => 'login.php', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ],
];
