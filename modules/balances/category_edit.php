<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

$categoryId = (int) ($_GET['id'] ?? $_POST['category_id'] ?? 0);
if ($categoryId <= 0) {
    $_SESSION['error'] = 'Invalid category selected.';
    redirect('category_create.php');
}

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

$form = [
    'code' => '',
    'name' => '',
    'account_type' => 'Asset',
    'parent_id' => 0,
    'description' => '',
    'reporting_group' => 'Current Assets',
    'financial_statement' => 'Balance Sheet',
    'display_order' => 10,
    'is_header' => 'No',
    'allow_child' => 'Yes',
    'status' => 'Active',
    'notes' => '',
];

try {
    $stmt = $pdo->prepare("SELECT * FROM financial_account_categories WHERE id = ? LIMIT 1");
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $_SESSION['error'] = 'Category not found.';
        redirect('category_create.php');
    }
    $form['code'] = (string) ($row['code'] ?? '');
    $form['name'] = (string) ($row['name'] ?? '');
    $form['account_type'] = (string) ($row['account_type'] ?? 'Asset');
    $form['parent_id'] = (int) ($row['parent_id'] ?? 0);
    $form['description'] = (string) ($row['description'] ?? '');
    $form['reporting_group'] = (string) ($row['reporting_group'] ?? 'Current Assets');
    $form['financial_statement'] = (string) ($row['financial_statement'] ?? 'Balance Sheet');
    $form['display_order'] = (int) ($row['display_order'] ?? 10);
    $form['is_header'] = (string) ($row['is_header'] ?? 'No');
    $form['allow_child'] = (string) ($row['allow_child'] ?? 'Yes');
    $form['status'] = (string) ($row['status'] ?? 'Active');
    $form['notes'] = (string) ($row['notes'] ?? '');
} catch (Throwable $e) {
    $_SESSION['error'] = 'Could not load category.';
    redirect('category_create.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'save');
    if ($formAction === 'deactivate') {
        try {
            $stmt = $pdo->prepare("UPDATE financial_account_categories SET status = 'Inactive', updated_by = ? WHERE id = ?");
            $stmt->execute([(int) ($_SESSION['user_id'] ?? 0) ?: null, $categoryId]);
            $_SESSION['success'] = 'Category deactivated successfully.';
            redirect('category_edit.php?id=' . $categoryId);
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not deactivate category.';
        }
    }

    $form['code'] = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['account_type'] = trim((string) ($_POST['account_type'] ?? 'Asset'));
    $form['parent_id'] = (int) ($_POST['parent_id'] ?? 0);
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['reporting_group'] = trim((string) ($_POST['reporting_group'] ?? 'Current Assets'));
    $form['financial_statement'] = trim((string) ($_POST['financial_statement'] ?? 'Balance Sheet'));
    $form['display_order'] = max(0, (int) ($_POST['display_order'] ?? 10));
    $form['is_header'] = isset($_POST['is_header']) ? 'Yes' : 'No';
    $form['allow_child'] = isset($_POST['allow_child']) ? 'Yes' : 'No';
    $form['status'] = strtolower((string) ($_POST['status'] ?? 'active')) === 'inactive' ? 'Inactive' : 'Active';
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($form['code'] === '' || $form['name'] === '' || $form['reporting_group'] === '') {
        $_SESSION['error'] = 'Category code, name, and reporting group are required.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE financial_account_categories
                SET code = ?, name = ?, account_type = ?, parent_id = ?, description = ?, reporting_group = ?, financial_statement = ?, display_order = ?, is_header = ?, allow_child = ?, status = ?, notes = ?, updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $form['code'],
                $form['name'],
                $form['account_type'],
                $form['parent_id'] > 0 ? $form['parent_id'] : null,
                $form['description'],
                $form['reporting_group'],
                $form['financial_statement'],
                $form['display_order'],
                $form['is_header'],
                $form['allow_child'],
                $form['status'],
                $form['notes'],
                (int) ($_SESSION['user_id'] ?? 0) ?: null,
                $categoryId
            ]);
            $_SESSION['success'] = 'Account category updated successfully.';
            redirect('category_view.php?id=' . $categoryId);
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update category. Ensure the category code is unique.';
        }
    }
}

$parentCategories = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, code, name
        FROM financial_account_categories
        WHERE status = 'Active' AND id <> ?
        ORDER BY display_order ASC, name ASC
    ");
    $stmt->execute([$categoryId]);
    $parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $parentCategories = [];
}

