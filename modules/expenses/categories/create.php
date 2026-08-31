<?php
require_once '../../../includes/functions.php';
require_once __DIR__ . '/../includes/category_helpers.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'expenses';
}
$_SESSION['active_module'] = 'expenses';

global $pdo;
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (empty($errors)) {
        try {
            $dup = $pdo->prepare("SELECT id FROM erp_accounts WHERE type = 'expense' AND LOWER(name) = LOWER(?) LIMIT 1");
            $dup->execute([$name]);
            if ($dup->fetchColumn()) {
                throw new Exception('A category with this name already exists.');
            }

            $code = expenses_next_category_code($pdo);

            $stmt = $pdo->prepare("INSERT INTO erp_accounts (code, name, type, status) VALUES (?, ?, 'expense', 'active')");
            $stmt->execute([$code, $name]);

            header('Location: index.php?module=expenses&msg=created');
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$backUrl = 'index.php?module=expenses';
$listUrl = 'index.php?module=expenses';
$nextCategoryCode = expenses_next_category_code($pdo);
$formValues = [
    'name' => trim((string) ($_POST['name'] ?? '')),
];
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Expense Category | Expenses Module</title>
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
        .form-input-readonly {
            background: #f8fafc;
            font-family: monospace;
            font-weight: 700;
            color: #2563eb;
            border-style: dashed;
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
<?php include '../../../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">New Expense Category</h1>
            </div>
            <a href="<?= $esc($listUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Categories
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                <?php foreach ($errors as $err): ?>
                    <div><?= $esc($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-row">
                <label class="form-label">Category Name <span>*</span></label>
                <div>
                    <input type="text" name="name" required class="form-input" placeholder="e.g. Office Supplies, Travel, Utilities" value="<?= $esc($formValues['name']) ?>">
                    <div class="help-text">This appears in expense forms and reports.</div>
                </div>
            </div>

            <div class="form-row">
                <label class="form-label">Category Code</label>
                <div>
                    <input type="text" readonly class="form-input form-input-readonly" value="<?= $esc($nextCategoryCode) ?>">
                    <div class="help-text">Generated automatically from the latest available category number.</div>
                </div>
            </div>

            <div class="flex justify-start gap-4 mb-20">
                <button type="button" onclick="location.href='<?= $esc($listUrl) ?>'" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-save">Save Category</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
