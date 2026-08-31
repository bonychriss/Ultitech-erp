<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financial_account_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            account_type VARCHAR(30) NOT NULL,
            parent_id INT NULL,
            description TEXT NULL,
            reporting_group VARCHAR(120) NOT NULL,
            financial_statement VARCHAR(80) NOT NULL,
            display_order INT NOT NULL DEFAULT 10,
            is_header ENUM('Yes','No') NOT NULL DEFAULT 'No',
            allow_child ENUM('Yes','No') NOT NULL DEFAULT 'Yes',
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_parent_id (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // Keep page usable even if migration fails.
}

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
$createUrl = 'category_create.php' . $moduleQs;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['form_action'] ?? '') === 'toggle_status') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $targetStatus = strtolower((string) ($_POST['target_status'] ?? '')) === 'inactive' ? 'Inactive' : 'Active';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($categoryId > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE financial_account_categories SET status = ?, updated_by = ? WHERE id = ?');
            $stmt->execute([$targetStatus, $userId > 0 ? $userId : null, $categoryId]);
            $_SESSION['success'] = 'Category status updated successfully.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update status.';
        }
    }
    header('Location: account_categories.php' . $moduleQs);
    exit;
}

$allCategories = [];
try {
    $allCategories = $pdo->query('
        SELECT id, code, name, account_type, reporting_group, status
        FROM financial_account_categories
        ORDER BY display_order ASC, name ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $allCategories = [];
}

$page_title = 'Account Categories';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formAction = 'account_categories.php' . $moduleQs;
$sessionError = trim((string) ($_SESSION['error'] ?? ''));
$sessionSuccess = trim((string) ($_SESSION['success'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
if ($sessionSuccess !== '') {
    unset($_SESSION['success']);
}

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
    .pill-asset { background: #dbeafe; color: #1d4ed8; }
    .pill-liability { background: #f3e8ff; color: #7e22ce; }
    .pill-equity { background: #fef3c7; color: #b45309; }
    .pill-revenue { background: #dcfce7; color: #15803d; }
    .pill-expense { background: #ffedd5; color: #c2410c; }
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
                <h1 class="text-xl font-semibold text-slate-800">All Account Categories</h1>
                <p class="text-sm text-slate-500 mt-1"><?= number_format(count($allCategories)) ?> categor<?= count($allCategories) === 1 ? 'y' : 'ies' ?></p>
            </div>
            <a href="<?= $esc($createUrl) ?>" class="btn-add">
                <i class="fas fa-plus text-xs"></i> Add Category
            </a>
        </div>

        <?php if ($sessionError !== ''): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            <?= $esc($sessionError) ?>
        </div>
        <?php endif; ?>
        <?php if ($sessionSuccess !== ''): ?>
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700">
            <?= $esc($sessionSuccess) ?>
        </div>
        <?php endif; ?>

        <div class="cat-list-head">
            <h2 class="cat-list-title">Categories (<?= number_format(count($allCategories)) ?>)</h2>
            <input id="catSearch" class="cat-search" type="search" placeholder="Search account categories...">
        </div>
        <div class="cat-table-card">
            <table class="cat-table" id="categoryTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Category Name</th>
                        <th>Account Type</th>
                        <th>Reporting Group</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($allCategories === []): ?>
                    <tr><td colspan="6" class="text-slate-500">No categories yet. <a href="<?= $esc($createUrl) ?>">Add one</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($allCategories as $row): ?>
                    <?php
                    $rowId = (int) ($row['id'] ?? 0);
                    $type = strtolower((string) ($row['account_type'] ?? ''));
                    $pillClass = $type === 'asset' ? 'pill-asset' : ($type === 'liability' ? 'pill-liability' : ($type === 'equity' ? 'pill-equity' : ($type === 'revenue' ? 'pill-revenue' : 'pill-expense')));
                    $isActive = strtolower((string) ($row['status'] ?? '')) === 'active';
                    $linkParams = [];
                    if (!empty($_GET['module'])) {
                        $linkParams['module'] = (string) $_GET['module'];
                    }
                    if (!empty($_GET['company_slug'])) {
                        $linkParams['company_slug'] = (string) $_GET['company_slug'];
                    }
                    $linkParams['id'] = $rowId;
                    $rowLinkQs = '?' . http_build_query($linkParams);
                    ?>
                    <tr>
                        <td><?= $esc($row['code'] ?? '') ?></td>
                        <td><strong><?= $esc($row['name'] ?? '') ?></strong></td>
                        <td><span class="cat-pill <?= $pillClass ?>"><?= $esc($row['account_type'] ?? '') ?></span></td>
                        <td><?= $esc($row['reporting_group'] ?? '') ?></td>
                        <td><span class="cat-pill <?= $isActive ? 'pill-active' : 'pill-inactive' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <div class="dropdown">
                                <button class="cat-action-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="category_view.php<?= $esc($rowLinkQs) ?>"><i class="fas fa-eye text-primary me-2"></i> View</a></li>
                                    <li><a class="dropdown-item" href="category_edit.php<?= $esc($rowLinkQs) ?>"><i class="fas fa-pen-to-square text-info me-2"></i> Edit</a></li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="toggleCategoryStatus(<?= $rowId ?>, '<?= $isActive ? 'Inactive' : 'Active' ?>'); return false;">
                                            <i class="fas <?= $isActive ? 'fa-ban text-warning' : 'fa-circle-check text-success' ?> me-2"></i>
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form id="statusToggleForm" method="post" action="<?= $esc($formAction) ?>" class="d-none">
    <input type="hidden" name="form_action" value="toggle_status">
    <input type="hidden" name="category_id" id="toggleCategoryId" value="0">
    <input type="hidden" name="target_status" id="toggleTargetStatus" value="Inactive">
</form>

<script>
(function () {
    const catSearch = document.getElementById('catSearch');
    const table = document.getElementById('categoryTable');
    if (catSearch && table) {
        catSearch.addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            table.querySelectorAll('tbody tr').forEach((tr) => {
                tr.style.display = q === '' || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
})();

function toggleCategoryStatus(categoryId, status) {
    if (!categoryId) return;
    const msg = status === 'Inactive' ? 'Deactivate this category?' : 'Activate this category?';
    if (!confirm(msg)) return;
    document.getElementById('toggleCategoryId').value = String(categoryId);
    document.getElementById('toggleTargetStatus').value = status;
    document.getElementById('statusToggleForm').submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
