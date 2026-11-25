<?php
/**
 * Physical alias: /ultimate/attendance/mobile-preview.php
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'attendance' . DIRECTORY_SEPARATOR . 'mobile-preview.php';
