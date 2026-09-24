<?php
/**
 * Boot the Google-style PV coach tip on voucher pages.
 * Tip content is loaded from api/pv_coach.php (works even if sidebar $pdo/tasks fail).
 *
 * Laravel voucher shells rewrite SCRIPT_NAME to /voucher/create|/voucher/view,
 * so detection also uses REQUEST_URI, ERP_ROUTE, and active_module.
 */
if (empty($_SESSION['user_id'])) {
    return;
}

if (!empty($GLOBALS['_ultitech_pv_turn_coach_rendered'])) {
    return;
}

$activeModule = strtolower(trim((string) ($active_module ?? ($_SESSION['active_module'] ?? ''))));
$moduleParam = strtolower(trim((string) ($_GET['module'] ?? '')));
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$requestUri = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? ''));
$erpRoute = strtolower(trim((string) ($GLOBALS['ERP_ROUTE'] ?? $GLOBALS['ERP_VOUCHER_ROUTE'] ?? '')));
$pathBlob = strtolower($scriptPath . ' ' . $requestUri . ' ' . $erpRoute);

$voucherPages = array(
    'dashboard.php',
    'all-vouchers.php',
    'my-vouchers.php',
    'pending-voucher-tasks.php',
    'create-voucher.php',
    'view-voucher.php',
    'edit-voucher.php',
);
$onVoucherPage = false;
foreach ($voucherPages as $vp) {
    if (substr($scriptPath, -strlen($vp)) === $vp
        && (strpos($scriptPath, '/admin/') !== false || strpos($scriptPath, '/employee/') !== false)) {
        $onVoucherPage = true;
        break;
    }
}
if (!$onVoucherPage && preg_match('#(create-voucher|view-voucher|edit-voucher|my-vouchers|all-vouchers|pending-voucher|/voucher/create|/voucher/view)#', $pathBlob)) {
    $onVoucherPage = true;
}
if (!$onVoucherPage && (str_starts_with($erpRoute, '/voucher/') || $activeModule === 'voucher' || $moduleParam === 'voucher')) {
    $onVoucherPage = true;
}
if ($activeModule !== 'voucher' && $moduleParam !== 'voucher' && !$onVoucherPage) {
    return;
}

$GLOBALS['_ultitech_pv_turn_coach_rendered'] = true;

// Use app_url (not company_url): /{slug}/api/pv_coach.php 404s when a physical
// /{slug}/ folder exists and blocks the tenant rewrite.
$apiUrl = function_exists('app_url') ? app_url('/api/pv_coach.php') : '/api/pv_coach.php';
$jsUrl = function_exists('app_url') ? app_url('/assets/js/pv-turn-coach.js') : '/assets/js/pv-turn-coach.js';
$ncCss = function_exists('app_url') ? app_url('/assets/css/notifications-centre.css') : '/assets/css/notifications-centre.css';
$jsPath = dirname(__DIR__, 2) . '/assets/js/pv-turn-coach.js';
$jsVer = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();
$cssPath = dirname(__DIR__, 2) . '/assets/css/notifications-centre.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($ncCss . '?v=' . $cssVer, ENT_QUOTES, 'UTF-8') ?>">
<script>
window.ULTITECH_PV_COACH = {
  apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
  anchor: '.sidebar-notif-item .sidebar-notif-trigger, .sidebar-notif-item .header-notif-bell-btn, .header-notif-bell-btn',
  scrollHost: '#native-sidebar'
};
</script>
<script src="<?= htmlspecialchars($jsUrl . '?v=' . $jsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
