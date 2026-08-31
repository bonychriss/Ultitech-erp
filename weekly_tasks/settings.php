<?php
require_once __DIR__ . '/includes/performance_bootstrap.php';
require_once __DIR__ . '/includes/performance_layout.php';

$accountUrl = function_exists('user_profile_settings_url')
    ? user_profile_settings_url()
    : '../employee/account.php';

perf_layout_start('settings', 'Account and module preferences.');
?>

<div class="perf-panel">
    <div class="perf-panel__head"><h3>Settings</h3></div>
    <div style="padding:20px;">
        <p style="color:var(--perf-muted);margin-bottom:16px;">Manage your profile and notification preferences.</p>
        <a href="<?= htmlspecialchars($accountUrl) ?>" class="perf-btn-ai"><i class="bi bi-person"></i> Account settings</a>
        <a href="<?= htmlspecialchars($modulesLink) ?>" class="perf-btn-ai" style="background:#475569;margin-left:8px;"><i class="bi bi-grid"></i> All modules</a>
    </div>
</div>

<?php perf_layout_end(); ?>
