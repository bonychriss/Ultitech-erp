<?php

declare(strict_types=1);

/**
 * Tenant URL entry: /{company_slug}/deliveries/create_delivery_note.php
 * Serves Create Delivery Note via Laravel + React.
 */
$__sn = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/deliveries/create_delivery_note\.php#i', $__sn, $__m)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__m[1];
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = 'create_delivery_note';

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'deliveries.php';
