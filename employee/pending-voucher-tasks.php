<?php
/**
 * Payment Vouchers that currently require action from the logged-in user.
 * Uses the same layout shell as the voucher admin/employee dashboard.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'voucher';
}
$_SESSION['active_module'] = 'voucher';
$active_module = 'voucher';
$qsModule = 'voucher';

$isAdminViewer = function_exists('isAdmin') && isAdmin();

$selfUrl = function_exists('company_url')
    ? company_url('employee/pending-voucher-tasks.php') . '?module=voucher'
    : (function_exists('app_url')
        ? app_url('/employee/pending-voucher-tasks.php?module=voucher')
        : 'pending-voucher-tasks.php?module=voucher');

$flashMsg = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['voucher_id']) && (string) $_POST['action'] === 'delete') {
    $deleteId = (int) $_POST['voucher_id'];
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($deleteId <= 0) {
        $flashError = 'Invalid voucher.';
    } elseif (!function_exists('canDeleteVoucher') || !canDeleteVoucher($deleteId, $uid)) {
        $flashError = 'You do not have permission to delete this voucher.';
    } else {
        $ok = false;
        if (function_exists('deleteVoucherHard')) {
            $ok = (bool) deleteVoucherHard($deleteId, $uid);
        } else {
            try {
                global $pdo;
                $st = $pdo->prepare('DELETE FROM payment_vouchers WHERE id = ?');
                $st->execute([$deleteId]);
                $ok = $st->rowCount() > 0;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        if ($ok) {
            header('Location: ' . $selfUrl . '&msg=deleted');
            exit;
        }
        $flashError = 'Could not delete the voucher. Please try again.';
    }
}

if (isset($_GET['msg']) && (string) $_GET['msg'] === 'deleted') {
    $flashMsg = 'Voucher deleted permanently.';
}

require_once __DIR__ . '/dashboard-ui/lib.php';
$assets = function_exists('dashboardUiLoadReactAssets') ? dashboardUiLoadReactAssets() : null;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = function_exists('resolveVoucherSessionDisplayName')
    ? resolveVoucherSessionDisplayName($pdo)
    : trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

$tasks = function_exists('getPendingPaymentVoucherTasks')
    ? getPendingPaymentVoucherTasks($pdo, $userId, $userName, 100)
    : array();

$fmtMoney = static function ($amount, $currency = 'TZS'): string {
    return trim((string) $currency) . ' ' . number_format((float) $amount, 2);
};

$fmtDate = static function ($raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts ? date('d M Y', $ts) : $raw;
};

$taskCount = count($tasks);

$GLOBALS['_erp_header_style_linked'] = false;
$employeeHeaderTitle = 'PV Tasks';
$employeeHeaderSubtitle = $taskCount === 1
    ? '1 voucher waiting for your action'
    : ($taskCount . ' vouchers waiting for your action');
$hideHeaderCompanyBranding = true;
$employeeHeaderCenterHtml = null;
$employeeHeaderRightHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV Tasks - Payment Voucher</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if ($assets !== null && !empty($assets['cssFile'])): ?>
        <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/nav-back-script.php'; ?>
    <style>
        :root { --bg-body: #f1f5f9; --header-height: 72px; --ad-header-h: 64px; }
        body.dashboard { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }

        .main-content.dashboard-react-root {
            width: 100% !important;
            max-width: none !important;
            padding: 0.35rem 1.25rem 2rem !important;
            box-sizing: border-box;
            background: #f1f5f9 !important;
        }

        body.dashboard .header.admin-header,
        body.dashboard .header.employee-header {
            background: #f1f5f9 !important;
            border: none !important;
            box-shadow: none !important;
            height: auto !important;
            min-height: 64px;
            overflow: visible !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1020 !important;
            padding-bottom: 0 !important;
        }
        body.dashboard .header.admin-header::after,
        body.dashboard .header.employee-header::after { display: none !important; }

        body.dashboard .header.admin-header .header-content,
        body.dashboard .header.employee-header .header-content {
            display: grid !important;
            grid-template-columns: auto minmax(280px, 1fr) auto;
            align-items: center !important;
            gap: 14px;
            min-height: 64px;
            padding: 8px 20px !important;
            position: static !important;
        }
        body.dashboard .header.admin-header .header-left,
        body.dashboard .header.employee-header .header-left { display: none !important; }
        body.dashboard .header.admin-header .employee-header-page-heading,
        body.dashboard .header.employee-header .employee-header-page-heading {
            grid-column: 1;
            margin-left: 0 !important;
        }
        body.dashboard .admin-header-center-slot,
        body.dashboard .employee-header-center-slot {
            grid-column: 2;
            min-width: 0;
        }
        body.dashboard .header.admin-header .header-right.header-actions-tray,
        body.dashboard .header.employee-header .header-right.header-actions-tray {
            grid-column: 3;
            margin: 0 !important;
        }

        body.dashboard .ad-header-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-width: 0;
            justify-content: flex-end;
        }
        body.dashboard .ad-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            margin-left: auto;
        }
        .ad-chip-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none !important;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: background .15s, box-shadow .15s, transform .15s;
        }
        .ad-chip-btn--ghost {
            background: #fff;
            color: #374151 !important;
            border-color: #e5e7eb;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .ad-chip-btn--ghost:hover { background: #f8fafc; color: #111827 !important; }
        .ad-chip-btn--primary {
            background: #6d5df6;
            color: #fff !important;
            box-shadow: 0 6px 16px rgba(109, 93, 246, .25);
        }
        .ad-chip-btn--primary:hover {
            background: #5b4bd6;
            color: #fff !important;
            transform: translateY(-1px);
        }

        .pv-tasks-intro {
            margin: 0 0 12px;
            color: #64748b;
            font-size: 0.9rem;
        }
        .pv-tasks-empty {
            padding: 2.75rem 1.5rem;
            text-align: center;
            color: #64748b;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
        }
        .pv-tasks-empty strong {
            display: block;
            color: #0f172a;
            font-size: 1.05rem;
            margin-bottom: 0.35rem;
        }
        .pv-tasks-action {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .ed-table tbody tr.pv-task-row {
            cursor: pointer;
        }
        .pv-tasks-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            height: 30px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid #fecaca;
            background: #fff;
            color: #dc2626;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .pv-tasks-delete:hover {
            background: #fef2f2;
            border-color: #f87171;
        }
        .pv-tasks-flash {
            margin: 0 0 12px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .pv-tasks-flash--ok {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .pv-tasks-flash--err {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Fallback desk table if dashboard CSS is missing */
        .ed-table-wrap {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            max-width: 100%;
        }
        .ed-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .ed-table thead th {
            background: #1e293b;
            color: #f8fafc;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .04em;
            padding: 10px 8px;
            text-align: left;
            white-space: nowrap;
        }
        .ed-table tbody td {
            font-size: 12px;
            padding: 9px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
            color: #334155;
        }
        .ed-table tbody tr:nth-child(odd) td { background: #fff; }
        .ed-table tbody tr:nth-child(even) td { background: #f1f5f9; }
        .ed-table tbody tr:hover td { background: #e2e8f0 !important; }
        .ed-vno { font-weight: 600; color: #334155; white-space: nowrap; }
        .ed-amt { font-weight: 600; color: #334155; white-space: nowrap; }
        .ed-muted { color: #94a3b8; font-size: 11px; }

        html[data-theme="dark"] body.dashboard,
        html[data-theme="dark"] .main-content.dashboard-react-root,
        html[data-theme="dark"] body.dashboard .header.admin-header,
        html[data-theme="dark"] body.dashboard .header.employee-header {
            background: #0f172a !important;
        }
        html[data-theme="dark"] body.dashboard .employee-header-page-title { color: #f8fafc !important; }
        html[data-theme="dark"] body.dashboard .employee-header-page-subtitle { color: #94a3b8 !important; }
        html[data-theme="dark"] .ad-chip-btn--ghost {
            background: #1e293b;
            color: #e2e8f0 !important;
            border-color: #334155;
        }
        html[data-theme="dark"] .pv-tasks-empty {
            background: #1e293b;
            border-color: #334155;
            color: #94a3b8;
        }
        html[data-theme="dark"] .pv-tasks-empty strong { color: #f8fafc; }
        html[data-theme="dark"] .pv-tasks-intro { color: #94a3b8; }
        html[data-theme="dark"] .ed-table-wrap { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .ed-table tbody td { color: #e2e8f0; border-bottom-color: #334155; }
        html[data-theme="dark"] .ed-table tbody tr:nth-child(odd) td { background: #1e293b; }
        html[data-theme="dark"] .ed-table tbody tr:nth-child(even) td { background: #0f172a; }

        @media (max-width: 991.98px) {
            body.dashboard .header.admin-header .header-left,
            body.dashboard .header.employee-header .header-left {
                display: flex !important;
            }
            body.dashboard .header.admin-header .header-content,
            body.dashboard .header.employee-header .header-content {
                grid-template-columns: auto auto 1fr !important;
                grid-template-rows: auto auto;
            }
            body.dashboard .header.admin-header .employee-header-page-heading,
            body.dashboard .header.employee-header .employee-header-page-heading {
                grid-column: 2;
                grid-row: 1;
            }
            body.dashboard .admin-header-center-slot,
            body.dashboard .employee-header-center-slot {
                grid-column: 1 / -1;
                grid-row: 2;
            }
            .main-content.dashboard-react-root {
                padding: 0.5rem 0.85rem 5.5rem !important;
            }
        }
        @media (max-width: 720px) {
            .ed-table thead { display: none; }
            .ed-table tbody tr {
                display: block;
                padding: 0.85rem 0.75rem;
                border-bottom: 1px solid #e5e7eb;
            }
            .ed-table tbody td {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                border: none;
                padding: 0.25rem 0;
                background: transparent !important;
            }
            .ed-table tbody td::before {
                content: attr(data-label);
                color: #94a3b8;
                font-size: 0.7rem;
                font-weight: 600;
                text-transform: uppercase;
            }
        }
    </style>
</head>
<body class="dashboard">
    <?php
    if ($isAdminViewer) {
        require_once __DIR__ . '/../includes/header_admin.php';
    } else {
        require_once __DIR__ . '/../includes/header_employee.php';
    }
    ?>

    <main class="main-content dashboard-react-root">
        <?php if ($flashMsg !== ''): ?>
            <div class="pv-tasks-flash pv-tasks-flash--ok" role="status"><?= htmlspecialchars($flashMsg) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="pv-tasks-flash pv-tasks-flash--err" role="alert"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <p class="pv-tasks-intro">
            Only vouchers that currently need <strong>your</strong> signature or finance action are listed here.
        </p>

        <?php if (empty($tasks)): ?>
            <div class="pv-tasks-empty">
                <strong>No payment vouchers need your action</strong>
                You're all caught up. The badge will appear again when a voucher reaches your turn.
            </div>
        <?php else: ?>
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead>
                        <tr>
                            <th>Voucher</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Required action</th>
                            <th>Payee / Prepared by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $viewUrl = (string) ($task['view_url'] ?? '');
                            $taskId = (int) ($task['id'] ?? 0);
                            $canDelete = $taskId > 0 && function_exists('canDeleteVoucher') && canDeleteVoucher($taskId, $userId);
                            $voucherLabel = (string) (($task['voucher_no'] ?? '') !== '' ? $task['voucher_no'] : ('#' . $taskId));
                            ?>
                            <tr
                                class="pv-task-row"
                                data-href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                tabindex="0"
                                role="link"
                                aria-label="Open voucher <?= htmlspecialchars($voucherLabel, ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <td data-label="Voucher">
                                    <span class="ed-vno"><?= htmlspecialchars($voucherLabel) ?></span>
                                </td>
                                <td data-label="Date"><?= htmlspecialchars($fmtDate($task['date_created'] ?? '')) ?></td>
                                <td data-label="Amount" class="ed-amt"><?= htmlspecialchars($fmtMoney($task['total_amount'] ?? 0, $task['currency'] ?? 'TZS')) ?></td>
                                <td data-label="Action">
                                    <span class="pv-tasks-action"><?= htmlspecialchars((string) ($task['action_label'] ?? 'Action required')) ?></span>
                                </td>
                                <td data-label="Details">
                                    <?= htmlspecialchars((string) (($task['payee_name'] ?? '') !== '' ? $task['payee_name'] : '—')) ?>
                                    <?php if (!empty($task['prepared_by'])): ?>
                                        <div class="ed-muted">Prepared by <?= htmlspecialchars((string) $task['prepared_by']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Delete" onclick="event.stopPropagation();">
                                    <?php if ($canDelete): ?>
                                        <form method="post" action="<?= htmlspecialchars($selfUrl, ENT_QUOTES, 'UTF-8') ?>" class="pv-tasks-delete-form" onsubmit="return confirm('Delete this voucher permanently? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="voucher_id" value="<?= $taskId ?>">
                                            <button type="submit" class="pv-tasks-delete" title="Delete voucher">
                                                <i class="fas fa-trash-alt" aria-hidden="true"></i> Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="ed-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        var header = document.querySelector('.header.admin-header, .header.employee-header');
        if (header) {
            function syncHeaderHeight() {
                var h = Math.round(header.getBoundingClientRect().height);
                if (h > 0) {
                    document.documentElement.style.setProperty('--ad-header-h', h + 'px');
                }
            }
            syncHeaderHeight();
            window.addEventListener('resize', syncHeaderHeight);
            if (window.ResizeObserver) {
                new ResizeObserver(syncHeaderHeight).observe(header);
            }
        }

        function openTaskRow(row) {
            if (!row) return;
            var href = row.getAttribute('data-href') || '';
            if (href) {
                window.location.href = href;
            }
        }

        document.querySelectorAll('tr.pv-task-row[data-href]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('button, form, a, input, label')) {
                    return;
                }
                openTaskRow(row);
            });
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.target.closest('button, form, a, input, label')) {
                        return;
                    }
                    e.preventDefault();
                    openTaskRow(row);
                }
            });
        });
    })();
    </script>
</body>
</html>
