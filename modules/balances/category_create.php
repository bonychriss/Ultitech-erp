<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
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
$categoriesListUrl = 'account_categories.php' . $moduleQs;

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

function categoryCodeBase(string $name, string $reportingGroup, string $accountType): string
{
    $source = trim($name) !== '' ? $name : (trim($reportingGroup) !== '' ? $reportingGroup : $accountType);
    $source = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $source ?? '');
    $source = trim((string) $source);
    if ($source === '') {
        return 'CAT';
    }
    $parts = preg_split('/\s+/', strtoupper($source)) ?: [];
    $code = '';
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $code .= substr($p, 0, 1);
        if (strlen($code) >= 3) {
            break;
        }
    }
    if ($code === '') {
        $code = strtoupper(substr(preg_replace('/\s+/', '', $source), 0, 3));
    }
    return substr($code, 0, 3);
}

function nextCategoryCode(PDO $pdo, string $name, string $reportingGroup, string $accountType): string
{
    $base = categoryCodeBase($name, $reportingGroup, $accountType);
    try {
        $stmt = $pdo->prepare("SELECT code FROM financial_account_categories WHERE code LIKE ?");
        $stmt->execute([$base . '%']);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $codes = [];
    }

    if (empty($codes)) {
        return $base;
    }

    $used = [];
    foreach ($codes as $c) {
        $c = strtoupper((string) $c);
        if ($c === $base) {
            $used[1] = true;
            continue;
        }
        if (preg_match('/^' . preg_quote($base, '/') . '(\d{2,4})$/', $c, $m)) {
            $used[(int) $m[1]] = true;
        }
    }
    $n = 2;
    while (isset($used[$n])) {
        $n++;
    }
    return $base . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
}

if (($_GET['action'] ?? '') === 'next_category_code') {
    header('Content-Type: application/json; charset=UTF-8');
    $name = (string) ($_GET['name'] ?? '');
    $reportingGroup = (string) ($_GET['reporting_group'] ?? '');
    $accountType = (string) ($_GET['account_type'] ?? '');
    try {
        $code = nextCategoryCode($pdo, $name, $reportingGroup, $accountType);
        echo json_encode(['success' => true, 'code' => $code]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'code' => categoryCodeBase($name, $reportingGroup, $accountType)]);
    }
    exit;
}

