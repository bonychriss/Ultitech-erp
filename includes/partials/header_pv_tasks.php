<?php
/**
 * Header / sidebar Payment Voucher task badge.
 * Count = PVs that currently require action from the logged-in user (workflow turn).
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}
if (empty($_SESSION['user_id'])) {
    return;
}

$pvTasksUrl = function_exists('company_url')
    ? company_url('employee/pending-voucher-tasks.php?module=voucher')
    : (function_exists('app_url') ? app_url('/employee/pending-voucher-tasks.php?module=voucher') : '/employee/pending-voucher-tasks.php?module=voucher');

$pvTaskCount = 0;
try {
    if (function_exists('countPendingPaymentVoucherTasks')) {
        $pvTaskCount = (int) countPendingPaymentVoucherTasks($pdo);
    }
} catch (Throwable $e) {
    error_log('header PV tasks count: ' . $e->getMessage());
    $pvTaskCount = 0;
}

$pvDisplayMode = $pvDisplayMode ?? 'header';
$pvIsSidebar = ($pvDisplayMode === 'sidebar');
$pvLabel = $pvTaskCount > 0
    ? ($pvTaskCount === 1 ? '1 payment voucher needs your action' : $pvTaskCount . ' payment vouchers need your action')
    : 'Payment voucher tasks';
?>
<style>
.header-pv-tasks-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: none;
    background: transparent;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    padding: 0;
}
.header-pv-tasks-btn:hover {
    background: rgba(15, 23, 42, 0.06);
}
.header-pv-tasks-inner {
    position: relative;
    display: inline-flex;
    width: 22px;
    height: 22px;
    align-items: center;
    justify-content: center;
}
.header-pv-tasks-svg {
    width: 22px;
    height: 22px;
    display: block;
}
.header-pv-tasks-badge {
    position: absolute;
    top: -7px;
    right: -9px;
    min-width: 17px;
    height: 17px;
    padding: 0 4px;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    line-height: 17px;
    text-align: center;
    box-shadow: 0 0 0 2px #fff;
}
html[data-theme="dark"] .header-pv-tasks-badge {
    box-shadow: 0 0 0 2px #0f172a;
}
html[data-theme="dark"] .header-pv-tasks-btn:hover {
    background: rgba(148, 163, 184, 0.12);
}
<?php if ($pvIsSidebar): ?>
.sidebar-pv-item .header-pv-tasks-btn {
    width: 100%;
    justify-content: flex-start;
    gap: 0.75rem;
    height: auto;
    min-height: 42px;
    padding: 0.55rem 0.85rem;
    border-radius: 10px;
}
.sidebar-pv-item .header-pv-tasks-inner {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
}
.sidebar-pv-item .sidebar-pv-label {
    font-size: 0.9rem;
    font-weight: 500;
}
body.sidebar-collapsed .sidebar-pv-item .sidebar-pv-label {
    display: none;
}
<?php endif; ?>
</style>
<?php if ($pvIsSidebar): ?>
<a href="<?= htmlspecialchars($pvTasksUrl) ?>" class="header-pv-tasks-btn nav-link" aria-label="<?= htmlspecialchars($pvLabel) ?>" title="<?= htmlspecialchars($pvLabel) ?>">
    <span class="header-pv-tasks-inner" aria-hidden="true">
        <svg class="header-pv-tasks-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <?php if ($pvTaskCount > 0): ?>
            <span class="header-pv-tasks-badge"><?= $pvTaskCount > 99 ? '99+' : (int) $pvTaskCount ?></span>
        <?php endif; ?>
    </span>
    <span class="sidebar-text sidebar-pv-label">PV Tasks</span>
</a>
<?php else: ?>
<a href="<?= htmlspecialchars($pvTasksUrl) ?>" class="header-pv-tasks-btn" aria-label="<?= htmlspecialchars($pvLabel) ?>" title="<?= htmlspecialchars($pvLabel) ?>">
    <span class="header-pv-tasks-inner" aria-hidden="true">
        <svg class="header-pv-tasks-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#111827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <?php if ($pvTaskCount > 0): ?>
            <span class="header-pv-tasks-badge"><?= $pvTaskCount > 99 ? '99+' : (int) $pvTaskCount ?></span>
        <?php endif; ?>
    </span>
</a>
<?php endif; ?>
