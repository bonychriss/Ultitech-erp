<?php
/**
 * Google-style PO verify reminder for select-module + stock pages.
 */
declare(strict_types=1);

if (!empty($GLOBALS['_ultitech_po_verify_reminder_popup_rendered'])) {
    return;
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !function_exists('fetchUnreadPoVerifyReminders')) {
    return;
}

$reminders = fetchUnreadPoVerifyReminders($userId, 8);
if ($reminders === []) {
    return;
}

$GLOBALS['_ultitech_po_verify_reminder_popup_rendered'] = true;

$markReadUrl = function_exists('app_url')
    ? app_url('/api/get_notifications.php')
    : '/api/get_notifications.php';
$cssUrl = function_exists('app_url')
    ? app_url('/assets/css/po-verify-reminder.css')
    : '/assets/css/po-verify-reminder.css';
$jsUrl = function_exists('app_url')
    ? app_url('/assets/js/po-verify-reminder.js')
    : '/assets/js/po-verify-reminder.js';
$cssPath = dirname(__DIR__, 2) . '/assets/css/po-verify-reminder.css';
$jsPath = dirname(__DIR__, 2) . '/assets/js/po-verify-reminder.js';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVer = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssUrl . '?v=' . $cssVer, ENT_QUOTES, 'UTF-8') ?>">
<script>
window.__PO_VERIFY_REMINDERS__ = {
  items: <?= json_encode($reminders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  markReadUrl: <?= json_encode($markReadUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  autoShow: true
};
</script>
<script src="<?= htmlspecialchars($jsUrl . '?v=' . $jsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
