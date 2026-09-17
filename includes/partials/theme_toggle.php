<?php
/**
 * Compact theme toggle (shared headers / sidebar).
 * Visual only — theme logic: assets/js/theme-toggle.js
 */
$themeToggleExtraClass = isset($themeToggleExtraClass) ? trim((string) $themeToggleExtraClass) : '';
$themeToggleId = isset($themeToggleId) && $themeToggleId !== ''
    ? (string) $themeToggleId
    : 'themeToggleBtn';
$themeToggleIsSidebar = strpos($themeToggleExtraClass, 'sidebar-theme-toggle') !== false;
$themeToggleClass = 'theme-toggle-btn theme-toggle-glass'
    . ($themeToggleExtraClass !== '' ? ' ' . $themeToggleExtraClass : '');
if ($themeToggleIsSidebar) {
    $themeToggleClass .= ' sidebar-theme-toggle nav-link border-0 bg-transparent';
}
$themeToggleIconId = ($themeToggleId === 'themeToggleBtn') ? 'themeToggleIcon' : ($themeToggleId . 'Icon');
?>
<button
    type="button"
    id="<?= htmlspecialchars($themeToggleId, ENT_QUOTES, 'UTF-8') ?>"
    class="<?= htmlspecialchars($themeToggleClass, ENT_QUOTES, 'UTF-8') ?>"
    data-erp-theme-toggle
    aria-label="Toggle theme"
    title="Toggle Dark/Light Mode"
>
    <span class="theme-toggle-glass__track" aria-hidden="true">
        <i class="fas fa-sun theme-toggle-glass__icon theme-toggle-glass__icon--sun"<?= $themeToggleId === 'themeToggleBtn' ? ' id="' . htmlspecialchars($themeToggleIconId, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></i>
        <i class="fas fa-moon theme-toggle-glass__icon theme-toggle-glass__icon--moon"></i>
        <span class="theme-toggle-glass__bubble"></span>
    </span>
    <?php if ($themeToggleIsSidebar): ?>
    <span class="sidebar-text">Dark Mode</span>
    <?php endif; ?>
</button>
