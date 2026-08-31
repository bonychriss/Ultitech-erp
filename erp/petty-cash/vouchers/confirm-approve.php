<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

if (!pettyCashCanManage()) {
    header('Location: index.php?' . http_build_query(['module' => $_GET['module'] ?? 'petty_cash']));
    exit;
}

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
], static fn($v) => $v !== null && $v !== '');

$voucher_id = (int) ($_GET['voucher_id'] ?? $_POST['voucher_id'] ?? 0);
$listUrl = 'index.php?' . http_build_query($moduleQs);

$buildListUrl = static function (array $extra = []) use ($moduleQs): string {
    return 'index.php?' . http_build_query(array_merge($moduleQs, $extra));
};

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_approve_voucher') {
    $voucher_id = (int) ($_POST['voucher_id'] ?? 0);
    $preview = getPettyCashVoucherApprovalPreview($voucher_id);
    if (!$preview) {
        $error = 'This voucher is no longer pending or cannot be approved.';
    } elseif (empty($preview['can_approve'])) {
        $error = (string) ($preview['insufficient_message'] ?? 'Insufficient petty cash balance.');
    } else {
        $result = approvePettyCashVoucher($voucher_id, $user_id);
        if ($result === true) {
            header('Location: ' . $buildListUrl(['success' => 'approved']));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to approve voucher.';
    }
}

$preview = $voucher_id > 0 ? getPettyCashVoucherApprovalPreview($voucher_id) : null;
$refNumber = (string) ($preview['voucher_number'] ?? ('VCH-' . $voucher_id));

$page_title = 'Confirm voucher approval';
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fmt = static fn($n): string => number_format((float) $n, 2);

$pc_lottie_form_id = 'pcConfirmApproveForm';
$pc_lottie_show_success = false;
$pc_lottie_redirect = '';
$pc_lottie_submit_message = 'Approving voucher...';
$pc_lottie_success_message = 'Voucher approved successfully!';
$pc_lottie_okay_label = 'View all vouchers';
$pc_lottie_view_label = 'View voucher';
$viewVoucherQs = array_merge($moduleQs, ['id' => $voucher_id]);
$pc_lottie_view_url = $voucher_id > 0 ? ('../view-voucher.php?' . http_build_query($viewVoucherQs)) : '';