$form = [
    'code' => trim((string) ($_POST['code'] ?? '')),
    'name' => trim((string) ($_POST['name'] ?? '')),
    'account_type' => trim((string) ($_POST['account_type'] ?? '')),
    'parent_id' => (int) ($_POST['parent_id'] ?? 0),
    'description' => trim((string) ($_POST['description'] ?? '')),
    'reporting_group' => trim((string) ($_POST['reporting_group'] ?? '')),
    'financial_statement' => trim((string) ($_POST['financial_statement'] ?? 'Balance Sheet')),
    'display_order' => isset($_POST['display_order']) && $_POST['display_order'] !== '' ? (int) $_POST['display_order'] : 0,
    'is_header' => isset($_POST['is_header']) ? 'Yes' : 'No',
    'allow_child' => isset($_POST['allow_child']) ? 'Yes' : 'No',
    'status' => trim((string) ($_POST['status'] ?? 'Active')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];

$reportingGroups = [
    'Current Assets',
    'Cash and Bank',
    'Accounts Receivable',
    'Inventory',
    'Non-Current Assets',
    'Current Liabilities',
    'Accounts Payable',
    'VAT Payable',
    'Equity',
    'Sales Revenue',
    'Other Income',
    'Cost of Goods Sold',
    'Operating Expenses',
    'Payroll Expenses',
    'Finance Costs',
    'Tax Expense',
];

// Optional action context
$editId = (int) ($_GET['edit'] ?? 0);
$viewId = (int) ($_GET['view'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'create');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $code = strtoupper(trim($form['code']));
    if ($code === '') {
        $code = nextCategoryCode($pdo, $form['name'], $form['reporting_group'], $form['account_type']);
    }
    $name = $form['name'];
    $accountType = $form['account_type'] !== '' ? $form['account_type'] : 'Asset';
    $parentId = $form['parent_id'] > 0 ? $form['parent_id'] : null;
    $description = $form['description'];
    $reportingGroup = $form['reporting_group'];
    $financialStatement = $form['financial_statement'];
    $displayOrder = max(0, $form['display_order']);
    $isHeader = $form['is_header'] === 'Yes' ? 'Yes' : 'No';
    $allowChild = $form['allow_child'] === 'Yes' ? 'Yes' : 'No';
    $status = $form['status'] === 'Inactive' ? 'Inactive' : 'Active';
    $notes = $form['notes'];
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($code === '' || $name === '' || $reportingGroup === '') {
        $_SESSION['error'] = 'Category code, name, and reporting group are required.';
    } else {
        try {
            if ($formAction === 'update' && $categoryId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE financial_account_categories
                    SET code = ?, name = ?, account_type = ?, parent_id = ?, description = ?, reporting_group = ?, financial_statement = ?, display_order = ?, is_header = ?, allow_child = ?, status = ?, notes = ?, updated_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $code,
                    $name,
                    $accountType,
                    $parentId,
                    $description,
                    $reportingGroup,
                    $financialStatement,
                    $displayOrder,
                    $isHeader,
                    $allowChild,
                    $status,
                    $notes,
                    $userId > 0 ? $userId : null,
                    $categoryId
                ]);
                $_SESSION['success'] = 'Account category updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO financial_account_categories
                    (code, name, account_type, parent_id, description, reporting_group, financial_statement, display_order, is_header, allow_child, status, notes, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code,
                    $name,
                    $accountType,
                    $parentId,
                    $description,
                    $reportingGroup,
                    $financialStatement,
                    $displayOrder,
                    $isHeader,
                    $allowChild,
                    $status,
                    $notes,
                    $userId > 0 ? $userId : null,
                    $userId > 0 ? $userId : null
                ]);
                $_SESSION['success'] = 'Account category created successfully.';
            }
            redirect($categoriesListUrl);
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not save category. Ensure the category code is unique.';
        }
    }
}

