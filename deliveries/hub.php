<?php

declare(strict_types=1);

/**
 * Deliveries hub entry — Laravel + React (erp-laravel Domains/Deliveries).
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = $_GET['desk'] ?? 'hub';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deliveries.php';
