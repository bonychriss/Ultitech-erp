<?php

declare(strict_types=1);

/**
 * Order Details via Laravel + React.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = 'order_details';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deliveries.php';
