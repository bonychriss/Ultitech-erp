<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$can_manage = pettyCashCanManage();
$error = '';

$voucher_id = (int) ($_GET['id'] ?? 0);

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $result = approvePettyCashVoucher($voucher_id, $user_id);
        if ($result === true) {
            header('Location: index.php?' . http_build_query(array_merge($moduleQs, ['success' => 'approved'])));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to approve voucher.';
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        $result = rejectPettyCashVoucher($voucher_id, $user_id, $reason);
        if ($result === true) {
            header('Location: index.php?' . http_build_query(array_merge($moduleQs, ['success' => 'rejected'])));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to reject voucher.';
    } elseif ($action === 'cancel') {
        $result = cancelPettyCashVoucher($voucher_id);
        if ($result === true) {
            header('Location: index.php?' . http_build_query(array_merge($moduleQs, ['success' => 'cancelled'])));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to cancel voucher.';
    }
}

$voucher = getPettyCashVoucher($voucher_id);

if (!$voucher) {
    die('Voucher not found.');
}

if (!$can_manage && (int) $voucher['custodian_id'] !== $user_id) {
    die('Access denied.');
}

$receiptUrl = !empty($voucher['receipt_path'])
    ? (function_exists('app_url') ? app_url('/' . ltrim($voucher['receipt_path'], '/')) : '../../' . ltrim($voucher['receipt_path'], '/'))
    : '';
$st = strtolower((string) $voucher['status']);

$backListUrl = 'vouchers/index.php?' . http_build_query($moduleQs);
$dashboardUrl = 'index.php?' . http_build_query($moduleQs);

$voucherNumber = htmlspecialchars($voucher['voucher_number'] ?? 'N/A');
$createdAtTs = strtotime((string) ($voucher['created_at'] ?? 'now'));
$createdAtLabel = date('F d, Y g:i A', $createdAtTs);
$voucherDateLabel = date('F d, Y', strtotime((string) ($voucher['date'] ?? 'now')));
$amountLabel = 'TSH ' . number_format((float) ($voucher['amount'] ?? 0), 2);
$descriptionText = trim((string) ($voucher['description'] ?? ''));
$categoryLabel = htmlspecialchars((string) ($voucher['category'] ?? '—'));
$custodianLabel = htmlspecialchars((string) ($voucher['custodian_name'] ?? 'Unknown'));
$createdByLabel = htmlspecialchars((string) ($voucher['created_by_name'] ?? 'Unknown'));

$statusClass = 'pc-badge-pending';
if ($st === 'approved') {
    $statusClass = 'pc-badge-approved';
} elseif ($st === 'rejected' || $st === 'cancelled') {
    $statusClass = 'pc-badge-rejected';
}
$statusLabel = strtoupper(htmlspecialchars((string) $voucher['status']));

$receiptExt = $receiptUrl !== '' ? strtolower(pathinfo(parse_url($receiptUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) : '';
$receiptIsImage = in_array($receiptExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);

$page_title = 'Voucher ' . ($voucher['voucher_number'] ?? '');
include __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
        body.dashboard { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
        .pc-view {
            max-width: 1100px;
            margin: 0 auto;
            padding: 1.5rem 2rem 2rem;
        }
        .pc-view-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .pc-view-topbar h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .pc-view-topbar .pc-sub {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.2rem;
        }
        .pc-btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s;
            white-space: nowrap;
        }
        .pc-btn-back:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .pc-alert {
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 8px;
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-size: 14px;
        }

        .pc-summary {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .pc-summary-left {
            display: flex;
            align-items: center;
            gap: 20px;
            min-width: 0;
        }
        .pc-summary-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #fef3c7;
            color: #d97706;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }
        .pc-summary-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .pc-summary-id {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .pc-summary-created {
            font-size: 13px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pc-summary-right {
            text-align: right;
            flex-shrink: 0;
        }
        .pc-badge {
            display: inline-block;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .pc-badge-pending { background: #fef3c7; color: #b45309; }
        .pc-badge-approved { background: #d1fae5; color: #047857; }
        .pc-badge-rejected { background: #fee2e2; color: #b91c1c; }
        .pc-amount-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }
        .pc-amount-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: #059669;
            line-height: 1.1;
        }

        .pc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .pc-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .pc-card-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        .pc-card-head i.icon-info { color: #7c3aed; }
        .pc-card-head i.icon-receipt { color: #7c3aed; }
        .pc-card-head i.icon-desc { color: #7c3aed; }
        .pc-card-body { padding: 8px 20px 20px; }

        .pc-info-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        .pc-info-row:last-child { border-bottom: none; }
        .pc-info-icon {
            width: 20px;
            text-align: center;
            color: #94a3b8;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .pc-info-icon.icon-amount { color: #059669; }
        .pc-info-label {
            color: #64748b;
            min-width: 110px;
            flex-shrink: 0;
        }
        .pc-info-value {
            color: #0f172a;
            font-weight: 500;
            flex: 1;
        }
        .pc-info-value.is-amount {
            color: #059669;
            font-weight: 700;
        }
        .pc-info-value.is-danger { color: #dc2626; }

        .pc-receipt-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .pc-receipt-preview img {
            max-width: 100%;
            max-height: 320px;
            object-fit: contain;
            display: block;
            cursor: pointer;
        }
        .pc-receipt-empty {
            text-align: center;
            color: #94a3b8;
            padding: 48px 16px;
            font-size: 14px;
        }
        .pc-receipt-empty i {
            font-size: 36px;
            margin-bottom: 12px;
            display: block;
            color: #cbd5e1;
        }
        .pc-btn-view {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.15s;
        }
        .pc-btn-view.is-disabled {
            pointer-events: none;
            opacity: 0.5;
            cursor: default;
        }

        .pc-desc-card { margin-bottom: 20px; }
        .pc-desc-body {
            padding: 16px 20px 20px;
            font-size: 14px;
            line-height: 1.6;
            color: #334155;
            white-space: pre-wrap;
        }
        .pc-desc-empty { color: #94a3b8; font-style: italic; }

        .pc-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .pc-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.15s, transform 0.1s;
        }
        .pc-btn:active { transform: scale(0.98); }
        .pc-btn-approve { background: #059669; color: #fff; }
        .pc-btn-approve:hover { background: #047857; }
        .pc-btn-reject { background: #dc2626; color: #fff; }
        .pc-btn-reject:hover { background: #b91c1c; }
        .pc-btn-cancel {
            background: #fff;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .pc-btn-cancel:hover { background: #f8fafc; }

        .pc-reject-panel {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 12px;
        }
        .pc-reject-panel label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #92400e;
            font-size: 14px;
        }
        .pc-reject-panel textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            min-height: 90px;
            margin-bottom: 12px;
            resize: vertical;
        }
        .pc-reject-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        @media (max-width: 900px) {
            .pc-grid { grid-template-columns: 1fr; }
            .pc-summary { flex-direction: column; align-items: flex-start; }
            .pc-summary-right { text-align: left; width: 100%; }
        }
        @media (max-width: 600px) {
            .pc-view { padding: 1rem; }
            .pc-summary { padding: 18px; }
            .pc-summary-icon { width: 56px; height: 56px; font-size: 22px; }
            .pc-amount-value { font-size: 1.4rem; }
            .pc-info-label { min-width: 90px; }
            .pc-actions { justify-content: stretch; }
            .pc-btn { flex: 1; justify-content: center; }
        }
    </style>

<main class="main-content">
<div class="pc-view">
    <div class="pc-view-topbar">
        <div>
            <h1>Voucher Details</h1>
            <p class="pc-sub"><?= $voucherNumber ?></p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="pc-btn-back">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="<?= htmlspecialchars($backListUrl) ?>" class="pc-btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
        <?php if ($error !== ''): ?>
            <div class="pc-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <section class="pc-summary">
            <div class="pc-summary-left">
                <div class="pc-summary-icon" aria-hidden="true">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div>
                    <h1 class="pc-summary-title">Voucher Details</h1>
                    <p class="pc-summary-id"><?= $voucherNumber ?></p>
                    <p class="pc-summary-created">
                        <i class="far fa-calendar"></i>
                        Created: <?= htmlspecialchars($createdAtLabel) ?>
                    </p>
                </div>
            </div>
            <div class="pc-summary-right">
                <span class="pc-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                <div class="pc-amount-label">Amount</div>
                <div class="pc-amount-value"><?= htmlspecialchars($amountLabel) ?></div>
            </div>
        </section>

        <div class="pc-grid">
            <div class="pc-card">
                <div class="pc-card-head">
                    <i class="fas fa-circle-info icon-info"></i>
                    Voucher Information
                </div>
                <div class="pc-card-body">
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="far fa-calendar"></i></span>
                        <span class="pc-info-label">Date</span>
                        <span class="pc-info-value"><?= htmlspecialchars($voucherDateLabel) ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-folder"></i></span>
                        <span class="pc-info-label">Category</span>
                        <span class="pc-info-value"><?= $categoryLabel ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon icon-amount"><i class="fas fa-dollar-sign"></i></span>
                        <span class="pc-info-label">Amount</span>
                        <span class="pc-info-value is-amount"><?= htmlspecialchars($amountLabel) ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-align-left"></i></span>
                        <span class="pc-info-label">Description</span>
                        <span class="pc-info-value"><?= $descriptionText !== '' ? nl2br(htmlspecialchars($descriptionText)) : '—' ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-user"></i></span>
                        <span class="pc-info-label">Custodian</span>
                        <span class="pc-info-value"><?= $custodianLabel ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-user-pen"></i></span>
                        <span class="pc-info-label">Created By</span>
                        <span class="pc-info-value"><?= $createdByLabel ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="far fa-clock"></i></span>
                        <span class="pc-info-label">Created At</span>
                        <span class="pc-info-value"><?= htmlspecialchars($createdAtLabel) ?></span>
                    </div>
                    <?php if (!empty($voucher['approved_by'])): ?>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-user-check"></i></span>
                        <span class="pc-info-label">Reviewed By</span>
                        <span class="pc-info-value"><?= htmlspecialchars((string) ($voucher['approved_by_name'] ?? '—')) ?></span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="far fa-calendar-check"></i></span>
                        <span class="pc-info-label">Reviewed At</span>
                        <span class="pc-info-value"><?= date('F d, Y g:i A', strtotime((string) $voucher['approved_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($voucher['rejection_reason'])): ?>
                    <div class="pc-info-row">
                        <span class="pc-info-icon"><i class="fas fa-comment-dots"></i></span>
                        <span class="pc-info-label">Rejection Reason</span>
                        <span class="pc-info-value is-danger"><?= nl2br(htmlspecialchars((string) $voucher['rejection_reason'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pc-card">
                <div class="pc-card-head">
                    <i class="fas fa-paperclip icon-receipt"></i>
                    Receipt / Attachment
                </div>
                <div class="pc-card-body">
                    <div class="pc-receipt-preview">
                        <?php if ($receiptUrl !== '' && $receiptIsImage): ?>
                            <img src="<?= htmlspecialchars($receiptUrl) ?>" alt="Receipt preview"
                                 onclick="window.open('<?= htmlspecialchars($receiptUrl, ENT_QUOTES) ?>', '_blank')">
                        <?php elseif ($receiptUrl !== ''): ?>
                            <div class="pc-receipt-empty">
                                <i class="fas fa-file-pdf"></i>
                                Attachment uploaded
                            </div>
                        <?php else: ?>
                            <div class="pc-receipt-empty">
                                <i class="fas fa-image"></i>
                                No receipt attached
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($receiptUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank" rel="noopener" class="pc-btn-view">
                            <i class="fas fa-up-right-from-square"></i> View Full Size
                        </a>
                    <?php else: ?>
                        <span class="pc-btn-view is-disabled">
                            <i class="fas fa-up-right-from-square"></i> View Full Size
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <section class="pc-card pc-desc-card">
            <div class="pc-card-head">
                <i class="fas fa-file-lines icon-desc"></i>
                Description
            </div>
            <div class="pc-desc-body">
                <?php if ($descriptionText !== ''): ?>
                    <?= nl2br(htmlspecialchars($descriptionText)) ?>
                <?php else: ?>
                    <span class="pc-desc-empty">No description provided.</span>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($can_manage && $st === 'pending'): ?>
            <form method="POST" id="approveForm" style="display: none;"><input type="hidden" name="action" value="approve"></form>
            <div id="rejectForm" class="pc-reject-panel">
                <form method="POST" onsubmit="validateReject(event)">
                    <input type="hidden" name="action" value="reject">
                    <label for="rejectReason">Rejection Reason *</label>
                    <textarea id="rejectReason" name="reason" placeholder="Explain why this voucher is being rejected..."></textarea>
                    <div class="pc-reject-actions">
                        <button type="submit" class="pc-btn pc-btn-reject"><i class="fas fa-check"></i> Confirm Rejection</button>
                        <button type="button" class="pc-btn pc-btn-cancel" onclick="hideRejectForm()">Cancel</button>
                    </div>
                </form>
            </div>
            <div class="pc-actions">
                <button type="button" onclick="document.getElementById('approveForm').submit()" class="pc-btn pc-btn-approve">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button type="button" onclick="showRejectForm()" class="pc-btn pc-btn-reject">
                    <i class="fas fa-xmark"></i> Reject
                </button>
                <form method="POST" onsubmit="return confirm('Cancel this voucher?');" style="display:inline;">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="pc-btn pc-btn-cancel">Cancel voucher</button>
                </form>
            </div>
        <?php elseif ($can_manage && in_array($st, ['approved'], true)): ?>
            <div class="pc-actions">
                <form method="POST" onsubmit="return confirm('Cancel this voucher? Balance will be reversed.');">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="pc-btn pc-btn-cancel">Cancel voucher</button>
                </form>
            </div>
        <?php endif; ?>
</div>
</main>

    <script>
        function showRejectForm() {
            document.getElementById('rejectForm').style.display = 'block';
        }
        function hideRejectForm() {
            document.getElementById('rejectForm').style.display = 'none';
        }
        
        function validateReject(e) {
            e.preventDefault();
            const form = e.target;
            const reason = form.querySelector('textarea[name="reason"]').value.trim();
            
            if (!reason) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Reason',
                    text: 'Please enter a rejection reason',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
                return false;
            }
            
            form.submit();
        }
    </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
