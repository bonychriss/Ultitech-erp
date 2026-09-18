<?php
/**
 * Physical alias so /ultimate/attendance works (on-disk ultimate/ folder).
 * Bridges into attendance.php (Laravel + React attendance desk).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'attendance.php';
