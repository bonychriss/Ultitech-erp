<?php
/**
 * Tenant entry: /{company_slug}/attendance/settings.php (e.g. /ultimate/attendance/settings.php)
 * Apache serves this file directly when it exists; canonical logic lives in attendance/settings.php.
 */
$publicHtmlRoot = dirname(__DIR__, 2);

$__attSettingsScript = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/attendance/settings\.php#i', $__attSettingsScript, $__attSlugMatch)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__attSlugMatch[1];
}

require $publicHtmlRoot . DIRECTORY_SEPARATOR . 'attendance' . DIRECTORY_SEPARATOR . 'settings.php';
