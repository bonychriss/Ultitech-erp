<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

if (!pettyCashCanManage()) {
    header('Location: index.php?module=petty_cash');
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

global $pdo;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $result = createPettyCashCategory($name);
    if (is_int($result)) {
        header('Location: index.php?module=petty_cash&msg=created');
        exit;
    }
    $errors[] = is_string($result) ? $result : 'Failed to create category.';
}

$listUrl = 'index.php?module=petty_cash';
$nextCode = pettyCashNextCategoryCode($pdo);
$formValues = ['name' => trim((string) ($_POST['name'] ?? ''))];
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$page_title = 'New Petty Cash Category';
include __DIR__ . '/../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; }
    .editor-shell { max-width: 640px; margin: 0 auto; padding: 2rem; }
    .form-input { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .form-input-readonly { background: #f8fafc; font-family: monospace; font-weight: 700; color: #7c3aed; border-style: dashed; }
    .btn-save { background: #7c3aed; color: #fff; padding: 12px 32px; border-radius: 12px; border: none; font-weight: 600; }
</style>

<div class="editor-shell">
    <div class="flex justify-between items-center mb-6 pb-3 border-b">
        <h1 class="text-xl font-semibold text-slate-800">New Petty Cash Category</h1>
        <a href="<?= $esc($listUrl) ?>" class="text-sm text-slate-500 hover:text-slate-700"><i class="fas fa-arrow-left"></i> Back to Categories</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= $esc($err) ?></div>
    <?php endforeach; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <div class="mb-4">
            <label class="block text-sm font-medium mb-2">Category Name <span class="text-red-500">*</span></label>
            <input type="text" name="name" required class="form-input" placeholder="e.g. Office Supplies, Travel" value="<?= $esc($formValues['name']) ?>">
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium mb-2">Category Code</label>
            <input type="text" readonly class="form-input form-input-readonly" value="<?= $esc($nextCode) ?>">
            <p class="text-xs text-slate-400 mt-2">Auto-generated petty cash category code.</p>
        </div>

        <div class="flex gap-3">
            <a href="<?= $esc($listUrl) ?>" class="px-6 py-3 rounded-xl border border-purple-200 text-purple-700">Cancel</a>
            <button type="submit" class="btn-save">Save Category</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
