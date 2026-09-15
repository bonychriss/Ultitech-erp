<?php
/**
 * Attendance settings — redirects into combined Time & Attendance admin desk.
 * URL: /attendance/settings.php  →  /admin/time-settings.php?module=settings
 */
if (!isset($publicHtmlRoot) || !is_string($publicHtmlRoot) || $publicHtmlRoot === '') {
    $publicHtmlRoot = dirname(__DIR__);
}

$__attSettingsScript = '/' . ltrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (preg_match('#/([A-Za-z0-9-]+)/attendance/settings\.php#i', $__attSettingsScript, $__attSlugMatch)) {
    $_GET['company_slug'] = $_GET['company_slug'] ?? $__attSlugMatch[1];
}

require_once $publicHtmlRoot . '/includes/functions.php';
requireAdmin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['active_module'] = 'settings';

$qs = 'module=settings';
if (!empty($_GET['company_id'])) {
    $qs .= '&company_id=' . rawurlencode((string) $_GET['company_id']);
}
if (!empty($_GET['company_slug'])) {
    $qs .= '&company_slug=' . rawurlencode((string) $_GET['company_slug']);
}

$target = function_exists('company_url')
    ? company_url('admin/time-settings.php?' . $qs)
    : (function_exists('app_url')
        ? app_url('/admin/time-settings.php?' . $qs)
        : '/admin/time-settings.php?' . $qs);

header('Location: ' . $target, true, 302);
exit;
