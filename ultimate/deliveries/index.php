<?php

declare(strict_types=1);

/**
 * Tenant URL entry: /{company_slug}/deliveries/index.php (e.g. /ultimate/deliveries/index.php)
 */
$__sn = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/deliveries/index\.php#i', $__sn, $__m)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__m[1];
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'deliveries' . DIRECTORY_SEPARATOR . 'deliveries-ui' . DIRECTORY_SEPARATOR . 'render-dashboard.php';
