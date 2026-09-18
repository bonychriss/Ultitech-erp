<?php
/**
 * Attendance desk — Laravel + React entry.
 * URL: /{company_slug}/attendance/?module=attendance
 *
 * Canonical logic lives in /attendance.php (erp-laravel Domains/Attendance).
 */
declare(strict_types=1);

$__attScript = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/attendance(?:/index\.php)?$#i', $__attScript, $__attSlugMatch)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__attSlugMatch[1];
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'attendance.php';
