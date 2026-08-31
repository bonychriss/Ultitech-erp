<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

balances_ensure_account_types_schema($pdo);

$balancesQs = static function (array $extra = []): string {
    $qs = $extra;
    if (!empty($_GET['module'])) {
        $qs['module'] = (string) $_GET['module'];
    }
    if (!empty($_GET['company_slug'])) {
        $qs['company_slug'] = (string) $_GET['company_slug'];
    }
    return $qs === [] ? '' : ('?' . http_build_query($qs));
};
$moduleQs = $balancesQs();
$createUrl = 'account_type_create.php' . $moduleQs;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['form_action'] ?? '') === 'toggle') {
    $id = (int) ($_POST['type_id'] ?? 0);
    if ($id > 0) {
        try {
            $st = $pdo->prepare('SELECT status FROM financial_account_types WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $cur = (string) ($st->fetchColumn() ?: '');
            $next = $cur === 'Active' ? 'Inactive' : 'Active';
            $pdo->prepare('UPDATE financial_account_types SET status = ? WHERE id = ?')->execute([$next, $id]);
            $_SESSION['success'] = 'Account type updated.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update account type.';
        }
    }
    header('Location: account_types.php' . $moduleQs);
    exit;
}

$allTypes = [];
try {
    $allTypes = $pdo->query('
        SELECT id, slug, label, code_range_min, code_range_max, status, display_order
        FROM financial_account_types
        ORDER BY display_order ASC, label ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $allTypes = balances_fetch_account_types($pdo, false);
}

$page_title = 'Account Types';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formAction = 'account_types.php' . $moduleQs;
$sessionError = trim((string) ($_SESSION['error'] ?? ''));
$sessionSuccess = trim((string) ($_SESSION['success'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
if ($sessionSuccess !== '') {
    unset($_SESSION['success']);
}

$accountTypeLottieSuccess = !empty($_SESSION['bal_lottie_success']);

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .employee-header { display: none !important; }
    .main-content-wrapper { padding: 2rem; }
    .page-shell { padding-left: 4rem; }
    .editor-shell { max-width: 1140px; margin: 0 auto; }
    .editor-topbar {
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
    }
    .btn-add {
        background: #7c3aed; color: #fff; padding: 10px 18px; border-radius: 10px;
        font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex;
        align-items: center; gap: 8px; box-shadow: 0 2px 8px rgba(124, 58, 237, 0.18);
    }
    .btn-add:hover { background: #6d28d9; color: #fff; }
    .cat-list-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .cat-list-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
    .cat-search { width: 260px; max-width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
    .cat-table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
    .cat-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cat-table th, .cat-table td { border-bottom: 1px solid #f1f5f9; padding: 12px 14px; vertical-align: middle; }
    .cat-table th { background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; }
    .cat-pill { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .pill-active { background: #dcfce7; color: #15803d; }
    .pill-inactive { background: #f1f5f9; color: #475569; }
    .cat-action-btn {
        width: 32px; height: 32px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
        color: #64748b; display: inline-flex; align-items: center; justify-content: center;
    }
    .cat-action-btn:hover { color: #7c3aed; border-color: #d8b4fe; }
    @media (max-width: 992px) {
        .main-content-wrapper { padding: 1rem !important; }
        .page-shell { padding-left: 0; }
        .editor-topbar { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">All Account Types</h1>
                <p class="text-sm text-slate-500 mt-1"><?= number_format(count($allTypes)) ?> type(s) configured</p>
            </div>
            <a href="<?= $esc($createUrl) ?>" class="btn-add">
                <i class="fas fa-plus text-xs"></i> Add Account Type
            </a>
        </div>

        <?php if ($sessionError !== ''): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            <?= $esc($sessionError) ?>
        </div>
        <?php endif; ?>
        <?php if ($sessionSuccess !== '' && !$accountTypeLottieSuccess): ?>
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700">
            <?= $esc($sessionSuccess) ?>
        </div>
        <?php endif; ?>

        <div class="cat-list-head">
            <h2 class="cat-list-title">Account Types (<?= number_format(count($allTypes)) ?>)</h2>
            <input id="typeSearch" class="cat-search" type="search" placeholder="Search account types...">
        </div>
        <div class="cat-table-card">
            <table class="cat-table" id="accountTypeTable">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Slug</th>
                        <th>Code Series</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($allTypes === []): ?>
                    <tr><td colspan="5" class="text-slate-500">No account types yet. <a href="<?= $esc($createUrl) ?>">Add one</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($allTypes as $row): ?>
                    <?php
                    $rowId = (int) ($row['id'] ?? 0);
                    $isActive = strtolower((string) ($row['status'] ?? 'active')) === 'active';
                    ?>
                    <tr>
                        <td><strong><?= $esc($row['label'] ?? '') ?></strong></td>
                        <td><code><?= $esc($row['slug'] ?? '') ?></code></td>
                        <td><?= $esc((string) ($row['code_range_min'] ?? '')) ?> - <?= $esc((string) ($row['code_range_max'] ?? '')) ?></td>
                        <td>
                            <span class="cat-pill <?= $isActive ? 'pill-active' : 'pill-inactive' ?>">
                                <?= $esc($row['status'] ?? 'Active') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($rowId > 0): ?>
                            <div class="dropdown">
                                <button class="cat-action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="toggleAccountType(<?= $rowId ?>, '<?= $isActive ? 'Inactive' : 'Active' ?>'); return false;">
                                            <i class="fas <?= $isActive ? 'fa-ban text-warning' : 'fa-circle-check text-success' ?> me-2"></i>
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <?php else: ?>
                            <span class="text-slate-400 text-xs">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form id="typeToggleForm" method="post" action="<?= $esc($formAction) ?>" class="d-none">
    <input type="hidden" name="form_action" value="toggle">
    <input type="hidden" name="type_id" id="toggleTypeId" value="0">
</form>

<script>
(function () {
    const typeSearch = document.getElementById('typeSearch');
    const table = document.getElementById('accountTypeTable');
    if (typeSearch && table) {
        typeSearch.addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            table.querySelectorAll('tbody tr').forEach((tr) => {
                tr.style.display = q === '' || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
})();

function toggleAccountType(typeId, nextStatus) {
    if (!typeId) return;
    const msg = nextStatus === 'Inactive' ? 'Deactivate this account type?' : 'Activate this account type?';
    if (!confirm(msg)) return;
    document.getElementById('toggleTypeId').value = String(typeId);
    document.getElementById('typeToggleForm').submit();
}
</script>

<?php
$bal_lottie_redirect = '';
$bal_lottie_okay_label = 'Close';
$pc_lottie_mobile_only = false;
include __DIR__ . '/includes/footer.php';
?>
