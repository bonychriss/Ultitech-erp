<?php
/**
 * Classic icon theme toggle (shared headers / sidebar).
 * Visual only — theme logic: assets/js/theme-toggle.js
 */
$themeToggleExtraClass = isset($themeToggleExtraClass) ? trim((string) $themeToggleExtraClass) : '';
$themeToggleId = isset($themeToggleId) && $themeToggleId !== ''
    ? (string) $themeToggleId
    : 'themeToggleBtn';
$themeToggleIsSidebar = strpos($themeToggleExtraClass, 'sidebar-theme-toggle') !== false;
$themeToggleClass = $themeToggleIsSidebar
    ? 'nav-link sidebar-theme-toggle w-100 text-start border-0 bg-transparent theme-toggle-btn'
    : ('theme-toggle-btn' . ($themeToggleExtraClass !== '' ? ' ' . $themeToggleExtraClass : ''));
$themeToggleIconId = ($themeToggleId === 'themeToggleBtn') ? 'themeToggleIcon' : ($themeToggleId . 'Icon');
?>
<button
    type="button"
    id="<?= htmlspecialchars($themeToggleId, ENT_QUOTES, 'UTF-8') ?>"
    class="<?= htmlspecialchars($themeToggleClass, ENT_QUOTES, 'UTF-8') ?>"
    data-erp-theme-toggle
    aria-label="Toggle Theme"
    title="Toggle Dark/Light Mode"
>
    <i class="fas fa-moon" id="<?= htmlspecialchars($themeToggleIconId, ENT_QUOTES, 'UTF-8') ?>"></i>
    <?php if ($themeToggleIsSidebar): ?>
    <span class="sidebar-text">Dark Mode</span>
    <?php endif; ?>
</button>
