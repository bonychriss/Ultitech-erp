<?php
require_once 'includes/functions.php';
require_once 'includes/revenue_account_helpers.php';
requireLogin();

if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$module = isset($_GET['module']) ? trim((string) $_GET['module']) : 'revenue';
$moduleQs = '?module=' . rawurlencode($module);

revenue_ensure_account_schema($pdo);

$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (empty($errors)) {
        try {
            $created = revenue_create_gl_account($pdo, $name, null, $description !== '' ? $description : null);
            revenue_ensure_child_account($pdo, (int) $created['id'], 'Revenue');
            header('Location: revenue_categories.php' . $moduleQs . '&success=' . urlencode('Category created successfully.'));
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$nextCode = revenue_next_gl_code($pdo, null);
$formValues = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
];
$backUrl = 'revenue_categories.php' . $moduleQs;
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Revenue Sub Account | <?= $esc(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
        .main-content-wrapper { padding: 2rem; }
        .page-shell { padding-left: 4rem; }
        .editor-shell { max-width: 760px; margin: 0 auto; }
        .editor-topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
        }
        .form-row { display: grid; grid-template-columns: 180px 1fr; gap: 16px; margin-bottom: 24px; align-items: start; }
        .form-label { font-size: 14px; font-weight: 500; padding-top: 12px; }
        .form-label span { color: #ef4444; }
        .form-input {
            width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; outline: none; background: #fff;
        }
        .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .form-input-readonly {
            background: #f8fafc; font-family: monospace; font-weight: 700;
            color: #2563eb; border-style: dashed;
        }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; }
        .btn-save {
            background: #7c3aed; color: white; padding: 14px 48px; border-radius: 12px;
            font-weight: 600; border: none; cursor: pointer;
        }
        .btn-save:hover { background: #6d28d9; }
        .btn-cancel {
            border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff;
            padding: 12px 32px; border-radius: 12px; font-weight: 600; cursor: pointer;
        }
        @media (max-width: 992px) {
            .page-shell { padding-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .form-label { padding-top: 0; }
        }
    </style>
</head>
<body>
<?php require_once 'includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">New Revenue Sub Account</h1>
            </div>
            <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Sub Accounts
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                <?= $esc($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                <?php foreach ($errors as $err): ?>
                    <div><?= $esc($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="module" value="<?= $esc($module) ?>">

            <div class="form-row">
                <label class="form-label">Category Name <span>*</span></label>
                <div>
                    <input type="text" name="name" required class="form-input"
                           placeholder="e.g. Sales Revenue, Service Revenue"
                           value="<?= $esc($formValues['name']) ?>">
                    <div class="help-text">Top-level revenue grouping used on the revenue entry form.</div>
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Account Code</label>
                <div>
                    <input type="text" readonly class="form-input form-input-readonly" value="<?= $esc($nextCode) ?>">
                    <div class="help-text">Generated automatically from the chart of accounts.</div>
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Description</label>
                <div>
                    <textarea name="description" rows="3" class="form-input"
                              placeholder="Optional description for this category"><?= $esc($formValues['description']) ?></textarea>
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Default Account</label>
                <div>
                    <input type="text" readonly class="form-input form-input-readonly" value="Revenue">
                    <div class="help-text">A &quot;Revenue&quot; posting account is created automatically under this category.</div>
                </div>
            </div>

            <div class="flex justify-start gap-4 mb-20">
                <button type="button" onclick="location.href='<?= $esc($backUrl) ?>'" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-save">Save Category</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
