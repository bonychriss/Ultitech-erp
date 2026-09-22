<?php
/**
 * Company stub for Deliveries Laravel bridge under /ultimate/deliveries.php
 */
$_GET['company_slug'] = $_GET['company_slug'] ?? 'ultimate';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_GET['desk'] = $_GET['desk'] ?? 'hub';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deliveries.php';
