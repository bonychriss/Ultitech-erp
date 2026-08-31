<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/../../includes/user-avatar.php';
requireLogin();

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$can_manage = pettyCashCanManage();
$custodian_scope = $can_manage ? null : $user_id;

$stats = getPettyCashDashboardStats($custodian_scope);
$cash_flow = getPettyCashFlowTrend(6, $custodian_scope);

$voucherFilters = ['limit' => 11, 'exclude_cancelled' => true];
$repFilters = ['limit' => 5];
if (!$can_manage) {
    $voucherFilters['custodian_id'] = $user_id;
    $repFilters['custodian_id'] = $user_id;
}
$vouchers = getAllPettyCashVouchers($voucherFilters);
$replenishments = getAllPettyCashReplenishments($repFilters);

$error = '';
$redirectSuccess = function (string $key) {
    $q = array_merge($_GET ?: [], ['success' => $key]);
    header('Location: index.php?' . http_build_query($q));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $voucher_id = (int) ($_POST['voucher_id'] ?? 0);
    $rep_id = (int) ($_POST['rep_id'] ?? 0);

    if (in_array($action, ['approve_voucher', 'reject_voucher', 'cancel_voucher', 'approve_replenishment', 'reject_replenishment', 'cancel_replenishment'], true) && !$can_manage) {
        $error = 'Only Admin or Finance can approve, reject, or cancel records.';
    } elseif ($action === 'approve_voucher') {
        $result = approvePettyCashVoucher($voucher_id, $user_id);
        if ($result === true) {
            $redirectSuccess('approved');
        }
        $error = is_string($result) ? $result : 'Failed to approve voucher.';
    } elseif ($action === 'reject_voucher') {
        $reason = trim($_POST['reason'] ?? '');
        $result = rejectPettyCashVoucher($voucher_id, $user_id, $reason);
        if ($result === true) {
            $redirectSuccess('rejected');
        }
        $error = is_string($result) ? $result : 'Failed to reject voucher.';
    } elseif ($action === 'cancel_voucher') {
        $result = cancelPettyCashVoucher($voucher_id);
        if ($result === true) {
            $redirectSuccess('cancelled');
        }
        $error = is_string($result) ? $result : 'Failed to cancel voucher.';
    } elseif ($action === 'approve_replenishment') {
        $result = approvePettyCashReplenishment($rep_id, $user_id);
        if ($result === true) {
            $redirectSuccess('rep_approved');
        }
        $error = is_string($result) ? $result : 'Failed to approve replenishment.';
    } elseif ($action === 'reject_replenishment') {
        $reason = trim($_POST['reason'] ?? '');
        $result = rejectPettyCashReplenishment($rep_id, $user_id, $reason);
        if ($result === true) {
            $redirectSuccess('rep_rejected');
        }
        $error = is_string($result) ? $result : 'Failed to reject replenishment.';
    } elseif ($action === 'cancel_replenishment') {
        $result = cancelPettyCashReplenishment($rep_id);
        if ($result === true) {
            $redirectSuccess('rep_cancelled');
        }
        $error = is_string($result) ? $result : 'Failed to cancel replenishment.';
    }
}

$flash_success = '';
if (isset($_GET['success'])) {
    $messages = [
        'approved' => 'Voucher approved successfully.',
        'rejected' => 'Voucher rejected.',
        'cancelled' => 'Voucher cancelled.',
        'created' => 'Voucher created successfully.',
        'rep_approved' => 'Replenishment approved.',
        'rep_rejected' => 'Replenishment rejected.',
        'rep_cancelled' => 'Replenishment cancelled.',
    ];
    $flash_success = $messages[$_GET['success']] ?? '';
}

$page_title = 'Petty Cash Dashboard';

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
], static fn($v) => $v !== null && $v !== '');
$linkQs = $moduleQs ? '?' . http_build_query($moduleQs) : '';

