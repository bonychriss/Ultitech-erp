<?php
/**
 * Attendance Logs — Laravel + React entry.
 * URL: /employee/attendance-logs.php?module=attendance
 *      /{company}/employee/attendance-logs.php?module=attendance
 */
declare(strict_types=1);

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
$_GET['desk'] = 'logs';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'attendance.php';
