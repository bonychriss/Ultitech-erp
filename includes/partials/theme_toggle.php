<?php
/**
 * Glass bubble theme toggle (shared headers / sidebar).
 * Visual only — theme logic: assets/js/theme-toggle.js
 */
$themeToggleExtraClass = isset($themeToggleExtraClass) ? trim((string) $themeToggleExtraClass) : '';
$themeToggleId = isset($themeToggleId) && $themeToggleId !== ''
    ? (string) $themeToggleId
    : 'themeToggleBtn';
$themeToggleClass = 'theme-toggle-btn theme-toggle-glass'
    . ($themeToggleExtraClass !== '' ? ' ' . $themeToggleExtraClass : '');
?>
<button
    type="button"
    id="<?= htmlspecialchars($themeToggleId, ENT_QUOTES, 'UTF-8') ?>"
    class="<?= htmlspecialchars($themeToggleClass, ENT_QUOTES, 'UTF-8') ?>"
    data-erp-theme-toggle
    aria-label="Toggle theme"
    title="Toggle theme"
>
    <span class="theme-toggle-glass__track" aria-hidden="true">
        <!-- Fixed icons: bubble slides over the active one -->
        <i class="fas fa-sun theme-toggle-glass__icon theme-toggle-glass__icon--sun"<?= $themeToggleId === 'themeToggleBtn' ? ' id="themeToggleIcon"' : '' ?>></i>
        <i class="fas fa-moon theme-toggle-glass__icon theme-toggle-glass__icon--moon"></i>
        <span class="theme-toggle-glass__label theme-toggle-glass__label--light">Light</span>
        <span class="theme-toggle-glass__label theme-toggle-glass__label--dark">Dark</span>
        <!-- Moving glass bubble -->
        <span class="theme-toggle-glass__bubble"></span>
    </span>
</button>
