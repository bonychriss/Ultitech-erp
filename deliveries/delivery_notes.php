<?php

declare(strict_types=1);

/**
 * Delivery Notes list via Laravel + React.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = 'delivery_notes';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deliveries.php';
