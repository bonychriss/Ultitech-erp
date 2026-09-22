<?php
/**
 * Company stub for Driver KPI under /ultimate/driver-kpi/.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'driver_kpi';
}
$_GET['company_slug'] = $_GET['company_slug'] ?? 'ultimate';

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'driver-kpi' . DIRECTORY_SEPARATOR . 'index.php';