include __DIR__ . '/../includes/header.php';
?>
<script>document.documentElement.classList.add('pc-confirm-approve-page');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    @keyframes pc-confirm-sheet-up {
        from { transform: translateY(100%); opacity: 0.9; }
        to { transform: translateY(0); opacity: 1; }
    }
    @keyframes pc-confirm-backdrop-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .pc-confirm-overlay {
        font-family: 'Inter', system-ui, sans-serif;
    }
    /* Mobile / tablet: full-viewport overlay + bottom sheet */
    @media (max-width: 1024px) {
        html.pc-confirm-approve-page,
        html.pc-confirm-approve-page body.pc-confirm-mobile-active {
            overflow: hidden !important;
            height: 100% !important;
        }
        html.pc-confirm-approve-page body.pc-confirm-mobile-active > .layout-main-wrapper {
            display: none !important;
        }
        #pc-confirm-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            min-height: 100dvh !important;
            height: 100dvh !important;
            z-index: 10055 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            justify-content: flex-end !important;
            box-sizing: border-box;
            background: transparent !important;
        }
        .pc-confirm-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            opacity: 0;
        }
        #pc-confirm-overlay.is-open .pc-confirm-backdrop {
            animation: pc-confirm-backdrop-in 0.28s ease-out forwards;
        }
        .pc-confirm-sheet {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 100%;
            max-height: min(92dvh, 100%);
            background: #fff;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -8px 32px rgba(15, 23, 42, 0.2);
            display: flex;
            flex-direction: column;
            transform: translateY(100%);
            padding-bottom: env(safe-area-inset-bottom, 0px);
            box-sizing: border-box;
            will-change: transform;
        }
        #pc-confirm-overlay.is-open .pc-confirm-sheet {
            animation: pc-confirm-sheet-up 0.4s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .pc-confirm-handle { display: block; }
        .pc-confirm-header--desktop { display: none !important; }
        .pc-confirm-header--mobile { display: flex !important; }
        .pc-confirm-field-label { display: none; }
        .pc-transfer-heading { display: none; }
        .pc-confirm-footer {
            padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid #f1f5f9;
        }
        .pc-confirm-actions { flex-direction: column-reverse; }
        .pc-confirm-actions .btn-confirm,
        .pc-confirm-actions .btn-back { width: 100%; flex: none; }
        .pc-transfer-summary { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .pc-transfer-card { border: none; border-radius: 0; padding: 0.85rem 1rem; }
        .pc-transfer-card + .pc-transfer-card { border-top: none; }
        .pc-transfer-card-icon { display: none; }
        .pc-transfer-connector { background: #faf5ff; padding: 0.35rem; }
        .pc-transfer-connector .pc-transfer-line { display: none; }
        .pc-transfer-arrow-btn {
            width: auto;
            height: auto;
            background: transparent;
            box-shadow: none;
            font-size: 0.875rem;
        }
        .pc-confirm-meta { text-align: center; }
        .pc-confirm-ref--mobile {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #7c3aed;
            margin-bottom: 0.5rem;
        }
        .pc-transfer-bal-grid {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-top: 0.35rem;
            font-size: 0.8125rem;
            color: #64748b;
        }
        .pc-transfer-bal-grid .pc-bal-label { display: inline; }
        .pc-transfer-bal-grid .pc-bal-value { display: inline; font-weight: 600; }
        .pc-transfer-bal-grid .pc-bal-value--after { color: #16a34a; }
        .pc-transfer-bal-grid .pc-bal-value--after.neg { color: #dc2626; }
        .pc-confirm-warn { display: block; }
        .pc-confirm-warn i { display: none; }
        .pc-confirm-footer--has-meta .pc-confirm-meta {
            text-align: center;
            margin: 0 0 0.65rem;
        }
        #pc-lottie-overlay { z-index: 10060 !important; }
    }
    /* Desktop: centered card in content area, no grey backdrop */
    @media (min-width: 1025px) {
        html.pc-confirm-approve-page body.pc-confirm-mobile-active > .layout-main-wrapper {
            display: flex !important;
        }
        html.pc-confirm-approve-page .layout-main-wrapper > .flex-grow-1 {
            display: flex !important;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100dvh - 72px);
            padding: 2rem 1.5rem 3rem;
            box-sizing: border-box;
            background: #fff;
        }
        #pc-confirm-overlay {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            background: transparent;
        }
        .pc-confirm-backdrop { display: none !important; }
        .pc-confirm-handle { display: none; }
        .pc-confirm-header--mobile { display: none !important; }
        .pc-confirm-header--desktop { display: flex !important; }
        .pc-confirm-sheet {
            width: 100%;
            max-width: 560px;
            margin-left: auto;
            margin-right: auto;
            flex-shrink: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            max-height: none;
            animation: none;
            transform: none;
        }
        .pc-confirm-body { padding: 0 1.5rem 1rem; }
        .pc-confirm-footer {
            padding: 1rem 1.5rem 1.5rem;
            border-top: 1px solid #f1f5f9;
            background: #fff;
            border-radius: 0 0 16px 16px;
        }
        .pc-confirm-actions {
            flex-direction: row;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .pc-confirm-actions .btn-confirm,
        .pc-confirm-actions .btn-back {
            width: auto;
            min-width: 120px;
            flex: none;
            padding: 0.6rem 1.25rem;
            min-height: 42px;
            font-size: 0.875rem;
        }
        .pc-confirm-actions .btn-confirm { order: 2; }
        .pc-confirm-actions .btn-back { order: 1; }
        .pc-confirm-meta { text-align: left; margin-top: 0; }
        .pc-confirm-ref--mobile { display: none; }
        .pc-transfer-heading {
            display: block;
            margin: 0 0 0.85rem;
            font-size: 0.9375rem;
            font-weight: 700;
            color: #0f172a;
        }
        .pc-transfer-summary { display: flex; flex-direction: column; gap: 0; margin-bottom: 1.25rem; }
        .pc-transfer-card {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 1rem 1.1rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
        }
        .pc-transfer-connector {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.15rem 0;
        }
        .pc-transfer-line {
            width: 2px;
            flex: 1;
            min-height: 10px;
            background: #e2e8f0;
        }
        .pc-transfer-arrow-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #7c3aed;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.35);
        }
        .pc-transfer-card-icon--source {
            background: #f3e8ff;
            color: #7c3aed;
        }
        .pc-transfer-card-icon--dest {
            background: #dcfce7;
            color: #16a34a;
        }
        .pc-transfer-bal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 1rem;
            margin-top: 0.5rem;
        }
        .pc-confirm-amount {
            font-size: 1.625rem;
        }
        .pc-confirm-warn {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
        }
        .pc-confirm-warn i {
            color: #d97706;
            font-size: 1.125rem;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
    }
    .pc-confirm-handle {
        width: 40px;
        height: 4px;
        margin: 0.5rem auto 0;
        border-radius: 999px;
        background: #cbd5e1;
        flex-shrink: 0;
    }
    .pc-confirm-header--mobile,
    .pc-confirm-header--desktop {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        flex-shrink: 0;
    }
    .pc-confirm-header--mobile {
        padding: 0.65rem 1rem 0.5rem;
    }
    .pc-confirm-header--desktop {
        padding: 1.25rem 1.5rem 1rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .pc-confirm-header--mobile h1,
    .pc-confirm-header--desktop h1 {
        margin: 0;
        font-size: 1.0625rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.3;
    }
    .pc-confirm-header--desktop h1 { font-size: 1.125rem; }
    .pc-confirm-header-left {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        min-width: 0;
    }
    .pc-confirm-header-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #f3e8ff;
        color: #7c3aed;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .pc-confirm-ref {
        font-size: 0.8125rem;
        font-weight: 600;
        color: #7c3aed;
        margin-top: 0.2rem;
    }
    .pc-confirm-close {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        color: #64748b;
        text-decoration: none;
        font-size: 1.125rem;
        flex-shrink: 0;
        transition: background 0.15s, color 0.15s;
    }
    .pc-confirm-close:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .pc-confirm-header--mobile .pc-confirm-close {
        font-size: 0.8125rem;
        width: auto;
        height: auto;
        font-weight: 600;
    }
    .pc-confirm-body {
        flex: 1;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: 0 1rem 0.75rem;
    }
    .pc-confirm-field {
        margin-bottom: 1.1rem;
    }
    .pc-confirm-field-label {
        display: block;
        font-size: 0.8125rem;
        font-weight: 500;
        color: #64748b;
        margin-bottom: 0.35rem;
    }
    .pc-confirm-amount {
        font-size: 1.75rem;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
        line-height: 1.2;
    }
    .pc-confirm-desc {
        font-size: 0.875rem;
        color: #334155;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.65rem 0.85rem;
        line-height: 1.45;
        margin: 0;
    }
    .pc-transfer-heading {
        font-size: 0.9375rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 0.85rem;
    }
    .pc-transfer-card-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .pc-transfer-card-icon--source {
        background: #f3e8ff;
        color: #7c3aed;
    }
    .pc-transfer-card-icon--dest {
        background: #dcfce7;
        color: #16a34a;
    }
    .pc-transfer-card-body { flex: 1; min-width: 0; }
    .pc-transfer-label {
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #94a3b8;
        margin-bottom: 0.2rem;
    }
    .pc-transfer-name {
        font-size: 0.9375rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 0;
    }
    .pc-transfer-bal-grid {
        font-size: 0.8125rem;
    }
    .pc-bal-item .pc-bal-label {
        display: block;
        color: #94a3b8;
        font-size: 0.75rem;
        margin-bottom: 0.15rem;
    }
    .pc-bal-item .pc-bal-value {
        display: block;
        color: #0f172a;
        font-weight: 600;
    }
    .pc-bal-item .pc-bal-value--after {
        color: #16a34a;
        font-weight: 700;
    }
    .pc-bal-item .pc-bal-value--after.neg { color: #dc2626; }
    .pc-transfer-connector {
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .pc-confirm-warn {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        border-radius: 10px;
        padding: 0.75rem 0.9rem;
        font-size: 0.8125rem;
        line-height: 1.5;
        margin-bottom: 1rem;
    }
    .pc-confirm-warn p { margin: 0; }
    .pc-confirm-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        border-radius: 10px;
        padding: 0.75rem 0.85rem;
        font-size: 0.8125rem;
        margin-bottom: 1rem;
    }
    .pc-confirm-footer {
        flex-shrink: 0;
        padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom, 0px));
        background: #fff;
    }
    .pc-confirm-actions {
        display: flex;
        gap: 0.65rem;
    }
    .pc-confirm-actions .btn-confirm {
        min-height: 48px;
        border: none;
        border-radius: 10px;
        background: #7c3aed;
        color: #fff;
        font-size: 0.9375rem;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        white-space: nowrap;
    }
    .pc-confirm-actions .btn-confirm:disabled {
        background: #cbd5e1;
        cursor: not-allowed;
        box-shadow: none;
    }
    .pc-confirm-actions .btn-back {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.6rem 1.25rem;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #334155;
        font-size: 0.9375rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }
    .pc-confirm-meta {
        font-size: 0.8125rem;
        color: #94a3b8;
        margin: 0 0 1rem;
        line-height: 1.4;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .pc-confirm-meta i { font-size: 0.75rem; }
    .pc-confirm-footer > .btn-back {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 0.75rem 1.25rem;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #334155;
        font-size: 0.9375rem;
        font-weight: 600;
        text-decoration: none;
    }
    @media (min-width: 1025px) {
        .pc-confirm-footer > .btn-back { width: auto; margin-left: auto; display: inline-flex; }
        .pc-confirm-footer--has-meta {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .pc-confirm-footer--has-meta .pc-confirm-meta {
            margin-bottom: 0;
            order: 1;
        }
        .pc-confirm-footer--has-meta .pc-confirm-actions {
            order: 2;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        #pc-confirm-overlay.is-open .pc-confirm-sheet,
        #pc-confirm-overlay.is-open .pc-confirm-backdrop { animation: none; }
        #pc-confirm-overlay.is-open .pc-confirm-sheet { transform: translateY(0); }
        #pc-confirm-overlay.is-open .pc-confirm-backdrop { opacity: 1; }
    }
</style>

<div id="pc-confirm-overlay" class="pc-confirm-overlay" role="dialog" aria-modal="true" aria-labelledby="pc-confirm-title">
    <a href="<?= $esc($listUrl) ?>" class="pc-confirm-backdrop" aria-label="Close"></a>
    <div class="pc-confirm-sheet">
        <span class="pc-confirm-handle" aria-hidden="true"></span>

        <div class="pc-confirm-header--mobile">
            <h1 id="pc-confirm-title">Confirm approval</h1>
            <a href="<?= $esc($listUrl) ?>" class="pc-confirm-close"><i class="fas fa-times"></i> Close</a>
        </div>

        <?php if ($preview): ?>
        <div class="pc-confirm-header--desktop">
            <div class="pc-confirm-header-left">
                <div class="pc-confirm-header-icon" aria-hidden="true">
                    <i class="fas fa-file-circle-check"></i>
                </div>
                <div>
                    <h1 id="pc-confirm-title-desktop">Confirm approval</h1>
                    <div class="pc-confirm-ref"><?= $esc($refNumber) ?></div>
                </div>
            </div>
            <a href="<?= $esc($listUrl) ?>" class="pc-confirm-close" aria-label="Close"><i class="fas fa-times"></i></a>
        </div>
        <?php else: ?>
        <div class="pc-confirm-header--desktop">
            <div class="pc-confirm-header-left">
                <div class="pc-confirm-header-icon" aria-hidden="true"><i class="fas fa-file-circle-check"></i></div>
                <div><h1>Confirm approval</h1></div>
            </div>
            <a href="<?= $esc($listUrl) ?>" class="pc-confirm-close" aria-label="Close"><i class="fas fa-times"></i></a>
        </div>
        <?php endif; ?>

        <div class="pc-confirm-body">
        <?php if (!$preview): ?>
            <p class="mb-0 text-slate-600">This voucher is not available for approval. It may already be processed.</p>
        <?php else: ?>

        <?php if ($error !== ''): ?>
            <div class="pc-confirm-error"><?= $esc($error) ?></div>
        <?php endif; ?>

            <div class="pc-confirm-ref pc-confirm-ref--mobile"><?= $esc($refNumber) ?></div>

            <div class="pc-confirm-field">
                <span class="pc-confirm-field-label">Amount</span>
                <p class="pc-confirm-amount">TZS <?= $fmt($preview['amount']) ?></p>
            </div>

            <div class="pc-confirm-field">
                <span class="pc-confirm-field-label">Category</span>
                <div class="pc-confirm-desc"><?= $esc($preview['category'] ?? '-') ?></div>
            </div>

            <?php if (!empty($preview['description'])): ?>
            <div class="pc-confirm-field">
                <span class="pc-confirm-field-label">Description</span>
                <div class="pc-confirm-desc"><?= nl2br($esc($preview['description'])) ?></div>
            </div>
            <?php endif; ?>

            <h2 class="pc-transfer-heading">Balance impact</h2>
            <div class="pc-transfer-summary">
                <div class="pc-transfer-card">
                    <div class="pc-transfer-card-icon pc-transfer-card-icon--source" aria-hidden="true">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="pc-transfer-card-body">
                        <div class="pc-transfer-label">Custodian petty cash</div>
                        <div class="pc-transfer-name"><?= $esc($preview['custodian_name'] ?? 'Custodian') ?></div>
                        <div class="pc-transfer-bal-grid">
                            <div class="pc-bal-item">
                                <span class="pc-bal-label">Current balance</span>
                                <span class="pc-bal-value">TZS <?= $fmt($preview['petty_balance']) ?></span>
                            </div>
                            <div class="pc-bal-item">
                                <span class="pc-bal-label">After approval</span>
                                <span class="pc-bal-value pc-bal-value--after<?= (float) $preview['petty_balance_after'] < 0 ? ' neg' : '' ?>">
                                    TZS <?= $fmt($preview['petty_balance_after']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pc-confirm-warn">
                <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                <p>Approving will deduct this amount from the custodian petty cash balance and mark the voucher as approved.</p>
            </div>

            <?php if (empty($preview['can_approve'])): ?>
                <div class="pc-confirm-error"><?= $esc($preview['insufficient_message']) ?></div>
            <?php endif; ?>

        <?php endif; ?>
        </div>

        <div class="pc-confirm-footer<?= $preview ? ' pc-confirm-footer--has-meta' : '' ?>">
            <?php if (!$preview): ?>
                <a href="<?= $esc($listUrl) ?>" class="btn-back">Return to list</a>
            <?php else: ?>
            <p class="pc-confirm-meta">
                <i class="fas fa-user" aria-hidden="true"></i>
                <span>Requested by <?= $esc($preview['created_by_name'] ?? $preview['custodian_name'] ?? '-') ?>
                on <?= $esc(date('d M Y, H:i', strtotime($preview['created_at']))) ?>.</span>
            </p>
            <form method="POST" class="pc-confirm-actions" id="pcConfirmApproveForm">
                <input type="hidden" name="action" value="confirm_approve_voucher">
                <input type="hidden" name="voucher_id" value="<?= (int) $preview['id'] ?>">
                <button
                    type="submit"
                    class="btn-confirm"
                    <?= empty($preview['can_approve']) ? 'disabled' : '' ?>
                >
                    Confirm &amp; approve voucher
                </button>
                <a href="<?= $esc($listUrl) ?>" class="btn-back">Cancel</a>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var MOBILE_MQ = window.matchMedia('(max-width: 1024px)');
    var overlay = null;
    var anchorParent = null;
    var anchorNext = null;

    function openSheet() {
        if (!overlay) return;
        overlay.classList.add('is-open');
    }

    function mountMobile() {
        document.documentElement.classList.add('pc-confirm-approve-page');
        document.body.classList.add('pc-confirm-mobile-active');
        if (overlay.parentElement !== document.body) {
            if (!anchorParent) {
                anchorParent = overlay.parentNode;
                anchorNext = overlay.nextSibling;
            }
            document.body.appendChild(overlay);
        }
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(openSheet);
        });
    }

    function unmountMobile() {
        document.body.classList.remove('pc-confirm-mobile-active');
        document.body.style.overflow = '';
        if (overlay) overlay.classList.remove('is-open');
        if (anchorParent && overlay.parentElement === document.body) {
            if (anchorNext) {
                anchorParent.insertBefore(overlay, anchorNext);
            } else {
                anchorParent.appendChild(overlay);
            }
        }
    }

    function syncLayout() {
        overlay = document.getElementById('pc-confirm-overlay');
        if (!overlay) return;
        if (MOBILE_MQ.matches) {
            mountMobile();
        } else {
            unmountMobile();
        }
    }

    function boot() {
        syncLayout();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    if (typeof MOBILE_MQ.addEventListener === 'function') {
        MOBILE_MQ.addEventListener('change', syncLayout);
    } else if (typeof MOBILE_MQ.addListener === 'function') {
        MOBILE_MQ.addListener(syncLayout);
    }
})();
</script>
<?php include __DIR__ . '/../includes/lottie-success-overlay.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
