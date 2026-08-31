<?php
/**
 * Three-dot actions menu for petty cash replenishment table rows.
 *
 * @var array  $rep               Row with id, status
 * @var bool   $can_manage        Whether user can approve/reject/cancel
 * @var string $reject_form_id    Hidden reject form element id
 */
if (!isset($rep) || !is_array($rep)) {
    return;
}

$rid = (int) ($rep['id'] ?? 0);
$st = strtolower((string) ($rep['status'] ?? ''));
$can_manage = !empty($can_manage);
$reject_form_id = (string) ($reject_form_id ?? ('reject-rep-' . $rid));

$approve_confirm_url = 'confirm-approve.php?' . http_build_query(array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
    'rep_id' => $rid,
], static fn($v) => $v !== null && $v !== ''));

$showApprove = $can_manage && $st === 'pending';
$showReject = $showApprove;
$showCancel = $can_manage && in_array($st, ['pending', 'approved'], true);
$cancelApproved = $st === 'approved';

if (!$showApprove && !$showCancel) {
    echo '<span class="text-slate-300">-</span>';
    return;
}

if (empty($GLOBALS['_pc_row_menu_assets'])) {
    $GLOBALS['_pc_row_menu_assets'] = true;
    ?>
    <style>
        .pc-row-menu { position: relative; display: inline-block; }
        .pc-row-menu-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .pc-row-menu-btn:hover,
        .pc-row-menu.is-open .pc-row-menu-btn {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .pc-row-menu-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            min-width: 168px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
            padding: 6px;
            z-index: 50;
        }
        .pc-row-menu-panel[hidden] { display: none !important; }
        .pc-row-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 9px 12px;
            border: none;
            border-radius: 7px;
            background: transparent;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #334155;
            text-decoration: none;
            cursor: pointer;
            text-align: left;
        }
        .pc-row-menu-item:hover { background: #f8fafc; }
        .pc-row-menu-item i { width: 16px; text-align: center; font-size: 0.8rem; }
        .pc-row-menu-item.success { color: #15803d; }
        .pc-row-menu-item.danger { color: #dc2626; }
        .pc-row-menu-item.muted { color: #64748b; }
        .pc-row-menu-divider {
            height: 1px;
            background: #f1f5f9;
            margin: 4px 6px;
        }
    </style>
    <script>
    function pcToggleRowMenu(btn) {
        const wrap = btn.closest('[data-pc-row-menu]');
        const panel = wrap ? wrap.querySelector('.pc-row-menu-panel') : null;
        if (!panel) return;
        const willOpen = panel.hidden;
        document.querySelectorAll('[data-pc-row-menu]').forEach(function (m) {
            m.classList.remove('is-open');
            const p = m.querySelector('.pc-row-menu-panel');
            if (p) p.hidden = true;
        });
        if (willOpen) {
            panel.hidden = false;
            wrap.classList.add('is-open');
        }
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-pc-row-menu]')) return;
        document.querySelectorAll('[data-pc-row-menu]').forEach(function (m) {
            m.classList.remove('is-open');
            const p = m.querySelector('.pc-row-menu-panel');
            if (p) p.hidden = true;
        });
    });
    </script>
    <?php
}
?>
<div class="pc-row-menu" data-pc-row-menu>
    <button type="button" class="pc-row-menu-btn" aria-label="Top-up actions" aria-haspopup="true" onclick="event.stopPropagation(); pcToggleRowMenu(this);">
        <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
    </button>
    <div class="pc-row-menu-panel" hidden role="menu">
        <?php if ($showApprove): ?>
            <a href="<?= htmlspecialchars($approve_confirm_url) ?>" class="pc-row-menu-item success" role="menuitem">
                <i class="fas fa-check"></i> Approve
            </a>
            <button type="button" class="pc-row-menu-item danger" role="menuitem"
                    onclick="rejectWithReason(<?= json_encode($reject_form_id) ?>)">
                <i class="fas fa-xmark"></i> Reject
            </button>
        <?php endif; ?>
        <?php if ($showCancel): ?>
            <?php if ($showApprove): ?><div class="pc-row-menu-divider"></div><?php endif; ?>
            <form method="POST" class="m-0" onsubmit="return confirmCancel(<?= $cancelApproved ? 'true' : 'false' ?>);">
                <input type="hidden" name="action" value="cancel_replenishment">
                <input type="hidden" name="rep_id" value="<?= $rid ?>">
                <button type="submit" class="pc-row-menu-item muted" role="menuitem">
                    <i class="fas fa-ban"></i> Cancel
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($showReject): ?>
<form method="POST" class="d-none" id="<?= htmlspecialchars($reject_form_id) ?>">
    <input type="hidden" name="action" value="reject_replenishment">
    <input type="hidden" name="rep_id" value="<?= $rid ?>">
</form>
<?php endif; ?>
