<?php
/**
 * Payment voucher view  Actions dropdown.
 * Expects: $voucher_id, $voucher, $statusLower, $pendingCount, $userPendingApprovals
 * Optional: $vvModuleQs, $vvBackUrl, $vvEditHref, $vvReturnParams, $vvShowEdit, $canMarkPaid, $canPost
 */
$vvModuleQs = $vvModuleQs ?? '';
$vvReturnParams = $vvReturnParams ?? '';
$vvBackUrl = $vvBackUrl ?? ((isAdmin() ? '../admin/dashboard.php' : 'dashboard.php') . $vvModuleQs);
$vvEditHref = $vvEditHref ?? ('edit-voucher.php?id=' . (int) $voucher_id . $vvModuleQs);
$vvShowEdit = isset($vvShowEdit)
    ? (bool) $vvShowEdit
    : (
        (canEditVoucher($voucher_id, $_SESSION['user_id']) && in_array(strtolower((string) ($voucher['status'] ?? '')), ['pending', 'confirming'], true))
        || canLimitedEditApprovedVoucher($voucher_id, $_SESSION['user_id'])
    );
$canMarkPaid = !empty($canMarkPaid);
$canPost = !empty($canPost);
$userPendingApprovals = $userPendingApprovals ?? [];
$pendingCount = isset($pendingCount) ? (int) $pendingCount : 0;

if (!defined('VV_ACTIONS_STYLES_PRINTED')) {
    require dirname(__DIR__, 2) . '/includes/voucher-view-actions-styles.php';
}
?>
<div class="vv-header-actions no-print">
    <div class="vv-actions-dropdown">
        <button type="button" class="vv-actions-btn dropdown-btn-actions" onclick="var m=this.closest('.vv-actions-dropdown').querySelector('.vv-actions-menu'); if(m){m.classList.toggle('show-dropdown');}" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-ellipsis-v" aria-hidden="true"></i> Actions
        </button>
        <div class="vv-actions-menu">
            <a href="#" id="downloadBtn" class="dropdown-item">
                <i class="fas fa-download" aria-hidden="true"></i> Download PDF
            </a>

            <button type="button" class="dropdown-item" onclick="printVoucher(); document.querySelectorAll('.vv-actions-menu.show-dropdown').forEach(function(m){m.classList.remove('show-dropdown');});">
                <i class="fas fa-print" aria-hidden="true"></i> Print
            </button>

            <a href="<?= htmlspecialchars($vvBackUrl) ?>" class="dropdown-item">
                <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
            </a>

            <?php if ($vvShowEdit): ?>
                <a href="<?= htmlspecialchars($vvEditHref) ?>" class="dropdown-item">
                    <i class="fas fa-edit" aria-hidden="true"></i> Edit
                </a>
            <?php endif; ?>

            <?php if ($canMarkPaid): ?>
                <button type="button" onclick="openMarkPaidModal()" class="dropdown-item dropdown-item--success">
                    <i class="fas fa-check-circle" aria-hidden="true"></i> Mark Paid
                </button>
            <?php endif; ?>

            <?php if ($canPost): ?>
                <form method="POST" action="view-voucher.php?id=<?= (int) $voucher_id ?><?= htmlspecialchars($vvReturnParams) ?>" class="vv-actions-form" onsubmit="return confirm('Finalize (post) this voucher? This locks further changes for non-admin users.');">
                    <input type="hidden" name="mark_posted" value="1">
                    <button type="submit" class="dropdown-item">
                        <i class="fas fa-lock" aria-hidden="true"></i> Mark Posted
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!empty($userPendingApprovals)): ?>
                <?php
                    $roles = array_map(static function ($ua) {
                        return htmlspecialchars($ua['role']);
                    }, $userPendingApprovals);
                    $rolesStr = implode(', ', $roles);
                    $mainApproval = $userPendingApprovals[0];
                ?>
                <button type="button" class="dropdown-item dropdown-item--primary btn-approve-dynamic"
                    data-approval-id="<?= (int) $mainApproval['id'] ?>"
                    data-role="<?= htmlspecialchars($rolesStr) ?>"
                    data-approver-name="<?= htmlspecialchars($mainApproval['approver_name']) ?>"
                    onclick="openApproveModal(this)">
                    <i class="fas fa-thumbs-up" aria-hidden="true"></i> Approve <?= count($userPendingApprovals) > 1 ? 'All Roles' : '' ?>
                </button>
            <?php endif; ?>

            <?php if (isAdmin() && in_array($statusLower, [STATUS_PENDING, STATUS_CONFIRMING], true)): ?>
                <?php if ($statusLower !== 'confirming'): ?>
                <button type="button" class="dropdown-item dropdown-item--success" onclick="showAdminApprovalModal('approved')">
                    <i class="fas fa-check-double" aria-hidden="true"></i> Final Approve
                </button>
                <?php endif; ?>
                <button type="button" class="dropdown-item dropdown-item--danger" onclick="showAdminApprovalModal('rejected')">
                    <i class="fas fa-times-circle" aria-hidden="true"></i> Reject
                </button>
            <?php endif; ?>

            <?php if ($pendingCount > 0): ?>
                <div class="dropdown-item dropdown-item--muted dropdown-item--pending">
                    <i class="fas fa-clock" aria-hidden="true"></i> <?= (int) $pendingCount ?> Pending
                </div>
            <?php endif; ?>

            <?php if (strtolower($statusLower) === 'pending'):
                $notifyTarget = getVoucherNotificationTarget($voucher, $_SESSION['full_name'] ?? '');
                if ($notifyTarget && !empty($notifyTarget['link'])): ?>
                <a href="<?= htmlspecialchars($notifyTarget['link']) ?>" target="_blank" rel="noopener" class="dropdown-item dropdown-item--success">
                    <i class="fab fa-whatsapp" aria-hidden="true"></i> Notify <?= htmlspecialchars($notifyTarget['role']) ?>
                </a>
            <?php endif; endif; ?>

            <?php
                $groupLink = getWhatsAppGroupLink();
                if ($groupLink):
                    $shareMsg = 'Hello, Payment Voucher ' . ($voucher['voucher_no'] ?? '???') . ' has been generated for ' . ($voucher['payee_name'] ?? 'N/A') . ' and is ready for review.';
                    $shareUrl = 'https://api.whatsapp.com/send?text=' . urlencode($shareMsg);
            ?>
                <a href="<?= htmlspecialchars($shareUrl) ?>" target="_blank" rel="noopener" class="dropdown-item dropdown-item--success">
                    <i class="fab fa-whatsapp" aria-hidden="true"></i> Send to Group
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