$page_title = 'Edit Account Category';
include __DIR__ . '/includes/header.php';
?>
<style>
    .employee-header { display:none !important; }
    .main-content.ce-shell { margin-top:0 !important; padding:12px 0 18px !important; background:#f8fafc; font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif; color:#0f172a; }
    .ce-wrap { padding:0 12px; width:100%; max-width:none; margin:0; }
    .ce-top { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; margin-bottom:10px; display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap; }
    .ce-bc { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:13px; color:#64748b; margin-bottom:6px; }
    .ce-bc a { color:#2563eb; text-decoration:none; font-weight:700; }
    .ce-title { margin:0; font-size:34px; font-weight:800; color:#0f172a; line-height:1.1; }
    .ce-sub { margin:5px 0 0; font-size:14px; color:#64748b; }
    .ce-top-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .ce-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:10px; }
    .ce-sec-title { margin:0 0 8px; font-size:20px; font-weight:800; color:#1d4ed8; }
    .ce-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .ce-fg { margin-bottom:10px; }
    .ce-fg label { display:block; font-size:14px; font-weight:700; color:#64748b; margin-bottom:5px; }
    .ce-fg label .req { color:#ef4444; }
    .ce-inp, .ce-sel, .ce-ta { width:100%; border:1px solid #dbe2ea; border-radius:8px; height:40px; padding:0 12px; font-size:14px; color:#0f172a; background:#fff; }
    .ce-ta { min-height:86px; height:auto; padding:9px 12px; resize:vertical; line-height:1.45; }
    .ce-help { font-size:12px; color:#94a3b8; margin-top:5px; }
    .ce-checks { display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:4px; }
    .ce-checks label { font-size:14px; color:#0f172a; font-weight:600; display:flex; align-items:center; gap:6px; margin:0; }
    .ce-settings { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
    .ce-setting { border:1px solid #eef2f7; border-radius:8px; padding:10px; font-size:14px; }
    .ce-setting label { margin:0; display:flex; align-items:flex-start; gap:8px; font-weight:700; color:#0f172a; }
    .ce-setting small { display:block; margin-left:24px; font-size:12px; color:#64748b; margin-top:2px; }
    .ce-foot { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .ce-audit { border:1px solid #e5e7eb; border-radius:8px; padding:10px; display:flex; gap:8px; align-items:center; background:#f8fafc; }
    .ce-audit .ico { width:28px; height:28px; border-radius:50%; background:#dbeafe; color:#1d4ed8; display:flex; align-items:center; justify-content:center; font-size:13px; }
    .ce-audit .k { font-size:11px; color:#64748b; text-transform:uppercase; font-weight:700; }
    .ce-audit .v { font-size:14px; color:#0f172a; font-weight:700; }
    .ce-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
    .ce-btn { border:1px solid #dbe2ea; background:#fff; color:#0f172a; border-radius:8px; font-size:13px; font-weight:700; padding:9px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; cursor:pointer; }
    .ce-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
    .ce-btn.warn { border-color:#fecaca; color:#dc2626; background:#fff; }
    @media (max-width:960px) { .ce-grid, .ce-settings, .ce-foot { grid-template-columns:1fr; } .ce-title { font-size:30px; } }
</style>

<main class="main-content ce-shell">
    <div class="ce-wrap">
        <div class="ce-top">
            <div>
                <div class="ce-bc">
                    <a href="accounts.php">Chart of Accounts</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="category_create.php">Account Categories</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Edit Category</span>
                </div>
                <h1 class="ce-title">Edit Account Category</h1>
                <p class="ce-sub">Update the selected account category details.</p>
            </div>
            <div class="ce-top-actions">
                <button type="button" class="ce-btn"><i class="far fa-bookmark"></i> User Manual</button>
                <a class="ce-btn" href="category_view.php?id=<?php echo (int) $categoryId; ?>"><i class="fas fa-arrow-left"></i> Back</a>
                <button type="submit" form="ceEditForm" class="ce-btn primary"><i class="far fa-floppy-disk"></i> Save Changes</button>
                <button type="button" id="deactivateBtn" class="ce-btn warn"><i class="far fa-circle-xmark"></i> Deactivate</button>
            </div>
        </div>

        <div class="ce-card">
            <h2 class="ce-sec-title">Category Information</h2>
            <form id="ceEditForm" method="post" action="category_edit.php?id=<?php echo (int) $categoryId; ?>">
                <input type="hidden" name="form_action" value="save">
                <input type="hidden" name="category_id" value="<?php echo (int) $categoryId; ?>">
                <div class="ce-grid">
                    <div>
                        <div class="ce-fg">
                            <label for="code">Category Code <span class="req">*</span></label>
                            <input class="ce-inp" id="code" name="code" required value="<?php echo htmlspecialchars($form['code'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="ce-help">Short unique code for the account category.</div>
                        </div>
                        <div class="ce-fg">
                            <label for="name">Category Name <span class="req">*</span></label>
                            <input class="ce-inp" id="name" name="name" required value="<?php echo htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="ce-fg">
                            <label for="account_type">Account Type <span class="req">*</span></label>
                            <select class="ce-sel" id="account_type" name="account_type">
                                <?php foreach (['Asset','Liability','Equity','Revenue','Expense'] as $opt): ?>
                                    <option value="<?php echo $opt; ?>"<?php echo $form['account_type'] === $opt ? ' selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ce-fg">
                            <label for="reporting_group">Reporting Group <span class="req">*</span></label>
                            <select class="ce-sel" id="reporting_group" name="reporting_group">
                                <?php foreach ($reportingGroups as $group): ?>
                                    <option value="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $form['reporting_group'] === $group ? ' selected' : ''; ?>><?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ce-fg">
                            <label for="parent_id">Parent Category (optional)</label>
                            <select class="ce-sel" id="parent_id" name="parent_id">
                                <option value="0">None</option>
                                <?php foreach ($parentCategories as $pc): ?>
                                    <?php $pid = (int) ($pc['id'] ?? 0); ?>
                                    <option value="<?php echo $pid; ?>"<?php echo $form['parent_id'] === $pid ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars(((string) ($pc['code'] ?? '') . ' - ' . (string) ($pc['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="ce-help">Select the parent category (if any).</div>
                        </div>
                    </div>

                    <div>
                        <div class="ce-fg">
                            <label for="display_order">Display Order</label>
                            <input class="ce-inp" id="display_order" name="display_order" type="number" min="0" value="<?php echo (int) $form['display_order']; ?>">
                            <div class="ce-help">Order of appearance in accounts.</div>
                        </div>
                        <div class="ce-fg">
                            <label for="description">Description (optional)</label>
                            <textarea class="ce-ta" id="description" name="description"><?php echo htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <div class="ce-help">Enter description of this account category.</div>
                        </div>
                        <div class="ce-fg">
                            <label>Status <span class="req">*</span></label>
                            <div class="ce-checks">
                                <label><input type="radio" name="status" value="Active"<?php echo $form['status'] === 'Active' ? ' checked' : ''; ?>> Active</label>
                                <label><input type="radio" name="status" value="Inactive"<?php echo $form['status'] === 'Inactive' ? ' checked' : ''; ?>> Inactive</label>
                            </div>
                            <div class="ce-help">Current status of this account category.</div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="ce-card">
            <h2 class="ce-sec-title">Settings</h2>
            <div class="ce-settings">
                <div class="ce-setting">
                    <label><input type="checkbox" form="ceEditForm" name="allow_child" value="1"<?php echo $form['allow_child'] === 'Yes' ? ' checked' : ''; ?>> Allow child categories</label>
                    <small>Allow creating sub-categories under this category.</small>
                </div>
                <div class="ce-setting">
                    <label><input type="checkbox" form="ceEditForm" name="is_header" value="1"<?php echo $form['is_header'] === 'Yes' ? ' checked' : ''; ?>> Show in account creation</label>
                    <small>Make this category available in account creation forms.</small>
                </div>
                <div class="ce-setting">
                    <label><input type="checkbox" disabled> System default category</label>
                    <small>Mark as system default for auto-selection.</small>
                </div>
            </div>
        </div>

        <div class="ce-card">
            <h2 class="ce-sec-title">Notes</h2>
            <div class="ce-fg" style="margin-bottom:0;">
                <textarea class="ce-ta" form="ceEditForm" id="notes" name="notes"><?php echo htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="ce-help">Internal notes about this category (optional).</div>
            </div>
        </div>

        <div class="ce-foot">
            <div class="ce-audit">
                <div class="ico"><i class="far fa-user"></i></div>
                <div><div class="k">Last Modified By</div><div class="v"><?php echo htmlspecialchars((string) ($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'System Administrator'), ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
            <div class="ce-audit">
                <div class="ico"><i class="far fa-calendar"></i></div>
                <div><div class="k">Last Modified On</div><div class="v"><?php echo htmlspecialchars(date('d M Y h:i A', strtotime((string) ($row['updated_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
        </div>
    </div>
</main>

<form id="ceDeactivateForm" method="post" action="category_edit.php?id=<?php echo (int) $categoryId; ?>" class="d-none">
    <input type="hidden" name="form_action" value="deactivate">
    <input type="hidden" name="category_id" value="<?php echo (int) $categoryId; ?>">
</form>

<script>
document.getElementById('deactivateBtn')?.addEventListener('click', function () {
    Swal.fire({
        icon: 'warning',
        title: 'Deactivate category?',
        text: 'This category will become inactive and cannot be used for new setup selections.',
        showCancelButton: true,
        confirmButtonText: 'Yes, Deactivate',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626'
    }).then(function (res) {
        if (res.isConfirmed) {
            document.getElementById('ceDeactivateForm').submit();
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
