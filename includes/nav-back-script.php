<?php
/**
 * One-step-back navigation script include.
 * Prefer the global HTML injector in functions.php; this partial remains for
 * pages that need an early explicit load (and stays safe if functions.php
 * is not yet bootstrapped).
 */
if (function_exists('erp_get_nav_back_script_html')) {
    echo erp_get_nav_back_script_html();
    return;
}

$erpNavBackFs = dirname(__DIR__) . '/assets/js/nav-back.js';
$erpNavBackSrc = function_exists('app_url') ? app_url('/assets/js/nav-back.js') : '/assets/js/nav-back.js';
$erpNavBackV = is_file($erpNavBackFs) ? (string) filemtime($erpNavBackFs) : (string) time();
$fallback = function_exists('company_url') ? company_url('select-module') : (function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php');
$cfg = json_encode(['fallbackUrl' => $fallback], JSON_UNESCAPED_SLASHES);
?>
<script>window.__ERP_NAV_BACK_CFG__=<?= $cfg !== false ? $cfg : '{}' ?>;</script>
<script src="<?= htmlspecialchars($erpNavBackSrc . '?v=' . $erpNavBackV, ENT_QUOTES, 'UTF-8') ?>"></script>
