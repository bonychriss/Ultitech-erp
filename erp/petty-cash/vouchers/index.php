<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$can_manage = pettyCashCanManage();

$moduleQs = array_filter([
    'module' => $_GET['module'] ?? 'petty_cash',
    'company_slug' => $_GET['company_slug'] ?? null,
], static fn($v) => $v !== null && $v !== '');

$buildUrl = static function (array $extra = []) use ($moduleQs): string {
    return 'index.php?' . http_build_query(array_merge($moduleQs, $extra));
};

$buildViewVoucherUrl = static function (int $id) use ($moduleQs): string {
    $q = $moduleQs;
    $q['id'] = $id;
    return '../view-voucher.php?' . http_build_query($q);
};

$error = '';
$flash_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $voucher_id = (int) ($_POST['voucher_id'] ?? 0);

    if (in_array($action, ['approve_voucher', 'reject_voucher', 'cancel_voucher'], true) && !$can_manage) {
        $error = 'Only Admin or Finance can approve, reject, or cancel vouchers.';
    } elseif ($action === 'approve_voucher') {
        $result = approvePettyCashVoucher($voucher_id, $user_id);
        if ($result === true) {
            header('Location: ' . $buildUrl(['success' => 'approved']));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to approve voucher.';
    } elseif ($action === 'reject_voucher') {
        $reason = trim($_POST['reason'] ?? '');
        $result = rejectPettyCashVoucher($voucher_id, $user_id, $reason);
        if ($result === true) {
            header('Location: ' . $buildUrl(['success' => 'rejected']));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to reject voucher.';
    } elseif ($action === 'cancel_voucher') {
        $result = cancelPettyCashVoucher($voucher_id);
        if ($result === true) {
            header('Location: ' . $buildUrl(['success' => 'cancelled']));
            exit;
        }
        $error = is_string($result) ? $result : 'Failed to cancel voucher.';
    }
}

if (isset($_GET['success'])) {
    $messages = [
        'approved' => 'Voucher approved successfully.',
        'rejected' => 'Voucher rejected.',
        'cancelled' => 'Voucher cancelled.',
    ];
    $flash_success = $messages[$_GET['success']] ?? '';
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));
$custodian_id = isset($_GET['custodian_id']) && $_GET['custodian_id'] !== '' ? (int) $_GET['custodian_id'] : null;

if (!$can_manage) {
    $custodian_id = $user_id;
}

$buildFilterUrl = static function (array $overrides = []) use (
    $moduleQs,
    $search,
    $status,
    $category,
    $date_from,
    $date_to,
    $custodian_id
): string {
    $q = $moduleQs;
    if ($search !== '') {
        $q['search'] = $search;
    }
    if ($status !== '') {
        $q['status'] = $status;
    }
    if ($category !== '') {
        $q['category'] = $category;
    }
    if ($date_from !== '') {
        $q['date_from'] = $date_from;
    }
    if ($date_to !== '') {
        $q['date_to'] = $date_to;
    }
    if ($custodian_id) {
        $q['custodian_id'] = (int) $custodian_id;
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($q[$key]);
        } else {
            $q[$key] = $value;
        }
    }

    return 'index.php?' . http_build_query($q);
};

$filters = [];
if ($search !== '') {
    $filters['search'] = $search;
}
if ($status !== '') {
    $filters['status'] = $status;
} elseif ($search === '') {
    $filters['exclude_cancelled'] = true;
}
if ($category !== '') {
    $filters['category'] = $category;
}
if ($date_from !== '') {
    $filters['date_from'] = $date_from;
}
if ($date_to !== '') {
    $filters['date_to'] = $date_to;
}
if ($custodian_id) {
    $filters['custodian_id'] = $custodian_id;
}

$vouchers = getAllPettyCashVouchers($filters);
$total_amount = array_sum(array_map(static fn($v) => (float) $v['amount'], $vouchers));
$categories = getPettyCashCategories();

