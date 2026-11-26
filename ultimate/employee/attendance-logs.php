<?php
/**
 * Attendance Logs — Laravel + React entry (ultimate mirror).
 */
declare(strict_types=1);

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
$_GET['desk'] = 'logs';

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'attendance.php';
