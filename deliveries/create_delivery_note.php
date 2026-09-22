<?php

declare(strict_types=1);

/**
 * Create Delivery Note via Laravel + React.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = 'create_delivery_note';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deliveries.php';
