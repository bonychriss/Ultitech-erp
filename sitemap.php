<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

$laravelRoot = __DIR__ . '/erp-laravel';
if (!is_file($laravelRoot . '/vendor/autoload.php')) {
    header('Content-Type: application/xml; charset=UTF-8');
    readfile(__DIR__ . '/sitemap.xml');
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'ultitech.io');

$GLOBALS['ERP_SITEMAP_CONTEXT'] = [
    'base_url' => $scheme . '://' . $host . rtrim(app_url('/'), '/'),
];
$GLOBALS['ERP_ROUTE'] = '/sitemap.xml';

require $laravelRoot . '/bootstrap/erp-bridge.php';