$company_display = $_SESSION['company_name'] ?? 'Petty Cash';
$rejected_vouchers = 0;
$cancelled_vouchers = 0;
try {
    foreach (['rejected' => &$rejected_vouchers, 'cancelled' => &$cancelled_vouchers] as $status => &$countRef) {
        if ($custodian_scope !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM petty_cash_vouchers WHERE custodian_id = ? AND status = ?');
            $stmt->execute([$custodian_scope, $status]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM petty_cash_vouchers WHERE status = ?');
            $stmt->execute([$status]);
        }
        $countRef = (int) $stmt->fetchColumn();
    }
    unset($countRef);
} catch (Throwable $e) {
    $rejected_vouchers = 0;
    $cancelled_vouchers = 0;
}

$buildViewVoucherUrl = static function (int $id) use ($moduleQs): string {
    $q = $moduleQs;
    $q['id'] = $id;
    return 'view-voucher.php?' . http_build_query($q);
};

$voucher_status = [
    'pending' => (int) $stats['pending_vouchers'],
    'approved' => (int) $stats['approved_vouchers'],
    'rejected' => $rejected_vouchers,
    'cancelled' => $cancelled_vouchers,
];
$voucher_status_total = max(1, array_sum($voucher_status));
$voucher_status_pct = [
    'pending' => round($voucher_status['pending'] / $voucher_status_total * 100, 1),
    'approved' => round($voucher_status['approved'] / $voucher_status_total * 100, 1),
    'rejected' => round($voucher_status['rejected'] / $voucher_status_total * 100, 1),
    'cancelled' => round($voucher_status['cancelled'] / $voucher_status_total * 100, 1),
];

$pending_actions = (int) $stats['pending_vouchers'] + (int) $stats['pending_replenishments'];

function pc_dashboard_format_tzs($n) {
    return 'TZS ' . number_format((float) $n, 2);
}

$statusBadge = static function (string $status): string {
    $st = strtolower($status);
    if ($st === 'approved') {
        return 'badge-green';
    }
    if ($st === 'rejected') {
        return 'badge-red';
    }
    if ($st === 'cancelled') {
        return 'badge-slate';
    }
    return 'badge-orange';
};

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    body.dashboard { font-family: 'Inter', sans-serif; }
    .pc-dash { padding: 1.5rem 2rem 2rem; max-width: 1600px; margin: 0 auto; }
    .kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1.25rem 1.5rem; height: 100%; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .kpi-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 1rem; }
    .kpi-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .kpi-label { font-size: 0.8rem; font-weight: 500; color: #64748b; margin-top: 0.35rem; }
    .kpi-link { font-size: 0.75rem; font-weight: 600; margin-top: 1rem; display: inline-block; text-decoration: none; }
    .kpi-link:hover { text-decoration: underline; }
    .dash-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .dash-card-h { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
    .dash-card-h h3 { font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0; }
    .dash-card-b { padding: 1.25rem; }
    .btn-outline { border: 1px solid #e2e8f0; padding: 0.55rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; color: #1e293b; display: inline-flex; align-items: center; gap: 0.5rem; background: #fff; transition: all 0.2s; text-decoration: none; }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
    .btn-blue { padding: 0.55rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .badge-orange { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; padding: 0.15rem 0.55rem; border-radius: 6px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; }
    .badge-green { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; padding: 0.15rem 0.45rem; border-radius: 6px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
    .badge-red { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; padding: 0.15rem 0.45rem; border-radius: 6px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
    .badge-slate { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; padding: 0.15rem 0.45rem; border-radius: 6px; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; }
    .pc-table { width: 100%; font-size: 0.8rem; border-collapse: collapse; }
    .pc-table th { text-align: left; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; padding: 0.5rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
    .pc-table td { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
    .pc-table tr:last-child td { border-bottom: none; }
    .action-link { font-size: 0.75rem; font-weight: 600; background: none; border: none; padding: 0; cursor: pointer; text-decoration: none; }
    .action-link:hover { text-decoration: underline; }
    .action-link.success { color: #15803d; }
    .action-link.danger { color: #dc2626; }
    .action-link.muted { color: #64748b; }
    .chart-wrap { position: relative; height: 260px; }
    .donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; padding-bottom: 2.5rem; }
    .donut-center .n { font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1; }
    .donut-center .l { font-size: 0.65rem; color: #94a3b8; font-weight: 600; margin-top: 0.15rem; }
    .status-legend { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; margin-top: 0.75rem; font-size: 0.75rem; }
    .status-legend span { display: inline-flex; align-items: center; gap: 0.35rem; color: #475569; font-weight: 600; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; }
    .alert-pc { border-radius: 12px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; }
    .alert-pc.success { background: #f0fdf4; border: 1px solid #dcfce7; color: #15803d; }
    .alert-pc.error { background: #fef2f2; border: 1px solid #fee2e2; color: #b91c1c; }
    .pc-rep-row { display: flex; align-items: flex-start; gap: 0.75rem; }
    .pc-custodian-cell { display: flex; align-items: center; gap: 0.5rem; }
    .pc-name-av .approval-avatar { width: 40px; height: 40px; min-width: 40px; min-height: 40px; border-radius: 50%; flex-shrink: 0; position: relative; overflow: hidden; box-shadow: 0 0 0 2px #fff, 0 0 0 1px #e2e8f0; }
    .pc-name-av--sm .approval-avatar { width: 32px; height: 32px; min-width: 32px; min-height: 32px; }
    .pc-name-av .approval-avatar img, .pc-name-av .approval-avatar .user-av-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .pc-name-av .user-av-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; border-radius: 50%; border: 1px solid transparent; }
    .pc-name-av--sm .user-av-fallback { font-size: 11px; }
    .pc-name-av .user-av--0 .user-av-fallback { background: #E0E7FF; color: #1e1b4b; border-color: #c7d2fe; }
    .pc-name-av .user-av--1 .user-av-fallback { background: #D1FAE5; color: #064e3b; border-color: #a7f3d0; }
    .pc-name-av .user-av--2 .user-av-fallback { background: #FEF3C7; color: #78350f; border-color: #fde68a; }
    .pc-name-av .user-av--3 .user-av-fallback { background: #FFE4E6; color: #881337; border-color: #fecdd3; }
    .pc-name-av .user-av--4 .user-av-fallback { background: #E0F2FE; color: #0c4a6e; border-color: #bae6fd; }
    .pc-name-av .user-av--5 .user-av-fallback { background: #EDE9FE; color: #4c1d95; border-color: #ddd6fe; }
    .pc-name-av .user-av--6 .user-av-fallback { background: #CCFBF1; color: #115e59; border-color: #99f6e4; }
    .pc-name-av .user-av--7 .user-av-fallback { background: #FFEDD5; color: #7c2d12; border-color: #fed7aa; }
    .pc-name-av .user-av--8 .user-av-fallback { background: #F1F5F9; color: #1e293b; border-color: #e2e8f0; }
    .pc-name-av .user-av--9 .user-av-fallback { background: #FAE8FF; color: #701a75; border-color: #f5d0fe; }
    @media (max-width: 992px) { .pc-dash { padding: 1rem; } }
</style>
<script>
function rejectWithReason(formId) {
    const reason = prompt('Please enter rejection reason:');
    if (reason !== null && reason.trim() !== '') {
        const form = document.getElementById(formId);
        if (!form) return;
        let input = form.querySelector('input[name="reason"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'reason';
            form.appendChild(input);
        }
        input.value = reason.trim();
        form.submit();
    }
}
function confirmCancel(isApproved, label) {
    const entity = (typeof label === 'string' && label) ? label : 'voucher';
    let text = 'Cancel this ' + entity + '?';
    if (isApproved === true || isApproved === 'true') {
        text += ' This will reverse the balance impact.';
    }
    return confirm(text);
}
</script>

<main class="main-content">
<div class="pc-dash">
    <header class="flex flex-col lg:flex-row lg:items-start justify-between mb-8 gap-5">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 tracking-tight mb-1">Petty Cash Dashboard</h1>
            <p class="text-slate-500 text-sm"><?= date('l, d M Y') ?></p>
            <?php if ($pending_actions > 0): ?>
            <div class="mt-3 inline-flex items-center gap-2 bg-orange-50 text-orange-700 px-3 py-1.5 rounded-lg border border-orange-100 text-sm font-medium">
                <i class="fas fa-exclamation-triangle"></i>
                <?= (int) $pending_actions ?> pending approval<?= $pending_actions === 1 ? '' : 's' ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="create-voucher.php<?= htmlspecialchars($linkQs) ?>" class="btn-blue"><i class="fas fa-ticket"></i> New Voucher</a>
            <a href="replenishment.php<?= htmlspecialchars($linkQs) ?>" class="btn-outline"><i class="fas fa-plus text-blue-500"></i> Request Top-up</a>
            <a href="categories/index.php<?= htmlspecialchars($linkQs) ?>" class="btn-outline"><i class="fas fa-folder"></i> Categories</a>
            <a href="reports.php<?= htmlspecialchars($linkQs) ?>" class="btn-outline"><i class="fas fa-file-alt"></i> Reports</a>
        </div>
    </header>

    <?php if ($flash_success !== ''): ?>
        <div class="alert-pc success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert-pc error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <div class="kpi-card">
            <div class="kpi-icon bg-blue-50 text-blue-500"><i class="fas fa-wallet"></i></div>
            <div class="kpi-value" style="font-size:1.15rem;"><?= htmlspecialchars(pc_dashboard_format_tzs($stats['total_balance'])) ?></div>
            <div class="kpi-label">Total Balance</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-red-50 text-red-500"><i class="fas fa-receipt"></i></div>
            <div class="kpi-value" style="font-size:1.15rem;"><?= htmlspecialchars(pc_dashboard_format_tzs($stats['total_spent'])) ?></div>
            <div class="kpi-label">Total Spent</div>
            <a href="reports.php<?= htmlspecialchars($linkQs) ?>" class="kpi-link">View reports</a>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-orange-50 text-orange-500"><i class="fas fa-clock"></i></div>
            <div class="kpi-value"><?= number_format((int) $stats['pending_vouchers']) ?></div>
            <div class="kpi-label">Pending Vouchers</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-green-50 text-green-500"><i class="fas fa-check-circle"></i></div>
            <div class="kpi-value"><?= number_format((int) $stats['approved_vouchers']) ?></div>
            <div class="kpi-label">Approved Vouchers</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-purple-50 text-purple-500"><i class="fas fa-coins"></i></div>
            <div class="kpi-value"><?= number_format((int) $stats['pending_replenishments']) ?></div>
            <div class="kpi-label">Pending Top-ups</div>
            <a href="replenishment.php<?= htmlspecialchars($linkQs) ?>" class="kpi-link">View requests</a>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 mb-8">
        <div class="xl:col-span-6 dash-card">
            <div class="dash-card-h">
                <h3>Petty Cash Flow</h3>
                <span class="text-[10px] font-bold text-slate-400 uppercase">Last 6 months · approved only</span>
            </div>
            <div class="dash-card-b">
                <div class="chart-wrap"><canvas id="cashFlowChart"></canvas></div>
                <div class="status-legend">
                    <span><span class="status-dot bg-green-500"></span> Top-ups (in)</span>
                    <span><span class="status-dot bg-red-500"></span> Vouchers (out)</span>
                </div>
            </div>
        </div>

        <div class="xl:col-span-6 dash-card">
            <div class="dash-card-h"><h3>Voucher Status</h3></div>
            <div class="dash-card-b">
                <div class="chart-wrap">
                    <canvas id="voucherStatusChart"></canvas>
                    <div class="donut-center">
                        <span class="n"><?= number_format($voucher_status_total) ?></span>
                        <span class="l">Total Vouchers</span>
                    </div>
                </div>
                <div class="status-legend">
                    <span><span class="status-dot bg-orange-500"></span> Pending <?= $voucher_status['pending'] ?> (<?= $voucher_status_pct['pending'] ?>%)</span>
                    <span><span class="status-dot bg-green-500"></span> Approved <?= $voucher_status['approved'] ?> (<?= $voucher_status_pct['approved'] ?>%)</span>
                    <span><span class="status-dot bg-red-500"></span> Rejected <?= $voucher_status['rejected'] ?> (<?= $voucher_status_pct['rejected'] ?>%)</span>
                    <span><span class="status-dot bg-slate-400"></span> Cancelled <?= $voucher_status['cancelled'] ?> (<?= $voucher_status_pct['cancelled'] ?>%)</span>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 mb-8">
        <div class="xl:col-span-8 dash-card">
            <div class="dash-card-h">
                <h3>Recent Vouchers</h3>
                <a href="vouchers/index.php<?= htmlspecialchars($linkQs) ?>" class="text-xs font-bold text-purple-600 hover:underline">View all</a>
            </div>
            <div class="dash-card-b p-0 overflow-x-auto">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th>Voucher #</th>
                            <th>Date</th>
                            <?php if ($can_manage): ?><th>Custodian</th><?php endif; ?>
                            <th>Category</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                        <tr><td colspan="<?= $can_manage ? 7 : 6 ?>" class="text-center text-slate-400 py-8">No vouchers yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($vouchers as $voucher): ?>
                            <?php $st = strtolower((string) $voucher['status']); ?>
                            <tr>
                                <td class="font-semibold text-slate-800"><?= htmlspecialchars($voucher['voucher_number']) ?></td>
                                <td class="text-slate-500"><?= date('d M Y', strtotime($voucher['date'])) ?></td>
                                <?php if ($can_manage): ?>
                                <td><?= pettyCashRenderCustodianCell($voucher, 32) ?></td>
                                <?php endif; ?>
                                <td class="text-slate-600"><?= htmlspecialchars($voucher['category']) ?></td>
                                <td class="text-right font-bold text-slate-800">TZS <?= number_format((float) $voucher['amount'], 0) ?></td>
                                <td><span class="<?= $statusBadge($st) ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
                                <td class="text-right whitespace-nowrap">
                                    <?php
                                    $viewUrl = $buildViewVoucherUrl((int) $voucher['id']);
                                    $reject_form_id = 'reject-voucher-' . (int) $voucher['id'];
                                    include __DIR__ . '/includes/voucher-row-actions.php';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="xl:col-span-4 dash-card flex flex-col">
            <div class="dash-card-h">
                <h3>Recent Top-up Requests</h3>
                <a href="replenishments/index.php<?= htmlspecialchars($linkQs) ?>" class="text-xs font-bold text-purple-600 hover:underline">View all</a>
            </div>
            <div class="dash-card-b flex-1 flex flex-col">
                <?php if (empty($replenishments)): ?>
                <div class="flex-1 flex flex-col items-center justify-center text-center py-10">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-5">
                        <i class="fas fa-coins text-slate-200 text-3xl"></i>
                    </div>
                    <h4 class="font-bold text-slate-800">No top-up requests</h4>
                    <p class="text-sm text-slate-400 mt-2 max-w-xs">Replenishment requests will appear here once submitted.</p>
                </div>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($replenishments as $rep): ?>
                        <?php $st = strtolower((string) $rep['status']); ?>
                    <?php
                    $custodianName = (string) ($rep['custodian_name'] ?? '');
                    $custodianPhoto = function_exists('user_avatar_photo_url')
                        ? user_avatar_photo_url($rep['custodian_photo'] ?? '')
                        : '';
                    ?>
                    <div class="flex justify-between gap-3 border-b border-slate-50 pb-3 last:border-0">
                        <div class="pc-rep-row pc-name-av min-w-0 flex-1">
                            <?= render_approval_flow_avatar($custodianName, $custodianPhoto, 40) ?>
                            <div class="min-w-0">
                            <div class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($rep['replenishment_number']) ?></div>
                            <div class="text-[10px] text-slate-400 font-bold uppercase"><?= htmlspecialchars($custodianName) ?> · <?= date('d M Y', strtotime($rep['created_at'])) ?></div>
                            <?php if ($st === 'pending' && $can_manage): ?>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <?php
                                $repApproveQs = array_filter([
                                    'module' => $_GET['module'] ?? 'petty_cash',
                                    'company_slug' => $_GET['company_slug'] ?? null,
                                    'rep_id' => (int) $rep['id'],
                                ], static fn($v) => $v !== null && $v !== '');
                                ?>
                                <a href="replenishments/confirm-approve.php?<?= htmlspecialchars(http_build_query($repApproveQs)) ?>" class="action-link success">Approve</a>
                                <button type="button" class="action-link danger" onclick="rejectWithReason('reject-rep-<?= (int) $rep['id'] ?>')">Reject</button>
                                <form method="POST" class="d-inline" id="reject-rep-<?= (int) $rep['id'] ?>"><input type="hidden" name="action" value="reject_replenishment"><input type="hidden" name="rep_id" value="<?= (int) $rep['id'] ?>"></form>
                            </div>
                            <?php endif; ?>
                            <?php if (in_array($st, ['pending', 'approved'], true) && $can_manage): ?>
                            <form method="POST" class="mt-1"><input type="hidden" name="action" value="cancel_replenishment"><input type="hidden" name="rep_id" value="<?= (int) $rep['id'] ?>"><button type="submit" class="action-link muted" onclick="return confirmCancel(<?= $st === 'approved' ? 'true' : 'false' ?>, 'replenishment');">Cancel</button></form>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-sm font-bold text-slate-800">TZS <?= number_format((float) $rep['amount'], 0) ?></div>
                            <span class="<?= $statusBadge($st) ?> mt-1 inline-block"><?= htmlspecialchars(ucfirst($st)) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="text-center pb-6 text-[11px] text-slate-400 font-medium uppercase tracking-widest">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($company_display) ?>. All rights reserved.
    </footer>
</div>
</main>

<script>
(function () {
    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } }
    };

    const flowLabels = <?= json_encode($cash_flow['labels']) ?>;
    const flowIn = <?= json_encode($cash_flow['inflow']) ?>;
    const flowOut = <?= json_encode($cash_flow['outflow']) ?>;
    const flowCanvas = document.getElementById('cashFlowChart');
    if (flowCanvas && flowLabels.length) {
        const flowMax = Math.max.apply(null, flowIn.concat(flowOut).concat([1]));
        new Chart(flowCanvas, {
            type: 'line',
            data: {
                labels: flowLabels,
                datasets: [
                    {
                        label: 'Top-ups',
                        data: flowIn,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#22c55e'
                    },
                    {
                        label: 'Vouchers',
                        data: flowOut,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.06)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#ef4444'
                    }
                ]
            },
            options: {
                ...chartDefaults,
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const v = Number(ctx.raw) || 0;
                                const s = 'TZS ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                return ctx.dataset.label + ': ' + s;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: {
                        beginAtZero: true,
                        suggestedMax: flowMax < 10000 ? 10000 : Math.ceil(flowMax * 1.15),
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            maxTicksLimit: 6,
                            callback: function (v) {
                                return 'TZS ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
                            }
                        }
                    }
                }
            }
        });
    }

    const statusData = [
        <?= (int) $voucher_status['pending'] ?>,
        <?= (int) $voucher_status['approved'] ?>,
        <?= (int) $voucher_status['rejected'] ?>,
        <?= (int) $voucher_status['cancelled'] ?>
    ];
    const canvas = document.getElementById('voucherStatusChart');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Approved', 'Rejected', 'Cancelled'],
            datasets: [{
                data: statusData,
                backgroundColor: ['#f97316', '#22c55e', '#ef4444', '#94a3b8'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            const total = statusData.reduce((a, b) => a + b, 0) || 1;
                            const pct = ((ctx.raw / total) * 100).toFixed(1);
                            return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
