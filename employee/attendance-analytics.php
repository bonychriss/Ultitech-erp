<?php
/**
 * Attendance Stats — Laravel + React entry.
 * URL: /employee/attendance-analytics.php?module=attendance
 *      /{company}/employee/attendance-analytics.php?module=attendance
 */
declare(strict_types=1);

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
$_GET['desk'] = 'analytics';
if (!isset($_GET['period']) || (string) $_GET['period'] === '') {
    $_GET['period'] = '30';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'attendance.php';
