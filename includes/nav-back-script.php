<?php
$erpNavBackFs = dirname(__DIR__) . '/assets/js/nav-back.js';
$erpNavBackSrc = function_exists('app_url') ? app_url('/assets/js/nav-back.js') : '/assets/js/nav-back.js';
$erpNavBackV = is_file($erpNavBackFs) ? (string) filemtime($erpNavBackFs) : (string) time();
?>
<script src="<?= htmlspecialchars($erpNavBackSrc . '?v=' . $erpNavBackV, ENT_QUOTES, 'UTF-8') ?>"></script>