$parentCategories = [];
try {
    $parentCategories = $pdo->query("
        SELECT id, code, name
        FROM financial_account_categories
        WHERE status = 'Active'
        ORDER BY display_order ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $parentCategories = [];
}

if ($editId > 0 || $viewId > 0) {
    $targetId = $editId > 0 ? $editId : $viewId;
    try {
        $stmt = $pdo->prepare("SELECT * FROM financial_account_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $form['code'] = (string) ($row['code'] ?? $form['code']);
            $form['name'] = (string) ($row['name'] ?? $form['name']);
            $form['account_type'] = (string) ($row['account_type'] ?? $form['account_type']);
            $form['parent_id'] = (int) ($row['parent_id'] ?? 0);
            $form['description'] = (string) ($row['description'] ?? $form['description']);
            $form['reporting_group'] = (string) ($row['reporting_group'] ?? $form['reporting_group']);
            $form['financial_statement'] = (string) ($row['financial_statement'] ?? $form['financial_statement']);
            $form['display_order'] = (int) ($row['display_order'] ?? $form['display_order']);
            $form['is_header'] = (string) ($row['is_header'] ?? $form['is_header']);
            $form['allow_child'] = (string) ($row['allow_child'] ?? $form['allow_child']);
            $form['status'] = (string) ($row['status'] ?? $form['status']);
            $form['notes'] = (string) ($row['notes'] ?? $form['notes']);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$page_title = $editId > 0 ? 'Edit Account Category' : 'Add New Account Category';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formAction = 'category_create.php' . $moduleQs;
$resetUrl = $formAction;
$backUrl = $categoriesListUrl;
$sessionError = trim((string) ($_SESSION['error'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
$selectChevron = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";
include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    .editor-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 2rem; align-items: start; }
    .section-nav { position: sticky; top: 96px; align-self: start; }
    .section-nav ul { list-style: none; margin: 0; padding: 0; }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
        display: block; padding: 0.45rem 0.75rem; border-radius: 8px; color: #64748b;
        font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s ease;
    }
    .section-nav a:hover { background: #eff6ff; color: #2563eb; }
    .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .editor-main { min-width: 0; }
    .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .editor-section:last-of-type { margin-bottom: 1.5rem; }
    .section-header { margin-bottom: 1.25rem; }
    .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
    .form-row:last-child { margin-bottom: 0; }
    .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
    .form-label span { color: #ef4444; margin-left: 2px; }
    .form-input {
        width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
        font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
    }
    .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    .form-input-readonly {
        background: #f8fafc; font-family: monospace; font-weight: 700; color: #2563eb; border-style: dashed;
    }
    .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
    .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
    .btn-save {
        background: #7c3aed; color: white; padding: 14px 48px; border-radius: 12px; font-weight: 600;
        font-size: 15px; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
    }
    .btn-save:hover { background: #6d28d9; }
    .btn-cancel {
        border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s;
        cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;
    }
    .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
    .status-inline { display: flex; gap: 20px; align-items: center; padding-top: 8px; }
    .status-inline label { font-size: 14px; font-weight: 500; color: #334155; display: flex; align-items: center; gap: 6px; margin: 0; }
    .cat-preview-box { text-align: center; padding: 8px 0 4px; }
    .cat-prev-code { font-size: 28px; font-family: ui-monospace, monospace; color: #2563eb; font-weight: 700; }
    .cat-prev-name { font-size: 20px; font-weight: 600; color: #0f172a; margin-top: 4px; }
    .cat-prev-pill { display: inline-block; margin-top: 8px; border-radius: 999px; font-size: 11px; font-weight: 600; padding: 4px 12px; background: #dcfce7; color: #15803d; }
    .cat-prev-meta { font-size: 13px; color: #64748b; margin-top: 12px; line-height: 1.8; }
    .cat-prev-fallback { color: #94a3b8 !important; font-weight: 400 !important; }
    .cat-prev-pill.cat-prev-fallback { background: #f1f5f9 !important; }
    .cat-prev-code:not(.cat-prev-fallback) { color: #2563eb; font-weight: 700; }
    .cat-prev-name:not(.cat-prev-fallback) { color: #0f172a; font-weight: 600; }
    .cat-prev-pill:not(.cat-prev-fallback) { background: #dcfce7; color: #15803d; font-weight: 600; }
    .cat-list-section { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #e5e7eb; }
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
        .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
        .section-nav { position: static; }
        .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .section-nav li + li { margin-top: 0; }
        .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
        .form-label { padding-top: 0; }
        .btn-save { width: 100%; padding: 14px 24px; }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800"><?= $editId > 0 ? 'Edit Account Category' : 'Create New Account Category' ?></h1>
                <p class="text-sm text-slate-500 mt-1">Define categories for your chart of accounts</p>
            </div>
            <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Categories
            </a>
        </div>

        <?php if ($sessionError !== ''): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            <?= $esc($sessionError) ?>
        </div>
        <?php endif; ?>
        <form id="balancesCategoryForm" method="post" action="<?= $esc($formAction) ?>">
            <input type="hidden" name="form_action" value="<?= $editId > 0 ? 'update' : 'create' ?>">
            <input type="hidden" name="category_id" value="<?= $editId > 0 ? (int) $editId : 0 ?>">
            <input type="hidden" name="financial_statement" value="<?= $esc($form['financial_statement']) ?>">
            <input type="hidden" name="is_header" value="<?= $esc($form['is_header']) ?>">
            <input type="hidden" name="allow_child" value="<?= $esc($form['allow_child']) ?>">
            <input type="hidden" name="notes" value="<?= $esc($form['notes']) ?>">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#general-info" class="is-active">General</a></li>
                        <li><a href="#classification">Classification</a></li>
                        <li><a href="#details-status">Details</a></li>
                        <li><a href="#preview">Preview</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="general-info">
                        <div class="section-header">
                            <h2 class="section-title">General Information</h2>
                            <p class="section-subtitle">Category code, name, and display order.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Category Code <span>*</span></label>
                            <div>
                                <input class="form-input form-input-readonly<?= $form['code'] === '' ? ' form-input-empty' : '' ?>" id="catCode" name="code" required readonly
                                       value="<?= $esc($form['code']) ?>" placeholder="Auto-generated (e.g. CA02)">
                                <div class="help-text">Short unique code for the account category.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Category Name <span>*</span></label>
                            <div>
                                <input class="form-input" id="catName" name="name" required value="<?= $esc($form['name']) ?>" placeholder="e.g. Current Assets">
                                <div class="help-text">Full name of the account category.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Display Order</label>
                            <div>
                                <input class="form-input" type="number" min="0" name="display_order"
                                       value="<?= $form['display_order'] > 0 ? (int) $form['display_order'] : '' ?>" placeholder="e.g. 10">
                                <div class="help-text">Order of appearance in accounts.</div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="classification">
                        <div class="section-header">
                            <h2 class="section-title">Classification</h2>
                            <p class="section-subtitle">Account type, reporting group, and parent category.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Account Type <span>*</span></label>
                            <div>
                                <select class="form-input appearance-none pr-10<?= $form['account_type'] === '' ? ' is-placeholder' : '' ?>" id="catType" name="account_type" required style="<?= $esc($selectChevron) ?>">
                                    <option value="" disabled<?= $form['account_type'] === '' ? ' selected' : '' ?>>Select account type</option>
                                    <?php foreach (['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'] as $opt): ?>
                                        <option value="<?= $esc($opt) ?>"<?= $form['account_type'] === $opt ? ' selected' : '' ?>><?= $esc($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">Type of account for this category.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Reporting Group <span>*</span></label>
                            <div>
                                <select class="form-input appearance-none pr-10<?= $form['reporting_group'] === '' ? ' is-placeholder' : '' ?>" name="reporting_group" id="reporting_group" required style="<?= $esc($selectChevron) ?>">
                                    <option value="" disabled<?= $form['reporting_group'] === '' ? ' selected' : '' ?>>Select reporting group</option>
                                    <?php foreach ($reportingGroups as $rg): ?>
                                        <option value="<?= $esc($rg) ?>"<?= $form['reporting_group'] === $rg ? ' selected' : '' ?>><?= $esc($rg) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">Reporting group this category belongs to.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Parent Category</label>
                            <div>
                                <select class="form-input appearance-none pr-10<?= $form['parent_id'] === 0 ? ' is-placeholder' : '' ?>" id="catParent" name="parent_id" style="<?= $esc($selectChevron) ?>">
                                    <option value="0"<?= $form['parent_id'] === 0 ? ' selected' : '' ?>>Select parent category</option>
                                    <?php foreach ($parentCategories as $pc): ?>
                                        <?php $pid = (int) ($pc['id'] ?? 0); ?>
                                        <option value="<?= $pid ?>"<?= $form['parent_id'] === $pid ? ' selected' : '' ?>>
                                            <?= $esc(($pc['code'] ?? '') . ' - ' . ($pc['name'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="details-status">
                        <div class="section-header">
                            <h2 class="section-title">Details &amp; Status</h2>
                            <p class="section-subtitle">Description and active state.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Description</label>
                            <div>
                                <textarea class="form-input min-h-[100px]" name="description" placeholder="Brief description of this account category..."><?= $esc($form['description']) ?></textarea>
                                <div class="help-text">Brief description of this account category.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Status <span>*</span></label>
                            <div>
                                <div class="status-inline">
                                    <label><input type="radio" name="status" value="Active"<?= $form['status'] === 'Active' ? ' checked' : '' ?>> Active</label>
                                    <label><input type="radio" name="status" value="Inactive"<?= $form['status'] === 'Inactive' ? ' checked' : '' ?>> Inactive</label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="preview">
                        <div class="section-header">
                            <h2 class="section-title">Preview</h2>
                            <p class="section-subtitle">Live summary before you save.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Category Preview</label>
                            <div class="cat-preview-box" id="catPreviewBox">
                                <div class="cat-prev-code" id="previewCode">--</div>
                                <div class="cat-prev-name" id="previewName">Category Name</div>
                                <span class="cat-prev-pill" id="previewType">Account type</span>
                                <div class="cat-prev-meta">Parent: <strong id="previewParent">None</strong></div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-4 mb-8">
                        <a href="<?= $esc($resetUrl) ?>" class="btn-cancel px-8 py-3 rounded-xl font-bold">Reset</a>
                        <button type="submit" class="btn-save">Save Account Category</button>
                    </div>
                </div>
            </div>
        </form>

    </div>
</div>

<script>
(() => {
    const code = document.getElementById('catCode');
    const name = document.getElementById('catName');
    const type = document.getElementById('catType');
    const reportingGroup = document.getElementById('reporting_group');
    const parent = document.getElementById('catParent');
    const prevCode = document.getElementById('previewCode');
    const prevName = document.getElementById('previewName');
    const prevType = document.getElementById('previewType');
    const prevParent = document.getElementById('previewParent');
    function syncSelectPlaceholder(sel) {
        if (!sel) return;
        const val = sel.value;
        const isEmpty = val === '' || val === '0';
        sel.classList.toggle('is-placeholder', isEmpty);
    }

    function syncCodeEmptyState() {
        if (!code) return;
        const hasCode = code.value.trim() !== '';
        code.classList.toggle('form-input-empty', !hasCode);
    }

    function syncPreview() {
        const hasCode = code && code.value.trim() !== '';
        const hasName = name && name.value.trim() !== '';
        const hasType = type && type.value !== '';
        const hasParent = parent && parent.value !== '' && parent.value !== '0';

        if (prevCode) {
            prevCode.textContent = hasCode ? code.value.trim().toUpperCase() : '--';
            prevCode.classList.toggle('cat-prev-fallback', !hasCode);
        }
        if (prevName) {
            prevName.textContent = hasName ? name.value.trim() : 'Category Name';
            prevName.classList.toggle('cat-prev-fallback', !hasName);
        }
        if (prevType) {
            prevType.textContent = hasType ? type.value : 'Account type';
            prevType.classList.toggle('cat-prev-fallback', !hasType);
        }
        if (prevParent) {
            prevParent.textContent = hasParent ? parent.options[parent.selectedIndex].text : 'None';
            prevParent.classList.toggle('cat-prev-fallback', !hasParent);
        }
        syncCodeEmptyState();
        syncSelectPlaceholder(type);
        syncSelectPlaceholder(reportingGroup);
        syncSelectPlaceholder(parent);
    }

    [code, name, type, parent, reportingGroup].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', syncPreview);
        el.addEventListener('change', syncPreview);
    });

    async function refreshCode() {
        if (!code) return;
        const prev = code.value;
        const url = new URL('category_create.php', window.location.href);
        url.searchParams.set('action', 'next_category_code');
        url.searchParams.set('name', name ? name.value : '');
        url.searchParams.set('reporting_group', reportingGroup ? reportingGroup.value : '');
        url.searchParams.set('account_type', type ? type.value : '');
        try {
            const res = await fetch(url.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (data && data.success && data.code) {
                code.value = String(data.code).toUpperCase();
            } else if (!code.value) {
                code.value = prev;
            }
        } catch (e) {
            if (!code.value) code.value = prev;
        }
        syncPreview();
    }

    [name, type, reportingGroup].forEach((el) => {
        if (!el) return;
        el.addEventListener('change', refreshCode);
        el.addEventListener('input', () => {
            clearTimeout(el._ccTimer);
            el._ccTimer = setTimeout(refreshCode, 220);
        });
    });

    if (!code.value) {
        refreshCode();
    } else {
        syncCodeEmptyState();
    }
    syncPreview();

    document.querySelectorAll('.section-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            document.querySelectorAll('.section-nav a').forEach(function (a) { a.classList.remove('is-active'); });
            link.classList.add('is-active');
        });
    });

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
