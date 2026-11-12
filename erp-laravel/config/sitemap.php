<?php

/*
|--------------------------------------------------------------------------
| Public sitemap (served at /sitemap.xml via sitemap.php ? erp-laravel)
|--------------------------------------------------------------------------
| 'path' is the public URL path; 'file' is the root PHP entry used for <lastmod>.
| Only list pages that load without a login.
*/

return [
    'pages' => [
        ['path' => '/', 'file' => 'index.php', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['path' => '/pricing.php', 'file' => 'pricing.php', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['path' => '/free-trial.php', 'file' => 'free-trial.php', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['path' => '/about.php', 'file' => 'about.php', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['path' => '/contact.php', 'file' => 'contact.php', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['path' => '/faq.php', 'file' => 'includes/site-faq.php', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['path' => '/login.php', 'file' => 'login.php', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ],
];