$custodians = [];
if ($can_manage) {
    try {
        $custodians = $pdo->query(
            "SELECT DISTINCT u.id, u.full_name
             FROM petty_cash_vouchers v
             JOIN users u ON v.custodian_id = u.id
             ORDER BY u.full_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $custodians = [];
    }
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

$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$dashboardUrl = '../index.php?' . http_build_query($moduleQs);
$createUrl = '../create-voucher.php?' . http_build_query($moduleQs);

$page_title = 'All Vouchers';
include __DIR__ . '/../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body.dashboard { font-family: 'Inter', sans-serif; }
    .pc-list { padding: 1.5rem 2rem 2rem; max-width: 1600px; margin: 0 auto; }
    .dash-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .dash-card-h { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
    .dash-card-h h1 { font-size: 1.35rem; font-weight: 700; color: #0f172a; margin: 0; }
    .dash-card-b { padding: 1.25rem; }
    .btn-outline { border: 1px solid #e2e8f0; padding: 0.55rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; color: #1e293b; display: inline-flex; align-items: center; gap: 0.5rem; background: #fff; text-decoration: none; }
    .btn-outline:hover { background: #f8fafc; color: #0f172a; }
    .btn-blue { padding: 0.55rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; align-items: end; }
    .filter-grid label { display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .filter-grid input, .filter-grid select { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; }
    .pc-table { width: 100%; font-size: 0.9rem; border-collapse: collapse; }
    .pc-table th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; padding: 0.75rem 0.85rem; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
    .pc-table td { padding: 0.8rem 0.85rem; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
    .pc-table tbody tr:hover { background: #f8fafc; }
    .badge-orange { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
    .badge-green { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; }
    .badge-red { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; }
    .badge-slate { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; }
    .action-link { font-size: 0.75rem; font-weight: 600; background: none; border: none; padding: 0; cursor: pointer; text-decoration: none; }
    .action-link:hover { text-decoration: underline; }
    .action-link.success { color: #15803d; }
    .action-link.danger { color: #dc2626; }
    .action-link.muted { color: #64748b; }
    .summary-pill { font-size: 0.8rem; color: #64748b; }
    .summary-pill strong { color: #0f172a; }
    .alert-pc { border-radius: 12px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
    .alert-pc.success { background: #f0fdf4; border: 1px solid #dcfce7; color: #15803d; }
    .alert-pc.error { background: #fef2f2; border: 1px solid #fee2e2; color: #b91c1c; }
    .pc-custodian-cell { display: flex; align-items: center; gap: 0.5rem; min-width: 0; }
    .pc-custodian-name { font-size: 0.9rem; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pc-name-av .approval-avatar { width: 32px; height: 32px; min-width: 32px; min-height: 32px; border-radius: 50%; flex-shrink: 0; position: relative; overflow: hidden; box-shadow: 0 0 0 2px #fff, 0 0 0 1px #e2e8f0; }
    .pc-name-av .approval-avatar img, .pc-name-av .approval-avatar .user-av-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .pc-name-av .user-av-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; border-radius: 50%; border: 1px solid transparent; }
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
    .pc-top-search {
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: center;
    }
    .pc-top-search-form {
        display: flex;
        align-items: stretch;
        gap: 0;
        width: 100%;
        max-width: 420px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .pc-top-search-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 1rem;
        color: #94a3b8;
        background: #f8fafc;
        border-right: 1px solid #e2e8f0;
        flex-shrink: 0;
    }
    .pc-top-search-input {
        flex: 1;
        min-width: 0;
        border: none;
        padding: 0.85rem 1rem;
        font-size: 0.9375rem;
        color: #0f172a;
        background: transparent;
        outline: none;
    }
    .pc-top-search-input::placeholder { color: #94a3b8; }
    .pc-top-search-btn {
        flex-shrink: 0;
        border: none;
        border-left: 1px solid #e2e8f0;
        color: #fff;
        padding: 0 1.25rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s;
    }
    .pc-top-search-clear {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.85rem;
        border: none;
        border-left: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #64748b;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
    }
    .pc-top-search-clear:hover { background: #f1f5f9; color: #0f172a; }
    .pc-search-hint {
        margin: -0.5rem 0 1rem;
        font-size: 0.8125rem;
        color: #64748b;
        text-align: center;
    }
    .pc-search-hint a { font-weight: 600; text-decoration: none; }
    .pc-search-hint a:hover { text-decoration: underline; }
    @media (max-width: 992px) {
        .pc-list { padding: 1rem; }
        .pc-top-search,
        .pc-top-search-form { max-width: 100%; }
    }
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
function confirmCancel(isApproved) {
    let text = 'Cancel this voucher?';
    if (isApproved) text += ' This will reverse the balance impact.';
    return confirm(text);
}
</script>

<main class="main-content">
<div class="pc-list">
    <div class="pc-top-search">
        <form method="GET" class="pc-top-search-form" id="pc-top-search-form" role="search">
            <?php foreach ($moduleQs as $k => $v): ?>
                <input type="hidden" name="<?= $esc($k) ?>" value="<?= $esc($v) ?>">
            <?php endforeach; ?>
            <?php if ($status !== ''): ?>
                <input type="hidden" name="status" value="<?= $esc($status) ?>">
            <?php endif; ?>
            <?php if ($category !== ''): ?>
                <input type="hidden" name="category" value="<?= $esc($category) ?>">
            <?php endif; ?>
            <?php if ($custodian_id): ?>
                <input type="hidden" name="custodian_id" value="<?= (int) $custodian_id ?>">
            <?php endif; ?>
            <?php if ($date_from !== ''): ?>
                <input type="hidden" name="date_from" value="<?= $esc($date_from) ?>">
            <?php endif; ?>
            <?php if ($date_to !== ''): ?>
                <input type="hidden" name="date_to" value="<?= $esc($date_to) ?>">
            <?php endif; ?>
            <span class="pc-top-search-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
            <input
                type="search"
                name="search"
                class="pc-top-search-input"
                value="<?= $esc($search) ?>"
                placeholder="Search voucher #, category, description, custodian…"
                autocomplete="off"
                autofocus
            >
            <button type="submit" class="pc-top-search-btn">Search</button>
            <?php if ($search !== ''): ?>
                <a href="<?= $esc($buildFilterUrl(['search' => ''])) ?>" class="pc-top-search-clear" title="Clear search" aria-label="Clear search">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($search !== ''): ?>
        <p class="pc-search-hint">
            <?= count($vouchers) ?> result<?= count($vouchers) === 1 ? '' : 's' ?> for &ldquo;<?= $esc($search) ?>&rdquo;.
            <a href="<?= $esc($buildFilterUrl(['search' => ''])) ?>">Clear search</a>
        </p>
    <?php endif; ?>

    <div class="dash-card mb-5">
        <div class="dash-card-h">
            <div>
                <h1>All Vouchers</h1>
                <p class="text-sm text-slate-500 m-0 mt-1"><?= count($vouchers) ?> record<?= count($vouchers) === 1 ? '' : 's' ?> &middot; Total TZS <?= number_format($total_amount, 2) ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?= $esc($dashboardUrl) ?>" class="btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <a href="<?= $esc($createUrl) ?>" class="btn-blue"><i class="fas fa-plus"></i> New Voucher</a>
            </div>
        </div>

        <?php if ($flash_success !== ''): ?>
            <div class="dash-card-b pt-0"><div class="alert-pc success"><?= $esc($flash_success) ?></div></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="dash-card-b pt-0"><div class="alert-pc error"><?= $esc($error) ?></div></div>
        <?php endif; ?>

        <div class="dash-card-b border-t border-slate-100">
            <form method="GET" class="filter-grid">
                <?php foreach ($moduleQs as $k => $v): ?>
                    <input type="hidden" name="<?= $esc($k) ?>" value="<?= $esc($v) ?>">
                <?php endforeach; ?>
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="search" value="<?= $esc($search) ?>">
                <?php endif; ?>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">Active (excl. cancelled)</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label>Category</label>
                    <select name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $esc($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $esc($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($can_manage && !empty($custodians)): ?>
                <div>
                    <label>Custodian</label>
                    <select name="custodian_id">
                        <option value="">All custodians</option>
                        <?php foreach ($custodians as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $custodian_id === (int) $c['id'] ? 'selected' : '' ?>><?= $esc($c['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label>From</label>
                    <input type="date" name="date_from" value="<?= $esc($date_from) ?>">
                </div>
                <div>
                    <label>To</label>
                    <input type="date" name="date_to" value="<?= $esc($date_to) ?>">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-blue" style="flex:1;justify-content:center;">Filter</button>
                    <a href="<?= $esc($buildUrl()) ?>" class="btn-outline" style="align-items:center;">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="dash-card">
        <div class="dash-card-b p-0 overflow-x-auto">
            <table class="pc-table">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Date</th>
                        <?php if ($can_manage): ?><th>Custodian</th><?php endif; ?>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="text-right">Amount (TZS)</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                    <tr>
                        <td colspan="<?= $can_manage ? 8 : 7 ?>" class="text-center text-slate-400 py-12">
                            <?php if ($search !== ''): ?>
                                No vouchers found for &ldquo;<?= $esc($search) ?>&rdquo;.
                            <?php else: ?>
                                No vouchers match your filters.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($vouchers as $voucher): ?>
                        <?php $st = strtolower((string) $voucher['status']); ?>
                    <tr>
                        <td class="font-semibold text-slate-800 whitespace-nowrap"><?= $esc($voucher['voucher_number']) ?></td>
                        <td class="text-slate-500 whitespace-nowrap"><?= date('d M Y', strtotime($voucher['date'])) ?></td>
                        <?php if ($can_manage): ?>
                        <td><?= pettyCashRenderCustodianCell($voucher, 32) ?></td>
                        <?php endif; ?>
                        <td class="text-slate-600"><?= $esc($voucher['category']) ?></td>
                        <td class="text-slate-500 max-w-xs truncate" title="<?= $esc($voucher['description']) ?>"><?= $esc($voucher['description']) ?></td>
                        <td class="text-right font-bold text-slate-800 whitespace-nowrap"><?= number_format((float) $voucher['amount'], 2) ?></td>
                        <td><span class="<?= $statusBadge($st) ?>"><?= $esc(ucfirst($st)) ?></span></td>
                        <td class="text-right whitespace-nowrap">
                            <?php
                            $viewUrl = $buildViewVoucherUrl((int) $voucher['id']);
                            $reject_form_id = 'reject-v-' . (int) $voucher['id'];
                            include __DIR__ . '/../includes/voucher-row-actions.php';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>

<script>
(function () {
    var form = document.getElementById('pc-top-search-form');
    if (!form) return;
    var input = form.querySelector('.pc-top-search-input');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var val = input.value.trim();
        timer = setTimeout(function () {
            if (val.length === 0 || val.length >= 2) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }, 450);
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
