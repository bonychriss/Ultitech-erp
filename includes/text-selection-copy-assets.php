<?php
/**
 * Global text-selection ? Copy button assets.
 * Safe to include from any page (employee/admin chrome or standalone shells).
 */
$textSelectionCopyCss = __DIR__ . '/../assets/css/text-selection-copy.css';
$textSelectionCopyJs = __DIR__ . '/../assets/js/text-selection-copy.js';
$textSelectionCopyVer = max(
    (int) (@filemtime($textSelectionCopyCss) ?: 0),
    (int) (@filemtime($textSelectionCopyJs) ?: 0)
);
$__tscBase = function_exists('app_url') ? app_url('/') : '/';
$__tscCss = rtrim($__tscBase, '/') . '/assets/css/text-selection-copy.css';
$__tscJs = rtrim($__tscBase, '/') . '/assets/js/text-selection-copy.js';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($__tscCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int) $textSelectionCopyVer ?>">
<script src="<?= htmlspecialchars($__tscJs, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int) $textSelectionCopyVer ?>" defer></script>
